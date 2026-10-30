import * as React from 'react'
import { CheckCircle2, Loader2, UserCheck } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Field } from '@/components/ui/form'
import { formatDate } from '@/lib/utils'

/**
 * The identity check for an online registrant.
 *
 * Everything on this record was typed in by the applicant themselves and has
 * never been checked against a document. Confirming it is the single action
 * that releases them into screening, so it is presented as a deliberate step
 * with a named officer against it rather than as one option in a status menu.
 *
 * Shown above the folder status because until the check is done, nothing else
 * on the page can be acted on.
 */
function IdentityCheckPanel({ applicant, onChanged }) {
  const { can } = useAuth()
  const toast = useToast()
  const [remarks, setRemarks] = React.useState('')
  const [submitting, setSubmitting] = React.useState(false)

  async function handleConfirm() {
    setSubmitting(true)
    try {
      await post(`/applicants/${applicant.id}/verify-identity`, {
        remarks: remarks || undefined,
      })
      toast.success(
        'Identity confirmed',
        `${applicant.full_name} has moved to initial screening.`
      )
      onChanged()
    } catch (error) {
      toast.error('Could not confirm identity', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card className="mb-5 border-warning/40 bg-warning/5">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <UserCheck className="h-4 w-4 text-warning" />
          Identity not yet confirmed
        </CardTitle>
        <CardDescription>
          This applicant registered online on {formatDate(applicant.self_registered_at)} and entered
          these details themselves. Nobody has seen them or checked an ID, so the record is held
          before screening.
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <div className="rounded-md border bg-card px-3 py-2.5 text-sm">
          <p className="mb-1.5 font-medium">Check against a valid ID before confirming</p>
          <ul className="space-y-0.5 text-muted-foreground">
            <li>
              Name: <span className="font-medium text-foreground">{applicant.full_name}</span>
            </li>
            <li>
              Date of birth:{' '}
              <span className="font-medium text-foreground">
                {formatDate(applicant.birth_date)}
              </span>
              {applicant.age ? ` (${applicant.age} years old)` : ''}
            </li>
            <li>
              Address:{' '}
              <span className="font-medium text-foreground">{applicant.present_address}</span>
            </li>
            <li>
              Mobile:{' '}
              <span className="font-medium text-foreground">
                {applicant.contact_number || '—'}
              </span>
            </li>
          </ul>
        </div>

        {can('applicants.change_status') ? (
          <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
            <Field
              label="Which ID was presented?"
              htmlFor="identity_remarks"
              className="flex-1"
              hint="Recorded in the audit trail against your name."
            >
              <Input
                value={remarks}
                onChange={(e) => setRemarks(e.target.value)}
                placeholder="e.g. PhilSys National ID"
              />
            </Field>
            <Button onClick={handleConfirm} disabled={submitting} className="shrink-0">
              {submitting ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <CheckCircle2 className="h-4 w-4" />
              )}
              Confirm identity
            </Button>
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">
            An HR officer needs to confirm this applicant&rsquo;s identity before screening can
            begin.
          </p>
        )}

        {/* If the applicant corrected a detail verbally, HR fixes it on the
            Profile tab first and confirms afterwards. */}
        <p className="text-xs text-muted-foreground">
          If any detail above is wrong, correct it on the Profile tab before confirming.
        </p>
      </CardContent>
    </Card>
  )
}

export default IdentityCheckPanel
