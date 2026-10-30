import * as React from 'react'
import { Link } from 'react-router-dom'
import { Building2, Loader2, MailCheck, Phone } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { post } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Field } from '@/components/ui/form'
import AuthLayout, { AuthCard } from '@/components/AuthLayout'

/**
 * "Forgot your password?" (audit FN-03, UX-09).
 *
 * Two ways back in. The office can always help: an officer checks the
 * person's ID and issues a temporary password that has to be replaced at the
 * first sign-in. An emailed reset link is offered only when the server says it
 * can actually send one - it is off until a real mailer is configured, and
 * this page never pretends otherwise.
 *
 * Whatever is typed, the answer is the same, so this page cannot be used to
 * find out whether somebody has an account.
 */
export default function ForgotPassword() {
  const { data, loading, error } = useApi('/auth/password-recovery', undefined, { retry: 1 })
  const [email, setEmail] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [answer, setAnswer] = React.useState(null)
  const [formError, setFormError] = React.useState('')
  const [submitting, setSubmitting] = React.useState(false)

  const emailAvailable = Boolean(data?.email_reset_available)

  async function submit(event) {
    event.preventDefault()
    setErrors({})
    setFormError('')
    setSubmitting(true)
    try {
      const response = await post('/auth/forgot-password', { email: email.trim() })
      setAnswer(response.message)
    } catch (err) {
      if (err.isValidation) setErrors(err.errors)
      else setFormError(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout
      title="Forgot your password?"
      subtitle="Here is how to get back into your account."
      footer={
        <p className="text-sm text-muted-foreground">
          Remembered it?{' '}
          <Link to="/login" className="font-medium text-foreground underline underline-offset-4">
            Back to sign in
          </Link>
        </p>
      }
    >
      <AuthCard>
        {loading ? (
          <div className="flex justify-center py-6" role="status">
            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" aria-hidden="true" />
            <span className="sr-only">Loading…</span>
          </div>
        ) : (
          <div className="space-y-5">
            {emailAvailable && !answer && (
              <form onSubmit={submit} className="space-y-4" noValidate>
                <p className="text-sm text-muted-foreground">
                  Enter the email address you sign in with. If it belongs to an account, a link to choose a new password
                  will be sent to it. The link works once and expires in {data.link_expires_minutes} minutes.
                </p>
                <Field label="Email address" htmlFor="email" required error={errors.email?.[0]}>
                  <Input
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    autoComplete="username"
                    autoFocus
                  />
                </Field>
                {formError && (
                  <p role="alert" className="rounded-md bg-destructive/5 px-3 py-2 text-sm text-destructive">
                    {formError}
                  </p>
                )}
                <Button type="submit" className="w-full" disabled={submitting || !email.trim()}>
                  {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
                  Send reset link
                </Button>
              </form>
            )}

            {answer && (
              <div className="flex items-start gap-3 rounded-md bg-status-info-bg p-4 text-sm text-status-info-text" role="status">
                <MailCheck className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <p>{answer}</p>
              </div>
            )}

            <OfficeRecovery office={data?.office} primary={!emailAvailable} unreachable={Boolean(error)} />
          </div>
        )}
      </AuthCard>
    </AuthLayout>
  )
}

/** The office route back in, which always works. */
export function OfficeRecovery({ office, primary, unreachable }) {
  return (
    <div className={primary ? 'space-y-3 text-sm' : 'space-y-2 border-t pt-4 text-sm'}>
      <p className={primary ? 'font-medium' : 'text-xs font-medium uppercase tracking-wide text-muted-foreground'}>
        {primary ? 'Ask the office to reset it' : 'Or ask the office'}
      </p>
      <p className="text-muted-foreground">
        Visit or call the office. An officer will check your ID and give you a temporary password. When you sign in with
        it, you will be asked to choose your own before anything else.
      </p>
      {unreachable && (
        <p className="text-xs text-muted-foreground">The system could not be reached just now; the office can still help.</p>
      )}
      {office?.name && (
        <div className="flex items-start gap-2 rounded-md bg-inset p-3">
          <Building2 className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
          <div>
            <p className="font-medium">{office.name}</p>
            {office.address && <p className="text-muted-foreground">{office.address}</p>}
            {office.contact && (
              <a href={`tel:${office.contact}`} className="mt-1 inline-flex items-center gap-1.5 text-brand hover:underline">
                <Phone className="h-3.5 w-3.5" aria-hidden="true" />
                {office.contact}
              </a>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
