import * as React from 'react'
import { useNavigate, Navigate, Link } from 'react-router-dom'
import {
  Loader2,
  Eye,
  EyeOff,
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  MapPin,
  FileText,
  Info,
} from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import { getCached } from '@/lib/api'
import { Button } from '@/components/ui/button'
import AuthLayout from '@/components/AuthLayout'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Field } from '@/components/ui/form'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'

/**
 * Applicant self-registration.
 *
 * Deliberately two steps, and the checklist comes first. The agency's most
 * common support call is "what do I need to bring?", and someone who sees the
 * answer before they sign up arrives prepared — which is the entire point of
 * having this page at all. Putting the form first would bury it.
 *
 * The page is honest that an account is not an application. Registering online
 * reserves a reference number and shows what to bring; the application itself
 * begins when the person visits the office and an officer checks their
 * identity. Saying so here prevents the far worse discovery of finding out
 * after a month of waiting.
 */
/*
 * Aimed at a job seeker rather than at HR. Each point answers the question
 * someone weighing up whether to bother registering is actually asking.
 */
const POINTS = [
  {
    icon: FileText,
    title: 'Know what to bring',
    body: 'The full document checklist before you travel to the office.',
  },
  {
    icon: CheckCircle2,
    title: 'Keep your reference number',
    body: 'Quote it at the counter instead of starting again.',
  },
  {
    icon: MapPin,
    title: 'Follow your application',
    body: 'Check your status and outstanding documents any time.',
  },
]

