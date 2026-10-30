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
  ShieldAlert,
  Archive,
  Sun,
  Moon,
  Monitor,
  FolderSync,
  Pencil,
} from 'lucide-react'
import { get, put, post, patch } from '@/lib/api'
import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/ThemeContext'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import NotificationPreferences from '@/components/NotificationPreferences'
import ProfilePhotoUploader from '@/components/ProfilePhotoUploader'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input, MobileInput, NameInput, NumberInput } from '@/components/ui/input'
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
import { cn, formatDateTime, humanise } from '@/lib/utils'
import { formProblems } from '@/lib/validation'

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
          {canViewSystem && <TabsTrigger value="competency">Job requirements</TabsTrigger>}
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

    // Named beside the field before sending; the server checks again.
    const problems = formProblems(profile, { mobiles: ['mobile_number'], names: ['first_name', 'last_name'] })
    setProfileErrors(problems)
    if (Object.keys(problems).length) return

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
      <div className="space-y-4">
        {/* The portal's control, saving to the staff account instead of an
            applicant record. */}
        <ProfilePhotoUploader
          endpoint="/auth/profile/photo"
          description="Shown beside your name at the top of every page."
        />

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
                  <NameInput
                    value={profile.first_name}
                    onChange={(e) => setProfile({ ...profile, first_name: e.target.value })}
                  />
                </Field>
                <Field label="Last name" htmlFor="last_name" error={profileErrors.last_name?.[0]}>
                  <NameInput
                    value={profile.last_name}
                    onChange={(e) => setProfile({ ...profile, last_name: e.target.value })}
                  />
                </Field>
              </FormGrid>

              <Field label="Mobile number" htmlFor="mobile_number" error={profileErrors.mobile_number?.[0]}>
                <MobileInput
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
      </div>

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

        {/* The same control the portal offers, from the same component: the
            categories and the behaviour are identical, and two copies would be
            two things to keep in step. */}
        <NotificationPreferences />
      </div>
    </div>
  )
}

/* ---------------------------------------------------------------- documents */

/*
 * Whether a document's expiry still matters once the worker is deployed.
 * CDE, 26 October 2026: an NBI clearance only upon employment; the annual
 * medical and the health card are renewed while employed. Left blank, a
 * deployed worker's lapse is shown as unconfirmed and never flagged.
 */
const POST_DEPLOYMENT_RULES = [
  { value: '', label: 'Not yet confirmed by CDE' },
  { value: 'pre_deployment_only', label: 'Valid until deployment only' },
  { value: 'renew_while_employed', label: 'Renewed while employed' },
]

