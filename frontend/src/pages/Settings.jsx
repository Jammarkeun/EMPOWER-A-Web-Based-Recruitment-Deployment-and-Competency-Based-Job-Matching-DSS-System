import * as React from 'react'
import {
  Loader2,
  Save,
  RotateCcw,
  Plus,
  Info,
  Lock,
  User as UserIcon,
  FileText,
  Scale,
  Building2,
  Sun,
  Moon,
  Monitor,
} from 'lucide-react'
import { get, put, post, patch } from '@/lib/api'
import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/ThemeContext'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Field, FormGrid } from '@/components/ui/form'
import { LoadingState, ErrorState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn, humanise } from '@/lib/utils'

/**
 * System configuration, organised by what each group governs rather than by
 * data type, because that is how an administrator thinks about it.
 *
 * Every user gets My Account. The rest requires settings.view, and the editing
 * controls only appear with settings.update — so HR can see which documents are
 * required without being offered controls that would be refused on submit.
 */
export default function Settings() {
  const { can } = useAuth()
  const [data, setData] = React.useState(null)
  const [error, setError] = React.useState(null)

  const canViewSystem = can('settings.view')

  const load = React.useCallback(() => {
    if (!canViewSystem) return
    get('/settings')
      .then((response) => setData(response.data))
      .catch(setError)
  }, [canViewSystem])

  React.useEffect(() => {
    load()
  }, [load])

  if (canViewSystem && error) return <ErrorState message={error.message} onRetry={load} />
  if (canViewSystem && !data) return <LoadingState label="Loading settings…" />

  const canEdit = data?.can_edit ?? false

  return (
    <>
      <PageHeader
        title="Settings"
        description={
          canViewSystem && !canEdit
            ? 'Your role can view these settings. Changes are made by an administrator.'
            : 'Your account and how the system behaves.'
        }
      />

      <Tabs defaultValue="account">
        <TabsList>
          <TabsTrigger value="account">My account</TabsTrigger>
          {canViewSystem && <TabsTrigger value="documents">Documents</TabsTrigger>}
          {canViewSystem && <TabsTrigger value="competency">Competency</TabsTrigger>}
          {canViewSystem && <TabsTrigger value="system">System</TabsTrigger>}
        </TabsList>

        <TabsContent value="account">
          <AccountTab />
        </TabsContent>

        {canViewSystem && (
          <TabsContent value="documents">
            <DocumentsTab data={data} canEdit={canEdit} onChanged={load} />
          </TabsContent>
        )}

        {canViewSystem && (
          <TabsContent value="competency">
            <CompetencyTab data={data} canEdit={canEdit} onChanged={load} />
          </TabsContent>
        )}

        {canViewSystem && (
          <TabsContent value="system">
            <SystemTab data={data} canEdit={canEdit} onChanged={load} />
          </TabsContent>
        )}
      </Tabs>
    </>
  )
}

/* ------------------------------------------------------------------ account */