export default function Register() {
  const { register, isAuthenticated, loading } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()

  const [step, setStep] = React.useState('requirements')
  const [checklist, setChecklist] = React.useState(null)
  const [done, setDone] = React.useState(null)

  /*
   * The positions on offer, read from the system rather than written here.
   *
   * `null` means still loading and `[]` means genuinely nothing on offer, and
   * the form has to tell those apart: an empty dropdown with no explanation
   * looks like a fault, and the two states need different words.
   */
  const [positions, setPositions] = React.useState(null)

  const [form, setForm] = React.useState({
    first_name: '',
    middle_name: '',
    last_name: '',
    sex: '',
    birth_date: '',
    contact_number: '',
    present_address: '',
    preferred_position_id: '',
    email: '',
    password: '',
    password_confirmation: '',
  })

  const [showPassword, setShowPassword] = React.useState(false)
  const [errors, setErrors] = React.useState({})
  const [formError, setFormError] = React.useState('')
  const [submitting, setSubmitting] = React.useState(false)

  React.useEffect(() => {
    getCached('/register/requirements')
      .then((response) => setChecklist(response.data))
      // The checklist is helpful, not essential. If it cannot be loaded the
      // person can still register, so this failure stays quiet.
      .catch(() => setChecklist({ primary: [], final: [], office: null }))

    getCached('/register/positions')
      .then((response) => setPositions(response.data.positions ?? []))
      // Falling back to an empty list is honest here rather than convenient: if
      // we cannot say what work is available, we should not invent any. The
      // field explains itself in that state, and the server accepts a blank
      // choice when nothing is on offer, so the applicant is never stuck.
      .catch(() => setPositions([]))
  }, [])

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Someone already signed in has no reason to be here — except immediately
  // after registering, when the success panel is the whole point.
  if (isAuthenticated && !done) {
    return <Navigate to="/portal" replace />
  }

  function set(field, value) {
    setForm((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field]: undefined }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setFormError('')
    setSubmitting(true)

    try {
      const result = await register({
        ...form,
        // Blank optional fields are sent as null rather than empty strings so
        // the record does not end up with meaningless values.
        middle_name: form.middle_name || null,
        sex: form.sex || null,
        // A select yields a string; the API wants the reference as a number.
        preferred_position_id: form.preferred_position_id
          ? Number(form.preferred_position_id)
          : null,
      })
      setDone(result)
      setStep('done')
      toast.success('Account created', `Your reference number is ${result.applicant_code}.`)
    } catch (error) {
      if (error.isValidation) {
        setErrors(error.errors)
        setFormError('Please check the highlighted fields.')
        toast.error('Check the form', 'Some details need correcting before we can continue.')
      } else {
        setFormError(error.message)
        // A duplicate registration is the common case here, and its message
        // tells the applicant to contact the office rather than try again.
        toast.error(error.isConflict ? 'You may already have applied' : 'Could not create your account', error.message)
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout
      wide
      points={POINTS}
      title={step === 'done' ? 'You are registered' : 'Apply for work'}
      subtitle={
        step === 'done'
          ? 'Keep your reference number — you will be asked for it at the office.'
          : 'Register online, then visit our office with your documents.'
      }
      footer={
        step !== 'done' ? (
          <p className="text-sm text-muted-foreground">
            Already registered?{' '}
            <Link to="/login" className="font-medium text-foreground underline underline-offset-4">
              Sign in
            </Link>
          </p>
        ) : null
      }
    >
      <>
          {step !== 'done' && <Steps current={step} />}

          {step === 'requirements' && (
            <RequirementsStep
              checklist={checklist}
              onContinue={() => {
                setStep('form')
                window.scrollTo({ top: 0, behavior: 'smooth' })
              }}
            />
          )}

          {step === 'form' && (
            <Card className="shadow-sm">
              <CardContent className="pt-5">
                <form onSubmit={handleSubmit} className="space-y-6" noValidate>
                  <section className="space-y-4">
                    <h2 className="text-sm font-semibold">Your details</h2>

                    <div className="grid gap-4 sm:grid-cols-3">
                      <Field label="First name" htmlFor="first_name" required error={errors.first_name?.[0]}>
                        <Input
                          value={form.first_name}
                          onChange={(e) => set('first_name', e.target.value)}
                          autoComplete="given-name"
                          autoFocus
                          required
                        />
                      </Field>
                      <Field label="Middle name" htmlFor="middle_name" error={errors.middle_name?.[0]}>
                        <Input
                          value={form.middle_name}
                          onChange={(e) => set('middle_name', e.target.value)}
                          autoComplete="additional-name"
                        />
                      </Field>
                      <Field label="Last name" htmlFor="last_name" required error={errors.last_name?.[0]}>
                        <Input
                          value={form.last_name}
                          onChange={(e) => set('last_name', e.target.value)}
                          autoComplete="family-name"
                          required
                        />
                      </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                      <Field
                        label="Date of birth"
                        htmlFor="birth_date"
                        required
                        error={errors.birth_date?.[0]}
                        hint="Must match your valid ID."
                      >
                        <Input
                          type="date"
                          value={form.birth_date}
                          onChange={(e) => set('birth_date', e.target.value)}
                          autoComplete="bday"
                          required
                        />
                      </Field>
                      <Field label="Sex" htmlFor="sex" error={errors.sex?.[0]}>
                        <select
                          id="sex"
                          value={form.sex}
                          onChange={(e) => set('sex', e.target.value)}
                          className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                          <option value="">Prefer not to say</option>
                          <option value="male">Male</option>
                          <option value="female">Female</option>
                        </select>
                      </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                      <Field
                        label="Mobile number"
                        htmlFor="contact_number"
                        required
                        error={errors.contact_number?.[0]}
                        hint="How the office will reach you."
                      >
                        <Input
                          type="tel"
                          value={form.contact_number}
                          onChange={(e) => set('contact_number', e.target.value)}
                          autoComplete="tel"
                          placeholder="09XX XXX XXXX"
                          required
                        />
                      </Field>
                      <PositionField
                        positions={positions}
                        value={form.preferred_position_id}
                        error={errors.preferred_position_id?.[0]}
                        onChange={(value) => set('preferred_position_id', value)}
                      />
                    </div>

                    <Field
                      label="Present address"
                      htmlFor="present_address"
                      required
                      error={errors.present_address?.[0]}
                      hint="Barangay, city or municipality, and province."
                    >
                      <Input
                        value={form.present_address}
                        onChange={(e) => set('present_address', e.target.value)}
                        autoComplete="street-address"
                        required
                      />
                    </Field>
                  </section>

                  <section className="space-y-4 border-t pt-5">
                    <h2 className="text-sm font-semibold">Your account</h2>

                    <Field
                      label="Email address"
                      htmlFor="email"
                      required
                      error={errors.email?.[0]}
                      hint="You will use this to sign in and check your application."
                    >
                      <Input
                        type="email"
                        value={form.email}
                        onChange={(e) => set('email', e.target.value)}
                        autoComplete="username"
                        required
                      />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                      <Field
                        label="Password"
                        htmlFor="password"
                        required
                        error={errors.password?.[0]}
                        hint="At least 8 characters."
                      >
                        <div className="relative">
                          <Input
                            type={showPassword ? 'text' : 'password'}
                            value={form.password}
                            onChange={(e) => set('password', e.target.value)}
                            autoComplete="new-password"
                            className="pr-10"
                            required
                          />
                          <button
                            type="button"
                            onClick={() => setShowPassword((visible) => !visible)}
                            className="absolute right-0 top-0 flex h-9 w-10 items-center justify-center rounded-r-md text-muted-foreground transition-colors hover:text-foreground"
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            aria-pressed={showPassword}
                            tabIndex={-1}
                          >
                            {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                          </button>
                        </div>
                      </Field>

                      <Field
                        label="Confirm password"
                        htmlFor="password_confirmation"
                        required
                        error={errors.password_confirmation?.[0]}
                      >
                        <Input
                          type={showPassword ? 'text' : 'password'}
                          value={form.password_confirmation}
                          onChange={(e) => set('password_confirmation', e.target.value)}
                          autoComplete="new-password"
                          required
                        />
                      </Field>
                    </div>
                  </section>

                  {/*
                    RA 10173 requires that consent be informed. The person is
                    told what the data is for and how long it is kept before
                    they submit, not in a policy nobody opens.
                  */}
                  <div className="flex gap-2.5 rounded-md border bg-muted/40 px-3 py-2.5 text-xs text-muted-foreground">
                    <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <p>
                      By registering you agree that CDE Manpower Services may process your personal
                      information to assess your application and match you to client vacancies, as
                      allowed under the Data Privacy Act of 2012 (RA 10173). Your records are kept
                      confidential and you may ask the office to correct or remove them.
                    </p>
                  </div>

                  {formError && (
                    <div
                      className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                      role="alert"
                    >
                      {formError}
                    </div>
                  )}

                  <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-between">
                    <Button type="button" variant="ghost" onClick={() => setStep('requirements')}>
                      <ArrowLeft className="h-3.5 w-3.5" />
                      Back to requirements
                    </Button>
                    <Button type="submit" disabled={submitting}>
                      {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
                      {submitting ? 'Creating your account…' : 'Create my account'}
                    </Button>
                  </div>
                </form>
              </CardContent>
            </Card>
          )}

          {step === 'done' && <SuccessStep result={done} office={checklist?.office} onGo={() => navigate('/portal')} />}
      </>
    </AuthLayout>
  )
}

/**
 * The position being applied for, chosen from what the agency actually places.
 *
 * This used to be a free-text box, which produced applications for jobs CDE does
 * not place and three spellings of the same role, none of which could be matched
 * against a client's request. The list comes from the API, so a role added for a
 * new client company appears here without anyone touching this file.
 *
 * The three states are told apart deliberately. Loading says so; an empty list
 * says why it is empty and that applying is still worthwhile; and a populated
 * list groups by the company hiring, because "Production Helper at Best Tiwi"
 * is a more useful thing to choose than "Production Helper".
 */
function PositionField({ positions, value, error, onChange }) {
  if (positions === null) {
    return (
      <Field label="Position you are applying for" htmlFor="preferred_position_id">
        <div className="flex h-9 items-center gap-2 rounded-md border border-input bg-muted/40 px-3 text-sm text-muted-foreground">
          <Loader2 className="h-3.5 w-3.5 animate-spin" />
          Loading available positions…
        </div>
      </Field>
    )
  }

  if (positions.length === 0) {
    return (
      <Field
        label="Position you are applying for"
        htmlFor="preferred_position_id"
        hint="You can still register. Our staff will match you to work as it comes in."
      >
        <div className="flex h-9 items-center rounded-md border border-dashed border-input px-3 text-sm text-muted-foreground">
          No available positions at this time
        </div>
      </Field>
    )
  }

  // Grouped by the company hiring so the choice carries its context. Positions
  // the agency holds on its own account have no company and are listed first,
  // under wording that explains what they are.
  const groups = new Map()
  for (const position of positions) {
    const key = position.company ?? ''
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(position)
  }

  const ordered = [...groups.entries()].sort(([a], [b]) => a.localeCompare(b))

  return (
    <Field
      label="Position you are applying for"
      htmlFor="preferred_position_id"
      required
      error={error}
      hint="Choose the closest match. Our staff can change this when you visit."
    >
      <Select
        id="preferred_position_id"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={!!error}
        required
      >
        <option value="">Select a position</option>
        {ordered.map(([company, items]) => (
          <optgroup key={company || 'agency'} label={company || 'General — matched to work as it arrives'}>
            {items.map((position) => (
              <option key={position.id} value={position.id}>
                {position.position_title}
                {position.is_hiring_now ? ' — hiring now' : ''}
              </option>
            ))}
          </optgroup>
        ))}
      </Select>
    </Field>
  )
}

function Steps({ current }) {
  const steps = [
    { key: 'requirements', label: 'What to bring' },
    { key: 'form', label: 'Your details' },
  ]

  return (
    <ol className="flex items-center justify-center gap-2 text-xs">
      {steps.map((step, index) => {
        const active = step.key === current
        const passed = steps.findIndex((s) => s.key === current) > index

        return (
          <li key={step.key} className="flex items-center gap-2">
            <span
              className={
                active || passed
                  ? 'flex items-center gap-1.5 font-medium text-foreground'
                  : 'flex items-center gap-1.5 text-muted-foreground'
              }
            >
              <span
                className={
                  'flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-semibold ' +
                  (active || passed
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-muted text-muted-foreground')
                }
              >
                {passed ? <CheckCircle2 className="h-3 w-3" /> : index + 1}
              </span>
              {step.label}
            </span>
            {index < steps.length - 1 && <span className="h-px w-6 bg-border" />}
          </li>
        )
      })}
    </ol>
  )
}

/**
 * The document checklist, shown before the form.
 *
 * Split the way the agency actually works: the primary documents are what to
 * bring on the first visit, the final ones are only needed once a client
 * accepts you. Presenting all of them at once makes the list look impossible
 * and turns people away who would have qualified.
 */
function RequirementsStep({ checklist, onContinue }) {
  if (!checklist) {
    return (
      <Card>
        <CardContent className="flex items-center justify-center py-12">
          <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="space-y-4">
      <Card className="shadow-sm">
        <CardContent className="space-y-5 pt-5">
          <div className="flex items-start gap-3 rounded-md border border-primary/30 bg-primary/5 px-3 py-2.5">
            <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
            <div className="space-y-0.5 text-sm">
              <p className="font-medium">Registering online does not complete your application</p>
              <p className="text-muted-foreground">
                You still need to visit {checklist.office?.name ?? 'our office'}
                {checklist.office?.address ? ` in ${checklist.office.address}` : ''} in person to
                submit your documents. Registering first means you arrive with everything you need
                and keep your reference number.
              </p>
            </div>
          </div>

          <DocumentGroup
            title="Bring these on your first visit"
            description="Original and photocopy where available."
            items={checklist.primary}
            emphasis
          />

          {checklist.final?.length > 0 && (
            <DocumentGroup
              title="Needed later, before deployment"
              description="Only once a client company accepts your application. You do not need these yet."
              items={checklist.final}
            />
          )}
        </CardContent>
      </Card>

      <Button className="w-full" size="lg" onClick={onContinue}>
        I understand — continue
        <ArrowRight className="h-4 w-4" />
      </Button>
    </div>
  )
}

function DocumentGroup({ title, description, items, emphasis }) {
  if (!items?.length) return null

  return (
    <section className="space-y-2.5">
      <div className="flex items-center gap-2">
        <FileText className={emphasis ? 'h-4 w-4 text-primary' : 'h-4 w-4 text-muted-foreground'} />
        <h2 className="text-sm font-semibold">{title}</h2>
      </div>
      {description && <p className="text-xs text-muted-foreground">{description}</p>}

      <ul className="grid gap-1.5 sm:grid-cols-2">
        {items.map((item) => (
          <li
            key={item.requirement_name}
            className="flex items-center justify-between gap-2 rounded-md border bg-card px-3 py-2 text-sm"
          >
            <span>{item.requirement_name}</span>
            {!item.is_required && (
              <Badge tone="outline" className="shrink-0 text-[10px]">
                Optional
              </Badge>
            )}
          </li>
        ))}
      </ul>
    </section>
  )
}

/**
 * The confirmation.
 *
 * The reference number is the one thing to carry away from this page, so it is
 * the largest element on it. Everything else is what happens next, in order.
 */
function SuccessStep({ result, office, onGo }) {
  return (
    <Card className="shadow-sm">
      <CardContent className="space-y-6 pt-8 text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500/10">
          <CheckCircle2 className="h-7 w-7 text-emerald-600 dark:text-emerald-500" />
        </div>

        <div className="space-y-1.5">
          <h1 className="text-xl font-semibold tracking-tight">You are registered</h1>
          <p className="text-sm text-muted-foreground">
            Write this reference number down and bring it with you.
          </p>
        </div>

        <div className="mx-auto w-fit rounded-lg border-2 border-dashed border-primary/40 bg-primary/5 px-8 py-4">
          <p className="text-[10px] font-medium uppercase tracking-widest text-muted-foreground">
            Your reference number
          </p>
          <p className="mt-1 font-mono text-2xl font-semibold tracking-tight">
            {result?.applicant_code}
          </p>
        </div>

        <div className="space-y-3 text-left">
          <h2 className="text-sm font-semibold">What happens next</h2>
          <ol className="space-y-2.5">
            {[
              `Visit ${office?.name ?? 'the CDE Manpower Services office'}${
                office?.address ? ` in ${office.address}` : ''
              } with your documents.`,
              'Our staff will check your identity against your ID and receive your documents.',
              'Your application then moves to screening, and you can follow its progress here.',
            ].map((line, index) => (
              <li key={index} className="flex gap-3 text-sm">
                <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-semibold">
                  {index + 1}
                </span>
                <span className="text-muted-foreground">{line}</span>
              </li>
            ))}
          </ol>
        </div>

        <Button className="w-full" size="lg" onClick={onGo}>
          Go to my application
          <ArrowRight className="h-4 w-4" />
        </Button>
      </CardContent>
    </Card>
  )
}
