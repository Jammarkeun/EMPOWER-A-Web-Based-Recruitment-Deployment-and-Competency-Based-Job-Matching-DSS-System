import * as React from 'react'
import { Loader2, Monitor, Moon, ShieldCheck, Sun } from 'lucide-react'
import { post } from '@/lib/api'
import { useTheme } from '@/contexts/ThemeContext'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Field } from '@/components/ui/form'
import NotificationPreferences from '@/components/NotificationPreferences'
import { cn } from '@/lib/utils'

/**
 * Settings for an applicant or employee.
 *
 * Deliberately short. Every control here does something the backend actually
 * supports — the theme, which notifications arrive, and the account password —
 * and nothing was added to fill the page out. A settings screen whose switches
 * do nothing is worse than a smaller one that works, because it teaches people
 * that the controls in this system are decorative.
 *
 * Contact details are not repeated here; they live on Profile, which is where
 * an applicant looks for them.
 */
export default function PortalSettings() {
  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Settings</h1>
        <p className="text-sm text-muted-foreground">
          How this portal looks, what it tells you about, and your sign-in.
        </p>
      </div>

      <AppearanceCard />
      <NotificationPreferences />
      <PasswordCard />
    </div>
  )
}

const THEMES = [
  { value: 'light', label: 'Light', icon: Sun },
  { value: 'dark', label: 'Dark', icon: Moon },
  { value: 'system', label: 'System', icon: Monitor },
]

/**
 * The theme, through the one mechanism the application already has.
 *
 * The same `useTheme` the header toggle uses, so the two can never disagree and
 * a choice made here is the choice made there. Building a second theme store
 * for this page is exactly how an application ends up with a switch that only
 * works on some screens.
 */
function AppearanceCard() {
  const { theme, setTheme } = useTheme()

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Appearance</CardTitle>
        <CardDescription>
          System follows whatever your phone or computer is set to.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <div
          className="grid gap-2 sm:grid-cols-3"
          role="radiogroup"
          aria-label="Theme"
        >
          {THEMES.map((option) => {
            const active = theme === option.value

            return (
              <button
                key={option.value}
                type="button"
                role="radio"
                aria-checked={active}
                onClick={() => setTheme(option.value)}
                className={cn(
                  'flex items-center gap-2.5 rounded-lg border px-3 py-2.5 text-sm transition-colors',
                  active
                    ? 'border-primary bg-primary/10 font-medium text-foreground'
                    : 'hover:bg-accent'
                )}
              >
                <option.icon className="h-4 w-4 shrink-0" />
                {option.label}
              </button>
            )
          })}
        </div>
        <p className="mt-2 text-xs text-muted-foreground">
          Saved on this device, so a shared computer does not carry your choice to the next person.
        </p>
      </CardContent>
    </Card>
  )
}

function PasswordCard() {
  const toast = useToast()
  const [form, setForm] = React.useState({
    current_password: '',
    password: '',
    password_confirmation: '',
  })
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(false)

  function set(field, value) {
    setForm((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field]: undefined }))
  }

  async function submit(event) {
    event.preventDefault()

    if (saving) return

    setSaving(true)
    setErrors({})

    try {
      const response = await post('/auth/change-password', form)
      setForm({ current_password: '', password: '', password_confirmation: '' })
      toast.success('Password changed', response.message)
    } catch (err) {
      if (err.isValidation) {
        setErrors(err.errors)
      } else {
        toast.error('Could not change your password', err.message)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <ShieldCheck className="h-4 w-4" />
          Password
        </CardTitle>
        <CardDescription>
          Your current password is required, so a signed-in session left open cannot be used to
          lock you out of your own account.
        </CardDescription>
      </CardHeader>

      <CardContent>
        <form onSubmit={submit} className="space-y-4" noValidate>
          <Field
            label="Current password"
            htmlFor="current_password"
            required
            error={errors.current_password?.[0]}
          >
            <Input
              id="current_password"
              type="password"
              value={form.current_password}
              onChange={(e) => set('current_password', e.target.value)}
              autoComplete="current-password"
            />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label="New password"
              htmlFor="password"
              required
              error={errors.password?.[0]}
              hint="At least 8 characters."
            >
              <Input
                id="password"
                type="password"
                value={form.password}
                onChange={(e) => set('password', e.target.value)}
                autoComplete="new-password"
              />
            </Field>

            <Field
              label="Confirm new password"
              htmlFor="password_confirmation"
              required
              error={errors.password_confirmation?.[0]}
            >
              <Input
                id="password_confirmation"
                type="password"
                value={form.password_confirmation}
                onChange={(e) => set('password_confirmation', e.target.value)}
                autoComplete="new-password"
              />
            </Field>
          </div>

          <div className="flex justify-end">
            <Button type="submit" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              {saving ? 'Changing…' : 'Change password'}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
