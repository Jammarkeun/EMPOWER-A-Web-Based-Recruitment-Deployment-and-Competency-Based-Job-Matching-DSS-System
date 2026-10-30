import * as React from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { KeyRound, Loader2, LogOut } from 'lucide-react'
import { post } from '@/lib/api'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { PasswordInput } from '@/components/ui/input'
import { Field } from '@/components/ui/form'
import AuthLayout, { AuthCard } from '@/components/AuthLayout'
import { homePathFor } from '@/lib/utils'

/**
 * The one page an account on an office-issued password can use.
 *
 * The office knows every temporary password it hands out, so until the holder
 * replaces it the API refuses everything else for that account (audit SEC-07).
 * This page is where they are sent, from sign-in and from any refused request.
 */
export default function ChangePasswordRequired() {
  const { user, refreshUser, logout } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const [current, setCurrent] = React.useState('')
  const [password, setPassword] = React.useState('')
  const [confirmation, setConfirmation] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)

  if (user && !user.must_change_password) {
    return <Navigate to={homePathFor(user)} replace />
  }

  async function submit(event) {
    event.preventDefault()
    setErrors({})

    if (password !== confirmation) {
      setErrors({ password_confirmation: ['The two passwords do not match.'] })
      return
    }

    setSubmitting(true)
    try {
      await post('/auth/change-password', {
        current_password: current,
        password,
        password_confirmation: confirmation,
      })
      const updated = await refreshUser()
      toast.success('Password changed', 'Only you know it now.')
      navigate(homePathFor(updated ?? user), { replace: true })
    } catch (err) {
      if (err.isValidation) setErrors(err.errors)
      else toast.error('Password not changed', err.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout
      title="Choose your own password"
      subtitle="The password you signed in with was issued by the office. Replace it to continue."
    >
      <AuthCard>
        <form onSubmit={submit} className="space-y-4" noValidate>
          <div className="flex items-start gap-3 rounded-md bg-inset p-3 text-sm text-muted-foreground">
            <KeyRound className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <p>
              Signed in as <span className="font-medium text-foreground">{user?.email}</span>. Nothing else is available
              until you choose a password that only you know.
            </p>
          </div>

          <Field label="Temporary password" htmlFor="current_password" required error={errors.current_password?.[0]}>
            <PasswordInput value={current} onChange={(e) => setCurrent(e.target.value)} autoComplete="current-password" autoFocus />
          </Field>
          <Field
            label="New password"
            htmlFor="password"
            required
            error={errors.password?.[0]}
            hint="At least 12 characters, not a common password, and not the temporary one."
          >
            <PasswordInput value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" />
          </Field>
          <Field label="Confirm new password" htmlFor="password_confirmation" required error={errors.password_confirmation?.[0]}>
            <PasswordInput value={confirmation} onChange={(e) => setConfirmation(e.target.value)} autoComplete="new-password" />
          </Field>

          <Button type="submit" className="w-full" disabled={submitting || !current || !password || !confirmation}>
            {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
            Save my password
          </Button>
          <Button type="button" variant="ghost" className="w-full" onClick={logout}>
            <LogOut className="h-4 w-4" />
            Sign out instead
          </Button>
        </form>
      </AuthCard>
    </AuthLayout>
  )
}
