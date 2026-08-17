import * as React from 'react'
import { useNavigate, useLocation, Navigate, Link } from 'react-router-dom'
import { Loader2, Eye, EyeOff, FolderCheck, Scale, ScrollText } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Field } from '@/components/ui/form'
import AuthLayout, { AuthCard } from '@/components/AuthLayout'

/**
 * Where a user belongs after signing in.
 *
 * Portal accounts must never land on the staff dashboard: they hold no staff
 * permissions, so it would render as a wall of refusals.
 */
function destinationFor(user) {
  return ['applicant', 'employee'].includes(user?.user_type) ? '/portal' : '/dashboard'
}

/*
 * Written for the two people who reach this screen — an HR officer and an
 * applicant checking their status — rather than as a feature list. Each names
 * something the system does with a record, because that is what a person is
 * being asked to trust it with.
 */
const POINTS = [
  {
    icon: FolderCheck,
    title: 'The folder system, digitally',
    body: 'Folder 1, 2, and 3 maintained from the documents actually verified.',
  },
  {
    icon: Scale,
    title: 'Explainable matching',
    body: 'Every candidate score shows the criteria behind it, not just a number.',
  },
  {
    icon: ScrollText,
    title: 'A complete audit trail',
    body: 'Every status change dated and attributed to the person who made it.',
  },
]

export default function Login() {
  const { login, isAuthenticated, loading, user } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const toast = useToast()

  const [email, setEmail] = React.useState('')
  const [password, setPassword] = React.useState('')
  const [showPassword, setShowPassword] = React.useState(false)
  const [errors, setErrors] = React.useState({})
  const [formError, setFormError] = React.useState('')
  const [submitting, setSubmitting] = React.useState(false)

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (isAuthenticated) {
    return <Navigate to={location.state?.from ?? destinationFor(user)} replace />
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setFormError('')
    setSubmitting(true)

    try {
      // login() returns the signed-in user, which is what decides the
      // destination — the context state has not updated yet at this point.
      const signedIn = await login(email, password)
      navigate(location.state?.from ?? destinationFor(signedIn), { replace: true })
      toast.success(`Welcome back, ${signedIn.full_name.split(' ')[0]}`, 'You are signed in.')
    } catch (error) {
      if (error.isValidation) {
        setErrors(error.errors)
      } else {
        setFormError(error.message)
      }
      /*
       * The inline message stays as well as the toast. A failed sign-in is
       * read at the field the user is about to correct, and unlike everywhere
       * else in the system there is no screen behind the form still showing
       * what went wrong once the toast has gone.
       */
      toast.error('Could not sign in', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout
      title="Sign in"
      subtitle="Enter your details to reach your dashboard."
      points={POINTS}
      footer={
        <p className="text-sm text-muted-foreground">
          Applying for work?{' '}
          <Link to="/register" className="font-medium text-foreground underline underline-offset-4">
            Create an account
          </Link>
        </p>
      }
    >
      <AuthCard>
        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Field label="Email address" htmlFor="email" required error={errors.email?.[0]}>
            <Input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoComplete="username"
              autoFocus
              required
            />
          </Field>

          <Field label="Password" htmlFor="password" required error={errors.password?.[0]}>
            <div className="relative">
              <Input
                type={showPassword ? 'text' : 'password'}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="current-password"
                className="pr-10"
                required
              />
              {/*
                Lets the user check a mistyped password rather than clearing the
                field and starting again. Kept out of the tab order so it never
                sits between the password field and the sign-in button.
              */}
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

          {formError && (
            <div
              className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
              role="alert"
            >
              {formError}
            </div>
          )}

          <Button type="submit" className="w-full" size="lg" disabled={submitting}>
            {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
            {submitting ? 'Signing in…' : 'Sign in'}
          </Button>
        </form>
      </AuthCard>
    </AuthLayout>
  )
}