function AccountTab() {
  const { user } = useAuth()
  const { theme, setTheme } = useTheme()
  const toast = useToast()

  const [profile, setProfile] = React.useState({
    first_name: user?.first_name ?? '',
    middle_name: user?.middle_name ?? '',
    last_name: user?.last_name ?? '',
    mobile_number: user?.mobile_number ?? '',
  })
  const [savingProfile, setSavingProfile] = React.useState(false)
  const [profileErrors, setProfileErrors] = React.useState({})

  const [passwords, setPasswords] = React.useState({
    current_password: '',
    password: '',
    password_confirmation: '',
  })
  const [savingPassword, setSavingPassword] = React.useState(false)
  const [passwordErrors, setPasswordErrors] = React.useState({})

  async function saveProfile(event) {
    event.preventDefault()
    setProfileErrors({})
    setSavingProfile(true)

    try {
      await patch('/auth/profile', profile)
      toast.success('Details updated', 'Sign out and back in to see the change everywhere.')
    } catch (error) {
      if (error.isValidation) setProfileErrors(error.errors)
      else toast.error('Could not update your details', error.message)
    } finally {
      setSavingProfile(false)
    }
  }

  async function savePassword(event) {
    event.preventDefault()
    setPasswordErrors({})
    setSavingPassword(true)

    try {
      await post('/auth/change-password', passwords)
      setPasswords({ current_password: '', password: '', password_confirmation: '' })
      toast.success('Password changed', 'Your other devices have been signed out.')
    } catch (error) {
      if (error.isValidation) setPasswordErrors(error.errors)
      else toast.error('Could not change your password', error.message)
    } finally {
      setSavingPassword(false)
    }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <UserIcon className="h-4 w-4" />
            Your details
          </CardTitle>
          <CardDescription>
            Your email address and role are set by an administrator.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={saveProfile} className="space-y-4" noValidate>
            <FormGrid>
              <Field label="First name" htmlFor="first_name" error={profileErrors.first_name?.[0]}>
                <Input
                  value={profile.first_name}
                  onChange={(e) => setProfile({ ...profile, first_name: e.target.value })}
                />
              </Field>
              <Field label="Last name" htmlFor="last_name" error={profileErrors.last_name?.[0]}>
                <Input
                  value={profile.last_name}
                  onChange={(e) => setProfile({ ...profile, last_name: e.target.value })}
                />
              </Field>
            </FormGrid>

            <Field label="Mobile number" htmlFor="mobile_number" error={profileErrors.mobile_number?.[0]}>
              <Input
                value={profile.mobile_number}
                onChange={(e) => setProfile({ ...profile, mobile_number: e.target.value })}
              />
            </Field>

            <div className="rounded-md border bg-muted/40 p-3 text-sm">
              <p className="text-xs text-muted-foreground">Email address</p>
              <p className="font-medium">{user?.email}</p>
              <p className="mt-2 text-xs text-muted-foreground">Role</p>
              <p className="font-medium capitalize">{user?.roles?.join(', ')}</p>
            </div>

            <Button type="submit" disabled={savingProfile}>
              {savingProfile ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
              Save details
            </Button>
          </form>
        </CardContent>
      </Card>

      <div className="space-y-4">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Lock className="h-4 w-4" />
              Change password
            </CardTitle>
            <CardDescription>
              Changing your password signs you out of every other device.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={savePassword} className="space-y-4" noValidate>
              <Field
                label="Current password"
                htmlFor="current_password"
                required
                error={passwordErrors.current_password?.[0]}
              >
                <Input
                  type="password"
                  value={passwords.current_password}
                  onChange={(e) => setPasswords({ ...passwords, current_password: e.target.value })}
                  autoComplete="current-password"
                />
              </Field>

              <Field
                label="New password"
                htmlFor="password"
                required
                error={passwordErrors.password?.[0]}
                hint="At least 8 characters"
              >
                <Input
                  type="password"
                  value={passwords.password}
                  onChange={(e) => setPasswords({ ...passwords, password: e.target.value })}
                  autoComplete="new-password"
                />
              </Field>

              <Field label="Confirm new password" htmlFor="password_confirmation" required>
                <Input
                  type="password"
                  value={passwords.password_confirmation}
                  onChange={(e) =>
                    setPasswords({ ...passwords, password_confirmation: e.target.value })
                  }
                  autoComplete="new-password"
                />
              </Field>

              <Button type="submit" disabled={savingPassword}>
                {savingPassword && <Loader2 className="h-4 w-4 animate-spin" />}
                Change password
              </Button>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Appearance</CardTitle>
            <CardDescription>Saved on this device only.</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-3 gap-2">
              {[
                { value: 'light', label: 'Light', icon: Sun },
                { value: 'dark', label: 'Dark', icon: Moon },
                { value: 'system', label: 'System', icon: Monitor },
              ].map((option) => (
                <button
                  key={option.value}
                  type="button"
                  onClick={() => setTheme(option.value)}
                  className={cn(
                    'flex flex-col items-center gap-1.5 rounded-md border p-3 text-xs transition-colors',
                    theme === option.value
                      ? 'border-primary bg-primary/5 font-medium text-primary'
                      : 'hover:bg-accent'
                  )}
                  aria-pressed={theme === option.value}
                >
                  <option.icon className="h-4 w-4" />
                  {option.label}
                </button>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

/* ---------------------------------------------------------------- documents */

function DocumentsTab({ data, canEdit, onChanged }) {
  const [dialogOpen, setDialogOpen] = React.useState(false)
  const toast = useToast()

  async function toggle(type, field, value) {
    try {
      await patch(`/settings/requirement-types/${type.id}`, { [field]: value })
      toast.success('Requirement updated')
      onChanged()
    } catch (error) {
      toast.error('Could not update requirement', error.message)
    }
  }

  const grouped = { primary: [], final: [] }
  for (const type of data.requirement_types ?? []) {
    grouped[type.requirement_group]?.push(type)
  }

  return (
    <div className="space-y-4">
      <SettingsGroup
        group={data.groups?.documents}
        canEdit={canEdit}
        onSaved={onChanged}
        title="Document handling"
        description="Applies to every uploaded requirement and evidence file."
      />

      <Card>
        <CardHeader className="flex-row items-start justify-between space-y-0">
          <div className="space-y-1">
            <CardTitle className="text-base">Requirements collected</CardTitle>
            <CardDescription>
              Which documents applicants must submit. Marking one as required affects the folder
              categories of everyone already on file.
            </CardDescription>
          </div>
          {canEdit && (
            <Button size="sm" variant="outline" onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Add
            </Button>
          )}
        </CardHeader>
        <CardContent className="space-y-5">
          {['primary', 'final'].map((group) => (
            <div key={group}>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {group === 'primary' ? 'Primary requirements' : 'Final requirements (medical)'}
              </p>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Document</TableHead>
                    <TableHead className="w-28">Required</TableHead>
                    <TableHead className="w-28">Expires</TableHead>
                    <TableHead className="w-28">Active</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {grouped[group].map((type) => (
                    <TableRow key={type.id} className={type.active_flag ? '' : 'opacity-50'}>
                      <TableCell>
                        <p className="font-medium">{type.requirement_name}</p>
                        <p className="font-mono text-xs text-muted-foreground">
                          {type.requirement_code}
                        </p>
                      </TableCell>
                      <TableCell>
                        <Toggle
                          checked={type.is_required}
                          disabled={!canEdit}
                          onChange={(v) => toggle(type, 'is_required', v)}
                          label={`${type.requirement_name} required`}
                        />
                      </TableCell>
                      <TableCell>
                        <Toggle
                          checked={type.has_expiry}
                          disabled={!canEdit}
                          onChange={(v) => toggle(type, 'has_expiry', v)}
                          label={`${type.requirement_name} expires`}
                        />
                      </TableCell>
                      <TableCell>
                        <Toggle
                          checked={type.active_flag}
                          disabled={!canEdit}
                          onChange={(v) => toggle(type, 'active_flag', v)}
                          label={`${type.requirement_name} active`}
                        />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          ))}
        </CardContent>
      </Card>

      <NewRequirementDialog open={dialogOpen} onOpenChange={setDialogOpen} onCreated={onChanged} />
    </div>
  )
}

/* --------------------------------------------------------------- competency */

function CompetencyTab({ data, canEdit, onChanged }) {
  const [dialogOpen, setDialogOpen] = React.useState(false)
  const toast = useToast()

  async function toggleActive(criterion, value) {
    try {
      const response = await patch(`/settings/criteria/${criterion.id}`, { is_active: value })
      toast.success(response.message)
      onChanged()
    } catch (error) {
      toast.error('Could not update criterion', error.message)
    }
  }

  return (
    <div className="space-y-4">
      <Card className="border-primary/30 bg-primary/[0.04]">
        <CardContent className="flex items-start gap-3 py-3.5">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
          <p className="text-sm text-muted-foreground">
            These bands decide how a score is described to HR. They change the wording on a
            recommendation, never who is eligible &mdash; a mandatory requirement still disqualifies
            a candidate regardless of their score.
          </p>
        </CardContent>
      </Card>

      <SettingsGroup
        group={data.groups?.competency}
        canEdit={canEdit}
        onSaved={onChanged}
        title="Recommendation bands"
        description="Must descend: highly recommended above recommended, above reserve pool."
      />

      <Card>
        <CardHeader className="flex-row items-start justify-between space-y-0">
          <div className="space-y-1">
            <CardTitle className="text-base">Competency criteria</CardTitle>
            <CardDescription>
              The factors HR can attach to a manpower request. Weights are set per request, not
              here.
            </CardDescription>
          </div>
          {canEdit && (
            <Button size="sm" variant="outline" onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Add
            </Button>
          )}
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Criterion</TableHead>
                <TableHead>How it is measured</TableHead>
                <TableHead className="w-32">Direction</TableHead>
                <TableHead className="w-24">Active</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {(data.criteria ?? []).map((criterion) => (
                <TableRow key={criterion.id} className={criterion.is_active ? '' : 'opacity-50'}>
                  <TableCell>
                    <p className="font-medium">{criterion.criteria_name}</p>
                    <p className="font-mono text-xs text-muted-foreground">
                      {criterion.criteria_code}
                    </p>
                  </TableCell>
                  <TableCell className="max-w-sm text-sm text-muted-foreground">
                    {criterion.description}
                    <div className="mt-1">
                      <Badge tone={criterion.criteria_type === 'hard_filter' ? 'warning' : 'muted'}>
                        {humanise(criterion.criteria_type)}
                      </Badge>
                    </div>
                  </TableCell>
                  <TableCell className="text-sm">
                    {criterion.score_direction === 'lower_better' ? 'Lower is better' : 'Higher is better'}
                  </TableCell>
                  <TableCell>
                    <Toggle
                      checked={criterion.is_active}
                      disabled={!canEdit}
                      onChange={(v) => toggleActive(criterion, v)}
                      label={`${criterion.criteria_name} active`}
                    />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <NewCriterionDialog open={dialogOpen} onOpenChange={setDialogOpen} onCreated={onChanged} />
    </div>
  )
}

/* ------------------------------------------------------------------- system */

function SystemTab({ data, canEdit, onChanged }) {
  return (
    <div className="space-y-4">
      <SettingsGroup
        group={data.groups?.organisation}
        canEdit={canEdit}
        onSaved={onChanged}
        title="Agency details"
        description="Printed in the header of every exported report."
        icon={Building2}
      />

      <SettingsGroup
        group={data.groups?.system}
        canEdit={canEdit}
        onSaved={onChanged}
        title="Document reading"
        description="Automatic reading of uploaded resumes and IDs."
      />
    </div>
  )
}

/* ---------------------------------------------------------------- shared UI */

/**
 * Renders one group of settings as an editable form.
 *
 * Values changed from the shipped default are marked, with a control to put
 * them back — otherwise an administrator has no way of knowing what has been
 * altered or what it originally was.
 */
function SettingsGroup({ group, canEdit, onSaved, title, description, icon: Icon }) {
  const [values, setValues] = React.useState({})
  const [saving, setSaving] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (!group) return
    setValues(Object.fromEntries(group.map((s) => [s.key, s.value])))
  }, [group])

  if (!group?.length) return null

  const dirty = group.some((s) => String(values[s.key]) !== String(s.value))

  async function save() {
    setSaving(true)
    try {
      const changed = group
        .filter((s) => String(values[s.key]) !== String(s.value))
        .map((s) => ({ key: s.key, value: coerce(values[s.key], s.type) }))

      await put('/settings', { settings: changed })
      toast.success('Settings saved', 'The change takes effect immediately.')
      onSaved()
    } catch (error) {
      toast.error('Could not save settings', error.message)
    } finally {
      setSaving(false)
    }
  }

  async function reset(key) {
    try {
      await post('/settings/reset', { key })
      toast.success('Returned to default')
      onSaved()
    } catch (error) {
      toast.error('Could not reset', error.message)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          {Icon && <Icon className="h-4 w-4" />}
          {title}
        </CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {group.map((setting) => (
          <div key={setting.key} className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-start">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-sm font-medium">{setting.label}</p>
                {setting.is_overridden && (
                  <button
                    type="button"
                    onClick={() => reset(setting.key)}
                    disabled={!canEdit}
                    className="inline-flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground disabled:pointer-events-none"
                    title={`Default is ${String(setting.default)}`}
                  >
                    <RotateCcw className="h-3 w-3" />
                    changed from {String(setting.default)}
                  </button>
                )}
              </div>
              <p className="text-xs text-muted-foreground">{setting.help}</p>
            </div>

            <div className="flex items-center gap-2 sm:w-56 sm:justify-end">
              {setting.type === 'boolean' ? (
                <Toggle
                  checked={!!values[setting.key]}
                  disabled={!canEdit}
                  onChange={(v) => setValues({ ...values, [setting.key]: v })}
                  label={setting.label}
                />
              ) : (
                <>
                  <Input
                    type={setting.type === 'string' ? 'text' : 'number'}
                    step={setting.type === 'decimal' ? '0.05' : '1'}
                    value={values[setting.key] ?? ''}
                    disabled={!canEdit}
                    onChange={(e) => setValues({ ...values, [setting.key]: e.target.value })}
                    className="h-8"
                  />
                  {setting.unit && (
                    <span className="shrink-0 text-xs text-muted-foreground">{setting.unit}</span>
                  )}
                </>
              )}
            </div>
          </div>
        ))}

        {canEdit && (
          <div className="flex justify-end border-t pt-3">
            <Button size="sm" onClick={save} disabled={!dirty || saving}>
              {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
              {dirty ? 'Save changes' : 'Saved'}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

/** Accessible switch. Native checkbox underneath so keyboard and screen readers work. */
function Toggle({ checked, onChange, disabled, label }) {
  return (
    <label className={cn('inline-flex cursor-pointer items-center', disabled && 'cursor-not-allowed opacity-60')}>
      <input
        type="checkbox"
        className="peer sr-only"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
        aria-label={label}
      />
      <span
        className={cn(
          'relative h-5 w-9 rounded-full transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2',
          checked ? 'bg-primary' : 'bg-muted-foreground/30'
        )}
      >
        <span
          className={cn(
            'absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform',
            checked ? 'translate-x-[1.125rem]' : 'translate-x-0.5'
          )}
        />
      </span>
    </label>
  )
}

function NewRequirementDialog({ open, onOpenChange, onCreated }) {
  const [form, setForm] = React.useState(emptyRequirement())
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setForm(emptyRequirement())
      setErrors({})
    }
  }, [open])

  async function submit(event) {
    event.preventDefault()
    setErrors({})
    setSaving(true)

    try {
      await post('/settings/requirement-types', form)
      toast.success('Requirement added', 'It now appears on every applicant checklist.')
      onOpenChange(false)
      onCreated()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not add requirement', error.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add a requirement</DialogTitle>
          <DialogDescription>
            This is added to the checklist of every applicant, including those already on file.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-4" noValidate>
          <Field label="Document name" htmlFor="requirement_name" required error={errors.requirement_name?.[0]}>
            <Input
              value={form.requirement_name}
              onChange={(e) => setForm({ ...form, requirement_name: e.target.value })}
              placeholder="e.g. NBI Clearance"
              autoFocus
            />
          </Field>

          <FormGrid>
            <Field
              label="Short code"
              htmlFor="requirement_code"
              required
              error={errors.requirement_code?.[0]}
              hint="Lowercase, no spaces"
            >
              <Input
                value={form.requirement_code}
                onChange={(e) =>
                  setForm({ ...form, requirement_code: e.target.value.toLowerCase().replace(/\s+/g, '_') })
                }
                placeholder="nbi_clearance"
              />
            </Field>

            <Field label="Collected during" htmlFor="requirement_group" required>
              <Select
                value={form.requirement_group}
                onChange={(e) => setForm({ ...form, requirement_group: e.target.value })}
              >
                <option value="primary">Screening (primary)</option>
                <option value="final">Before deployment (medical)</option>
              </Select>
            </Field>
          </FormGrid>

          <div className="space-y-3 rounded-md border p-3">
            <label className="flex items-center justify-between gap-3 text-sm">
              <span>
                Required for deployment
                <span className="block text-xs text-muted-foreground">
                  Optional documents do not hold up a folder category.
                </span>
              </span>
              <Toggle
                checked={form.is_required}
                onChange={(v) => setForm({ ...form, is_required: v })}
                label="Required for deployment"
              />
            </label>

            <label className="flex items-center justify-between gap-3 border-t pt-3 text-sm">
              <span>
                Expires
                <span className="block text-xs text-muted-foreground">
                  An expiry date becomes mandatory on upload, and lapsed copies stop counting.
                </span>
              </span>
              <Toggle
                checked={form.has_expiry}
                onChange={(v) => setForm({ ...form, has_expiry: v })}
                label="Expires"
              />
            </label>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              Add requirement
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function NewCriterionDialog({ open, onOpenChange, onCreated }) {
  const [form, setForm] = React.useState(emptyCriterion())
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setForm(emptyCriterion())
      setErrors({})
    }
  }, [open])

  async function submit(event) {
    event.preventDefault()
    setErrors({})
    setSaving(true)

    try {
      await post('/settings/criteria', form)
      toast.success('Criterion added', 'HR can now attach it to a manpower request.')
      onOpenChange(false)
      onCreated()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not add criterion', error.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add a competency criterion</DialogTitle>
          <DialogDescription>
            A factor HR can weigh when ranking applicants. The weight is chosen per request.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-4" noValidate>
          <FormGrid>
            <Field label="Name" htmlFor="criteria_name" required error={errors.criteria_name?.[0]}>
              <Input
                value={form.criteria_name}
                onChange={(e) => setForm({ ...form, criteria_name: e.target.value })}
                placeholder="e.g. Physical Fitness"
                autoFocus
              />
            </Field>

            <Field
              label="Code"
              htmlFor="criteria_code"
              required
              error={errors.criteria_code?.[0]}
              hint="Lowercase letters and underscores"
            >
              <Input
                value={form.criteria_code}
                onChange={(e) =>
                  setForm({
                    ...form,
                    criteria_code: e.target.value.toLowerCase().replace(/[^a-z_]/g, '_'),
                  })
                }
                placeholder="physical_fitness"
              />
            </Field>
          </FormGrid>

          <Field label="What it measures" htmlFor="description" required error={errors.description?.[0]}>
            <Input
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
              placeholder="Shown to HR when configuring a request"
            />
          </Field>

          <FormGrid>
            <Field label="How it scores" htmlFor="criteria_type" required>
              <Select
                value={form.criteria_type}
                onChange={(e) => setForm({ ...form, criteria_type: e.target.value })}
              >
                <option value="weighted_scale">Graded on a range</option>
                <option value="weighted_binary">All or nothing</option>
                <option value="hard_filter">Eligibility gate</option>
              </Select>
            </Field>

            <Field label="Direction" htmlFor="score_direction" required>
              <Select
                value={form.score_direction}
                onChange={(e) => setForm({ ...form, score_direction: e.target.value })}
              >
                <option value="higher_better">Higher is better</option>
                <option value="lower_better">Lower is better</option>
              </Select>
            </Field>
          </FormGrid>

          <Field label="Value type" htmlFor="value_type" required>
            <Select
              value={form.value_type}
              onChange={(e) => setForm({ ...form, value_type: e.target.value })}
            >
              <option value="number">Number</option>
              <option value="text">Text</option>
              <option value="enum">Fixed choices</option>
              <option value="boolean">Yes or no</option>
            </Select>
          </Field>

          <div className="rounded-md border border-warning/40 bg-warning/5 p-3">
            <p className="text-xs text-muted-foreground">
              A new criterion needs a matching value on the applicant record to score against. If
              nothing is recorded there, it will score zero for everyone.
            </p>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              Add criterion
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/** Inputs return strings; the API expects the declared type. */
function coerce(value, type) {
  if (type === 'integer') return parseInt(value, 10)
  if (type === 'decimal') return parseFloat(value)
  if (type === 'boolean') return !!value
  return value
}

function emptyRequirement() {
  return {
    requirement_code: '',
    requirement_name: '',
    requirement_group: 'primary',
    is_required: true,
    has_expiry: false,
  }
}

function emptyCriterion() {
  return {
    criteria_code: '',
    criteria_name: '',
    criteria_type: 'weighted_scale',
    value_type: 'number',
    score_direction: 'higher_better',
    description: '',
  }
}
