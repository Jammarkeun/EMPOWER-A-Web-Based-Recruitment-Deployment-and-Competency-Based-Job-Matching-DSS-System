import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { CheckCircle2, Loader2 } from 'lucide-react'
import { post } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input, PasswordInput } from '@/components/ui/input'
import { Field } from '@/components/ui/form'
import AuthLayout, { AuthCard } from '@/components/AuthLayout'

/**
 * Choosing a new password from an emailed link.
 *
 * The link's token is read once and taken out of the address bar straight
 * away, so it does not sit in the browser history or get copied with the URL.
 * The server checks it, uses it once, and refuses it after 60 minutes.
 */
export default function ResetPassword() {
  const [params] = useSearchParams()
  const [token] = React.useState(() => params.get('token') ?? '')
  const [email, setEmail] = React.useState(() => params.get('email') ?? '')
  const [password, setPassword] = React.useState('')
  const [confirmation, setConfirmation] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [formError, setFormError] = React.useState('')
  const [done, setDone] = React.useState(false)
  const [submitting, setSubmitting] = React.useState(false)

  React.useEffect(() => {
    if (params.get('token')) window.history.replaceState(null, '', '/reset-password')
  }, [params])

  async function submit(event) {
    event.preventDefault()
    setErrors({})
    setFormError('')

    if (password !== confirmation) {
      setErrors({ password_confirmation: ['The two passwords do not match.'] })
      return
    }

    setSubmitting(true)
    try {
      await post('/auth/reset-password', {
        token,
        email: email.trim(),
        password,
        password_confirmation: confirmation,
      })
      setDone(true)
    } catch (err) {
      if (err.isValidation && Object.keys(err.errors).length) setErrors(err.errors)
      else setFormError(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout title="Choose a new password" subtitle="This replaces the password you forgot.">
      <AuthCard>
        {!token ? (
          <div className="space-y-3 text-sm">
            <p>This reset link is incomplete. Open the link from the email again, or ask for a new one.</p>
            <Button asChild variant="outline">
              <Link to="/forgot-password">Ask for a new link</Link>
            </Button>
          </div>
        ) : done ? (
          <div className="space-y-4 text-sm" role="status">
            <p className="flex items-center gap-2 font-medium">
              <CheckCircle2 className="h-4 w-4 text-success" aria-hidden="true" />
              Your password has been changed.
            </p>
            <p className="text-muted-foreground">Every other session was signed out. Sign in with your new password.</p>
            <Button asChild className="w-full">
              <Link to="/login">Sign in</Link>
            </Button>
          </div>
        ) : (
          <form onSubmit={submit} className="space-y-4" noValidate>
            <Field label="Email address" htmlFor="email" required error={errors.email?.[0]}>
              <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" />
            </Field>
            <Field
              label="New password"
              htmlFor="password"
              required
              error={errors.password?.[0]}
              hint="At least 12 characters, and not a common password."
            >
              <PasswordInput value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" />
            </Field>
            <Field label="Confirm new password" htmlFor="password_confirmation" required error={errors.password_confirmation?.[0]}>
              <PasswordInput
                value={confirmation}
                onChange={(e) => setConfirmation(e.target.value)}
                autoComplete="new-password"
              />
            </Field>
            {formError && (
              <div role="alert" className="space-y-2 rounded-md bg-destructive/5 px-3 py-2 text-sm text-destructive">
                <p>{formError}</p>
                <Link to="/forgot-password" className="font-medium underline">
                  Ask for a new link
                </Link>
              </div>
            )}
            <Button type="submit" className="w-full" disabled={submitting || !password || !confirmation}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Change password
            </Button>
          </form>
        )}
      </AuthCard>
    </AuthLayout>
  )
}