function DocumentsTab({ data, canEdit, onChanged }) {
  const [dialogOpen, setDialogOpen] = React.useState(false)
  const toast = useToast()

  async function toggle(type, field, value) {
    try {
      const response = await patch(`/settings/requirement-types/${type.id}`, { [field]: value })
      reportRecalculation(toast, response)
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

      <FolderRecalculationPanel run={data.folder_recalculation} canEdit={canEdit} onChanged={onChanged} />

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
                    <TableHead className="w-56">After deployment</TableHead>
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
                        {type.has_expiry ? (
                          <Select
                            aria-label={`${type.requirement_name} after deployment`}
                            value={type.post_deployment_rule ?? ''}
                            disabled={!canEdit}
                            onChange={(e) => toggle(type, 'post_deployment_rule', e.target.value || null)}
                          >
                            {POST_DEPLOYMENT_RULES.map((rule) => (
                              <option key={rule.value} value={rule.value}>
                                {rule.label}
                              </option>
                            ))}
                          </Select>
                        ) : (
                          <span className="text-xs text-muted-foreground">Does not expire</span>
                        )}
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

/**
 * Says what the folder recalculation after a requirement change did (R-17).
 * A run that did not finish is a warning, never a quiet success.
 */
function reportRecalculation(toast, response) {
  const run = response.data?.folder_recalculation

  if (run && run.state !== 'completed') {
    toast.warning('Saved, but folders were not all recalculated', response.message)
  } else {
    toast.success(response.message ?? 'Requirement updated')
  }
}

/**
 * The last folder recalculation (R-17). Changing which documents are required
 * recalculates every applicant's folder; this says whether that finished, and
 * lets an administrator run it again after a failure or interruption.
 * Folders only - no applicant's stage in the pipeline is changed by it.
 */
function FolderRecalculationPanel({ run, canEdit, onChanged }) {
  const toast = useToast()
  const [running, setRunning] = React.useState(false)

  async function recalculate() {
    setRunning(true)
    try {
      const response = await post('/settings/folder-recalculations')
      const result = response.data?.folder_recalculation
      if (result?.state === 'completed') toast.success('Folders recalculated', response.message)
      else toast.warning('Recalculation did not finish', response.message)
      onChanged()
    } catch (error) {
      toast.error('Could not recalculate folders', error.message)
    } finally {
      setRunning(false)
    }
  }

  const tone = { completed: 'success', failed: 'destructive', interrupted: 'warning', running: 'info' }[run?.state] ?? 'muted'
  const triggers = {
    requirement_change: 'after a requirement change',
    document_expiry: 'by the daily expiry check',
    manual: 'on request',
  }

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between space-y-0">
        <div className="space-y-1">
          <CardTitle className="flex items-center gap-2 text-base">
            <FolderSync className="h-4 w-4 text-muted-foreground" />
            Folder recalculation
          </CardTitle>
          <CardDescription>
            Applicant folders follow the requirements above. They are recalculated after every change here and after
            documents expire. Only folders change; no applicant's stage does.
          </CardDescription>
        </div>
        {canEdit && (
          <Button size="sm" variant={run?.needs_retry ? 'default' : 'outline'} onClick={recalculate} disabled={running}>
            {running ? <Loader2 className="h-4 w-4 animate-spin" /> : <FolderSync className="h-4 w-4" />}
            {run?.needs_retry ? 'Finish recalculation' : 'Recalculate folders'}
          </Button>
        )}
      </CardHeader>
      <CardContent className="space-y-2 text-sm">
        {run ? (
          <>
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={tone}>{humanise(run.state)}</Badge>
              <span className="text-muted-foreground">
                Last run {triggers[run.trigger] ?? humanise(run.trigger)}
                {run.requested_by && ` by ${run.requested_by}`}
                {run.started_at && ` · ${formatDateTime(run.started_at)}`}
              </span>
            </div>
            <p className="text-muted-foreground">
              {run.applicants_checked} applicant(s) checked, {run.applicants_changed} folder(s) changed.
            </p>
            {run.error && <p className="text-status-danger-text">{run.error}</p>}
            {run.state === 'interrupted' && (
              <p className="text-status-warning-text">
                This run stopped without finishing. Run it again so every folder matches the current requirements.
              </p>
            )}
          </>
        ) : (
          <p className="text-muted-foreground">No recalculation has run yet.</p>
        )}
      </CardContent>
    </Card>
  )
}

/* --------------------------------------------------------------- competency */

/*
 * What each catalogue setting does in the matching engine, in words an
 * administrator can choose between. The type only matters in two places: a
 * hard filter is always Must Have, and it decides whether a typed list needs
 * every item (graded) or any one of them.
 */
const SCORING_OPTIONS = [
  {
    value: 'weighted_scale',
    label: 'Scored',
    hint: 'Adds points to the match score. For a list, applicants need every item and earn part of the points for some.',
  },
  {
    value: 'weighted_binary',
    label: 'Scored, any one item is enough',
    hint: 'Adds points to the match score. For a list, holding any one item earns full points.',
  },
  {
    value: 'hard_filter',
    label: 'Always Must Have',
    hint: 'Pass or fail on every client and request. Applicants who do not meet it are not eligible. It adds no points.',
  },
]

const VALUE_OPTIONS = [
  { value: 'number', label: 'Number', hint: 'Compared with a range HR sets, such as 18 to 35 years.' },
  { value: 'text', label: 'List of items', hint: 'HR types the items the client needs, such as skills.' },
  { value: 'enum', label: 'Rating (number scale)', hint: 'A number on a scale, such as an interview rating, compared with a range.' },
  { value: 'boolean', label: 'Yes or no', hint: "Met when the applicant's record says yes." },
]

const scoringLabel = (type) => SCORING_OPTIONS.find((o) => o.value === type)?.label ?? humanise(type)

function usageText(criterion) {
  const requests = criterion.request_criteria_count ?? 0
  const clients = criterion.company_criteria_count ?? 0
  if (!requests && !clients) return 'Not used yet'

  return [
    requests ? `${requests} request${requests === 1 ? '' : 's'}` : null,
    clients ? `${clients} client${clients === 1 ? '' : 's'}` : null,
  ]
    .filter(Boolean)
    .join(' · ')
}

export function CompetencyTab({ data, canEdit, onChanged }) {
  // null: closed. {}: adding. A criterion: editing it.
  const [editing, setEditing] = React.useState(null)
  const [turningOff, setTurningOff] = React.useState(null)
  const [busyId, setBusyId] = React.useState(null)
  const toast = useToast()

  async function setActive(criterion, value) {
    setBusyId(criterion.id)
    try {
      const response = await patch(`/settings/criteria/${criterion.id}`, { is_active: value })
      toast.success(value ? 'Requirement turned on' : 'Requirement turned off', response.message)
      setTurningOff(null)
      onChanged()
    } catch (error) {
      toast.error(value ? 'Could not turn the requirement on' : 'Could not turn the requirement off', error.message)
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="space-y-4">
      <Card className="border-primary/30 bg-primary/[0.04]">
        <CardContent className="flex items-start gap-3 py-3.5">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
          <p className="text-sm text-muted-foreground">
            These bands decide how a score is described to HR. They change the wording on a
            recommendation, never who is eligible &mdash; a Must Have requirement still makes a
            candidate not eligible regardless of their score.
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
        <CardHeader className="flex-row flex-wrap items-start justify-between gap-3 space-y-0">
          <div className="min-w-0 flex-1 space-y-1">
            <CardTitle className="text-base">Job requirements</CardTitle>
            <CardDescription>
              The requirements HR can choose from when setting up a client or a manpower request.
              Importance and values are set there, not here. Turning one off hides it from new
              setups; requests that already use it keep it.
            </CardDescription>
          </div>
          {canEdit && (
            <Button size="sm" variant="outline" onClick={() => setEditing({})}>
              <Plus className="h-4 w-4" />
              Add job requirement
            </Button>
          )}
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Requirement</TableHead>
                <TableHead>What it measures</TableHead>
                <TableHead className="w-32">Better when</TableHead>
                <TableHead className="w-24">Offered</TableHead>
                {canEdit && <TableHead className="w-16"><span className="sr-only">Actions</span></TableHead>}
              </TableRow>
            </TableHeader>
            <TableBody>
              {(data.criteria ?? []).map((criterion) => (
                <TableRow key={criterion.id} className={criterion.is_active ? '' : 'opacity-60'}>
                  <TableCell>
                    <p className="font-medium">{criterion.criteria_name}</p>
                    <p className="font-mono text-xs text-muted-foreground">{criterion.criteria_code}</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">Used on: {usageText(criterion)}</p>
                  </TableCell>
                  <TableCell className="max-w-sm text-sm text-muted-foreground">
                    {criterion.description}
                    <div className="mt-1">
                      <Badge tone={criterion.criteria_type === 'hard_filter' ? 'warning' : 'muted'}>
                        {scoringLabel(criterion.criteria_type)}
                      </Badge>
                    </div>
                  </TableCell>
                  <TableCell className="text-sm">
                    {criterion.score_direction === 'lower_better' ? 'Lower is better' : 'Higher is better'}
                  </TableCell>
                  <TableCell>
                    <Toggle
                      checked={criterion.is_active}
                      disabled={!canEdit || busyId === criterion.id}
                      onChange={(value) => (value ? setActive(criterion, true) : setTurningOff(criterion))}
                      label={`Offer ${criterion.criteria_name}`}
                    />
                  </TableCell>
                  {canEdit && (
                    <TableCell>
                      <Button
                        size="icon"
                        variant="ghost"
                        onClick={() => setEditing(criterion)}
                        aria-label={`Edit ${criterion.criteria_name}`}
                        title="Edit job requirement"
                      >
                        <Pencil className="h-4 w-4" />
                      </Button>
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <CriterionDialog
        open={editing !== null}
        criterion={editing?.id ? editing : null}
        onOpenChange={(next) => !next && setEditing(null)}
        onSaved={onChanged}
      />

      {/* Switching off is reversible and deletes nothing, but it changes what
          HR can choose, so it says what will happen first. */}
      <ConfirmActionDialog
        open={turningOff !== null}
        onOpenChange={(next) => !next && setTurningOff(null)}
        tone="warning"
        title={`Turn off ${turningOff?.criteria_name ?? 'this requirement'}?`}
        description="It can be turned back on at any time. Nothing is deleted."
        consequences={[
          'HR can no longer add it to a client or a new manpower request.',
          turningOff?.request_criteria_count
            ? `It stays on the ${turningOff.request_criteria_count} request(s) that already use it, and still counts there, so their rankings can still be explained.`
            : 'No request uses it yet.',
        ]}
        confirmLabel="Turn off"
        onConfirm={() => setActive(turningOff, false)}
      />
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

      {/*
        The client's own handbook, not the system's rules.
        A disciplinary record clearing after a year and a fourth offence putting
        somebody up for review are CDE's decisions, so they live here rather
        than in the code — and an agency that changes its policy should not
        need a developer.
      */}
      <SettingsGroup
        group={data.groups?.policy}
        canEdit={canEdit}
        onSaved={onChanged}
        title="Disciplinary and workflow policy"
        description="Reaching the review threshold flags an employee for an administrator. It never terminates anybody on its own."
        icon={ShieldAlert}
      />

      <SettingsGroup
        group={data.groups?.retention}
        canEdit={canEdit}
        onSaved={onChanged}
        title="Record retention"
        description="These decide when a record is offered for archiving. Nothing is ever deleted automatically."
        icon={Archive}
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
                  <SettingInput
                    type={setting.type}
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
      const response = await post('/settings/requirement-types', form)
      if (response.data?.folder_recalculation) {
        reportRecalculation(toast, response)
      } else {
        toast.success('Requirement added', 'It now appears on every applicant checklist.')
      }
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

/** Checks what the server will check, so a missing field is named before saving. */
export function criterionProblems(form, editing) {
  const problems = {}
  if (!form.criteria_name.trim()) problems.criteria_name = ['Enter a name.']
  if (!editing && !form.criteria_code.trim()) problems.criteria_code = ['Enter a code.']
  if (!form.description.trim()) problems.description = ['Describe what it measures.']
  return problems
}

/**
 * Adds a job requirement to the catalogue, or edits one in place.
 *
 * The code and value type are fixed once created: the code names the
 * applicant field the requirement reads, and values already saved against it
 * were entered in its value type. Editing shows them but does not send them.
 */
function CriterionDialog({ open, onOpenChange, criterion, onSaved }) {
  const editing = Boolean(criterion)
  const [form, setForm] = React.useState(emptyCriterion())
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(false)
  const toast = useToast()

  // Every opening starts from what is saved, so a cancelled edit leaves
  // nothing behind.
  React.useEffect(() => {
    if (!open) return
    setForm(
      criterion
        ? {
            criteria_code: criterion.criteria_code,
            criteria_name: criterion.criteria_name ?? '',
            criteria_type: criterion.criteria_type,
            value_type: criterion.value_type,
            score_direction: criterion.score_direction,
            description: criterion.description ?? '',
          }
        : emptyCriterion()
    )
    setErrors({})
  }, [open, criterion])

  const editable = ['criteria_name', 'criteria_type', 'score_direction', 'description']
  const changed = editing ? editable.filter((key) => form[key] !== (criterion[key] ?? '')) : editable
  const inUse = (criterion?.request_criteria_count ?? 0) + (criterion?.company_criteria_count ?? 0)
  const scoringChanged = editing && (changed.includes('criteria_type') || changed.includes('score_direction'))

  async function submit(event) {
    event.preventDefault()

    const problems = criterionProblems(form, editing)
    setErrors(problems)
    if (Object.keys(problems).length) return

    setSaving(true)
    try {
      if (editing) {
        await patch(
          `/settings/criteria/${criterion.id}`,
          Object.fromEntries(changed.map((key) => [key, form[key]]))
        )
        toast.success('Job requirement updated')
      } else {
        await post('/settings/criteria', form)
        toast.success('Job requirement added', 'HR can now choose it for clients and requests.')
      }
      onOpenChange(false)
      onSaved()
    } catch (error) {
      if (error.isValidation && Object.keys(error.errors ?? {}).length) {
        setErrors(error.errors)
        toast.error('Some entries need fixing', 'Check the highlighted fields.')
      } else {
        toast.error('Could not save the job requirement', error.message)
      }
    } finally {
      setSaving(false)
    }
  }

  const scoring = SCORING_OPTIONS.find((o) => o.value === form.criteria_type)
  const valueOption = VALUE_OPTIONS.find((o) => o.value === form.value_type)

  return (
    <Dialog open={open} onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{editing ? 'Edit job requirement' : 'Add job requirement'}</DialogTitle>
          <DialogDescription>
            A requirement HR can choose when setting up a client or a manpower request. How
            important it is, and the values to meet, are set there.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-4" noValidate>
          <FormGrid>
            <Field label="Name" htmlFor="criteria_name" required error={errors.criteria_name?.[0]}>
              <Input
                value={form.criteria_name}
                onChange={(e) => setForm({ ...form, criteria_name: e.target.value })}
                placeholder="e.g. Typing Speed"
                maxLength={120}
                autoFocus
              />
            </Field>

            <Field
              label="Code"
              htmlFor="criteria_code"
              required={!editing}
              error={errors.criteria_code?.[0]}
              hint={
                editing
                  ? 'Cannot be changed after the requirement is added.'
                  : 'The applicant field it reads. Lowercase letters and underscores only.'
              }
            >
              <Input
                value={form.criteria_code}
                disabled={editing}
                onChange={(e) =>
                  setForm({ ...form, criteria_code: e.target.value.toLowerCase().replace(/[^a-z_]/g, '_') })
                }
                placeholder="typing_speed"
                maxLength={60}
              />
            </Field>
          </FormGrid>

          <Field label="What it measures" htmlFor="description" required error={errors.description?.[0]}>
            <Input
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
              placeholder="Shown to HR when they choose it"
              maxLength={255}
            />
          </Field>

          <FormGrid>
            <Field label="How it counts" htmlFor="criteria_type" required hint={scoring?.hint} error={errors.criteria_type?.[0]}>
              <Select value={form.criteria_type} onChange={(e) => setForm({ ...form, criteria_type: e.target.value })}>
                {SCORING_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            </Field>

            <Field
              label="Better when"
              htmlFor="score_direction"
              required
              hint="Which end of a number range earns full points. Distance, for example, is lower is better."
              error={errors.score_direction?.[0]}
            >
              <Select value={form.score_direction} onChange={(e) => setForm({ ...form, score_direction: e.target.value })}>
                <option value="higher_better">Higher is better</option>
                <option value="lower_better">Lower is better</option>
              </Select>
            </Field>
          </FormGrid>

          <Field
            label="Value type"
            htmlFor="value_type"
            required={!editing}
            hint={editing ? `${valueOption?.hint ?? ''} Cannot be changed after the requirement is added.` : valueOption?.hint}
            error={errors.value_type?.[0]}
          >
            <Select
              value={form.value_type}
              disabled={editing}
              onChange={(e) => setForm({ ...form, value_type: e.target.value })}
            >
              {VALUE_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </Field>

          {scoringChanged && inUse > 0 && (
            <div className="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/5 p-3">
              <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
              <p className="text-xs text-muted-foreground">
                In use on {usageText(criterion)}. The change applies to each of those requests the next
                time it is evaluated.
              </p>
            </div>
          )}

          {!editing && (
            <div className="rounded-md border border-warning/40 bg-warning/5 p-3">
              <p className="text-xs text-muted-foreground">
                Matching reads this requirement from the applicant field named by the code. If no
                such field is recorded, nobody can meet it &mdash; and as Always Must Have, every
                applicant would be not eligible.
              </p>
            </div>
          )}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" disabled={saving || (editing && changed.length === 0)}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              {editing ? 'Save changes' : 'Add job requirement'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/**
 * A setting's input: text for text, otherwise a number box that takes only
 * the kind of number the setting holds. None of them can be negative.
 */
function SettingInput({ type, ...props }) {
  if (type === 'string') return <Input type="text" {...props} />

  return <NumberInput min="0" step={type === 'decimal' ? '0.05' : '1'} {...props} />
}

/** Inputs return strings; the API expects the declared type. */
function coerce(value, type) {
  // Number(), not parseInt: "1.5" stays 1.5 and "12abc" stays invalid, so
  // the server refuses them instead of quietly saving 1 or 12.
  if (type === 'integer' || type === 'decimal') return value === '' || value === null ? null : Number(value)
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
