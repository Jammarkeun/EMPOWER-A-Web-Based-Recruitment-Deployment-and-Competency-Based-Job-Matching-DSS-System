import * as React from 'react'
import { Loader2, Lock, Save, ShieldCheck } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { patch } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Field } from '@/components/ui/form'
import { LoadingState, ErrorState } from '@/components/ui/states'
import { formatDate } from '@/lib/utils'

/**
 * The applicant's own record.
 *
 * Split into what they may change and what they may not, with the reason for the
 * split stated rather than implied. Name, date of birth, and address were
 * checked against documents at the office; letting them be edited afterwards
 * would let a verified record drift away from the paperwork supporting it. But
 * showing them read-only still earns its place — an applicant who can see that
 * their date of birth is wrong can say so at the counter, which is far cheaper
 * than finding out during a client's background check.
 *
 * Saving updates in place rather than reloading. The form is below the fold on a
 * phone, and a reload would send the reader back to the top of the page having
 * just pressed Save at the bottom of it.
 */
export default function PortalProfile() {
  const { data, loading, error, refetch, setData } = useApi('/portal/profile')
  const toast = useToast()

  if (loading) return <LoadingState label="Loading your profile…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Profile</h1>
        <p className="text-sm text-muted-foreground">
          Your details as they appear on your application.
        </p>
      </div>

      <IdentityCard identity={data.identity} reason={data.locked_fields_reason} />

      <ContactForm
        values={data.editable}
        onSaved={(saved) =>
          // Merged into what is already on screen instead of refetching, so the
          // page does not jump and the rest of the profile stays put.
          setData((current) => ({ ...current, editable: { ...current.editable, ...saved } }))
        }
        toast={toast}
      />

      <AccountCard account={data.account} />
    </div>
  )
}

function IdentityCard({ identity, reason }) {
  const rows = [
    ['Full name', identity.full_name],
    ['Reference number', identity.applicant_code],
    ['Date of birth', identity.birth_date ? formatDate(identity.birth_date) : null],
    ['Age', identity.age ? `${identity.age} years` : null],
    ['Sex', identity.sex ? identity.sex[0].toUpperCase() + identity.sex.slice(1) : null],
    ['Civil status', identity.civil_status],
    ['Present address', identity.present_address],
    [
      'Applying for',
      identity.position
        ? identity.position + (identity.position_company ? ` — ${identity.position_company}` : '')
        : null,
    ],
    ['Applied on', identity.applied_on ? formatDate(identity.applied_on) : null],
  ].filter(([, value]) => value)

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex items-start gap-3">
          <span
            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary text-base font-semibold text-primary-foreground"
            aria-hidden="true"
          >
            {identity.initials}
          </span>
          <div className="min-w-0">
            <CardTitle className="text-base">{identity.full_name}</CardTitle>
            <CardDescription>
              Reference number <span className="font-mono">{identity.applicant_code}</span>
            </CardDescription>
          </div>
        </div>
      </CardHeader>

      <CardContent className="space-y-4">
        <dl className="grid gap-3 sm:grid-cols-2">
          {rows.map(([label, value]) => (
            <div key={label}>
              <dt className="text-xs text-muted-foreground">{label}</dt>
              <dd className="text-sm font-medium">{value}</dd>
            </div>
          ))}
        </dl>

        {/* Without this the read-only fields look like an oversight, and the
            applicant's next move is a phone call asking how to change them. */}
        <p className="flex items-start gap-2 rounded-md border bg-muted/40 px-3 py-2.5 text-xs text-muted-foreground">
          <Lock className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          {reason}
        </p>
      </CardContent>
    </Card>
  )
}

/**
 * The part the applicant owns.
 *
 * Exactly the three fields the API accepts, no more. The button reports its own
 * state and refuses a second press while a save is running, because a double-tap
 * on a slow connection is the most ordinary way to send the same change twice.
 */
function ContactForm({ values, onSaved, toast }) {
  const [form, setForm] = React.useState({
    contact_number: values.contact_number ?? '',
    email: values.email ?? '',
    availability_date: values.availability_date ?? '',
  })
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(false)

  const dirty =
    form.contact_number !== (values.contact_number ?? '') ||
    form.email !== (values.email ?? '') ||
    form.availability_date !== (values.availability_date ?? '')

  function set(field, value) {
    setForm((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field]: undefined }))
  }

  async function handleSubmit(event) {
    event.preventDefault()

    if (saving) return

    setSaving(true)
    setErrors({})

    const payload = {
      contact_number: form.contact_number || null,
      email: form.email || null,
      availability_date: form.availability_date || null,
    }

    try {
      const response = await patch('/portal/profile', payload)
      onSaved(payload)
      toast.success('Saved', response.message ?? 'Your contact details have been updated.')
    } catch (err) {
      if (err.isValidation) {
        setErrors(err.errors)
        toast.error('Check the form', 'Some details need correcting.')
      } else {
        toast.error('Could not save your details', err.message)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Contact details</CardTitle>
        <CardDescription>
          Keep these current — this is how the office reaches you about work.
        </CardDescription>
      </CardHeader>

      <CardContent>
        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label="Mobile number"
              htmlFor="contact_number"
              error={errors.contact_number?.[0]}
              hint="The number we will call about a placement."
            >
              <Input
                id="contact_number"
                type="tel"
                value={form.contact_number}
                onChange={(e) => set('contact_number', e.target.value)}
                autoComplete="tel"
                placeholder="09XX XXX XXXX"
              />
            </Field>

            <Field label="Email address" htmlFor="email" error={errors.email?.[0]}>
              <Input
                id="email"
                type="email"
                value={form.email}
                onChange={(e) => set('email', e.target.value)}
                autoComplete="email"
              />
            </Field>
          </div>

          <Field
            label="Earliest date you can start"
            htmlFor="availability_date"
            error={errors.availability_date?.[0]}
            hint="Leave blank if you can start any time. This counts towards how well you match a request."
          >
            <Input
              id="availability_date"
              type="date"
              value={form.availability_date}
              onChange={(e) => set('availability_date', e.target.value)}
            />
          </Field>

          <div className="flex justify-end">
            <Button type="submit" disabled={saving || !dirty}>
              {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
              {saving ? 'Saving…' : 'Save changes'}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}

function AccountCard({ account }) {
  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <ShieldCheck className="h-4 w-4" />
          Account
        </CardTitle>
        <CardDescription>How you sign in to this portal.</CardDescription>
      </CardHeader>
      <CardContent>
        <dl className="grid gap-3 sm:grid-cols-2">
          <div>
            <dt className="text-xs text-muted-foreground">Sign-in email</dt>
            <dd className="text-sm font-medium">{account.email}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Account type</dt>
            <dd className="text-sm font-medium capitalize">{account.user_type}</dd>
          </div>
        </dl>
        <p className="mt-4 text-xs text-muted-foreground">
          To change your password or sign-in email, contact the CDE Manpower Services office.
        </p>
      </CardContent>
    </Card>
  )
}
