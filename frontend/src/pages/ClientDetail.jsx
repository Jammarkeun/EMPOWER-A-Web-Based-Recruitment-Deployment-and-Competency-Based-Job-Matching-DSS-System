import * as React from 'react'
import { useParams, useSearchParams, Link } from 'react-router-dom'
import {
  Plus,
  Loader2,
  Building2,
  Users,
  ClipboardList,
  SlidersHorizontal,
  ChevronRight,
} from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { post, put } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Field, FormGrid } from '@/components/ui/form'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import DocumentFolders from '@/components/DocumentFolders'
import CriteriaValueEditor from '@/components/CriteriaValueEditor'
import { formatDate, cn } from '@/lib/utils'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

/**
 * One client company's workspace.
 *
 * Work at the agency starts by choosing a client, so this is where a day begins
 * rather than a reference page you visit occasionally. Everything about that
 * client sits behind one set of tabs: what they have asked for, who is placed
 * with them, what they require of applicants, and their documents.
 *
 * Applicants themselves are deliberately not scoped here. An applicant belongs
 * to the agency's pool and is matched to whichever client has a suitable
 * request — tying each one to a single company would undercut the matching the
 * whole system is built around. What appears here is the people actually placed
 * with this client.
 */
const TABS = ['activity', 'positions', 'requirements', 'departments', 'documents']

export default function ClientDetail() {
  const { id } = useParams()
  const { can } = useAuth()

  const company = useApi(`/clients/${id}`)
  const overview = useApi(`/clients/${id}/overview`)
  // Fetched separately from the company record because this is the call that
  // carries the headcounts, and they are the reason to open the tab.
  const departments = useApi(`/clients/${id}/departments`)
  const [dialogOpen, setDialogOpen] = React.useState(false)

  /*
   * The open tab lives in the URL.
   *
   * Without this, coming back from a department landed on Activity and the
   * reader had to find the Departments tab again — which is precisely the
   * "use the browser's back button and hope" experience a workspace should not
   * have. It also makes a particular tab something you can send to a colleague.
   */
  const [searchParams, setSearchParams] = useSearchParams()
  const requested = searchParams.get('tab')
  const tab = TABS.includes(requested) ? requested : 'activity'

  function selectTab(value) {
    const next = new URLSearchParams(searchParams)
    // Activity is the default, so it stays out of the URL rather than
    // decorating every link with ?tab=activity.
    if (value === 'activity') next.delete('tab')
    else next.set('tab', value)
    setSearchParams(next, { replace: true })
  }

  if (company.loading) return <LoadingState label="Loading company…" />
  if (company.error) return <ErrorState message={company.error.message} onRetry={company.refetch} />
  if (!company.data) return null

  const data = company.data
  const summary = overview.data?.summary

  return (
    <>
      <PageHeader
        breadcrumbs={[{ label: 'Client companies', to: '/clients' }, { label: data.company_name }]}
        title={data.company_name}
        description={`${data.business_type} · ${data.office_address}`}
        actions={
          can('clients.update') && (
            <Button variant="outline" onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Add department
            </Button>
          )
        }
      />

      <div className="mb-5 flex flex-wrap items-center gap-2">
        <StatusBadge status={data.status} />
        <span className="font-mono text-xs text-muted-foreground">{data.company_code}</span>
      </div>

      {/* The client at a glance, so the first question — how much is
          outstanding for them — is answered without opening a tab. */}
      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <SummaryTile
          label="Open requests"
          value={summary?.open_requests}
          hint={summary ? `${summary.positions_to_fill} workers still needed` : null}
          icon={ClipboardList}
          to="/job-requests"
        />
        <SummaryTile label="Placed with them" value={summary?.deployed_staff} icon={Users} to="/employees" />
        <SummaryTile label="Departments" value={summary?.departments} icon={Building2} />
        <SummaryTile
          label="Requirements set"
          value={summary?.requirements_set}
          hint={summary?.requirements_set ? 'Applied to new requests' : 'None configured yet'}
          icon={SlidersHorizontal}
        />
      </div>

      <Tabs value={tab} onValueChange={selectTab}>
        <TabsList>
          <TabsTrigger value="activity">Activity</TabsTrigger>
          <TabsTrigger value="positions">Positions</TabsTrigger>
          <TabsTrigger value="requirements">Requirements</TabsTrigger>
          <TabsTrigger value="departments">Departments</TabsTrigger>
          <TabsTrigger value="documents">Documents</TabsTrigger>
        </TabsList>

        <TabsContent value="activity">
          <CompanyActivity overview={overview} />
        </TabsContent>

        <TabsContent value="positions">
          <CompanyPositions clientId={id} canEdit={can('clients.update')} />
        </TabsContent>

        <TabsContent value="requirements">
          <CompanyRequirements clientId={id} onSaved={overview.refetch} />
        </TabsContent>

        <TabsContent value="departments">
          <CompanyDepartments
            clientId={id}
            query={departments}
            canEdit={can('clients.update')}
            canViewEmployees={can('employees.view')}
            onAdd={() => setDialogOpen(true)}
          />
        </TabsContent>

        <TabsContent value="documents">
          <DocumentFolders clientCompanyId={id} />
        </TabsContent>
      </Tabs>

      <NewDepartmentDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        clientId={id}
        onCreated={() => {
          // Creating a department changes this tab's collection. The company
          // and activity panels do not need to be refetched for that mutation.
          departments.refetch()
        }}
      />
    </>
  )
}

function SummaryTile({ label, value, hint, icon: Icon, to }) {
  const content = (
    <div className="rounded-xl border bg-card p-4 shadow-sm transition-colors hover:border-primary/30">
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        {Icon && <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />}
      </div>
      <p className="mt-2 text-2xl font-semibold tracking-tight">
        {value ?? <span className="text-muted-foreground">—</span>}
      </p>
      {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}
    </div>
  )

  return to ? <Link to={to}>{content}</Link> : content
}

function CompanyActivity({ overview }) {
  if (overview.loading) return <LoadingState label="Loading activity…" />
  if (overview.error) return <ErrorState message={overview.error.message} onRetry={overview.refetch} />
  if (!overview.data) return null

  const { requests, deployed } = overview.data

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Manpower requests</CardTitle>
          <CardDescription>What this client has asked for.</CardDescription>
        </CardHeader>
        <CardContent>
          {!requests.length ? (
            <EmptyState title="No requests yet" description="Requests from this client appear here." />
          ) : (
            <ul className="divide-y">
              {requests.map((request) => (
                <li key={request.id}>
                  <Link
                    to={`/job-requests/${request.id}`}
                    className="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 transition-colors hover:bg-accent/40"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{request.position_title}</p>
                      <p className="truncate text-xs text-muted-foreground">
                        {request.department ?? 'No department'}
                        {request.deadline ? ` · due ${formatDate(request.deadline)}` : ''}
                      </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-3">
                      <span className="text-sm tabular-nums">
                        {request.workers_fulfilled}/{request.workers_needed}
                      </span>
                      <StatusBadge status={request.request_status} />
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Placed with this client</CardTitle>
          <CardDescription>Workers currently deployed here.</CardDescription>
        </CardHeader>
        <CardContent>
          {!deployed.length ? (
            <EmptyState
              title="Nobody placed yet"
              description="Deploying an applicant against one of this client's requests adds them here."
            />
          ) : (
            <ul className="divide-y">
              {deployed.map((person) => (
                <li key={person.deployment_id}>
                  <Link
                    to={`/employees/${person.employee_id}`}
                    className="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 transition-colors hover:bg-accent/40"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{person.name}</p>
                      <p className="truncate text-xs text-muted-foreground">
                        {person.position ?? 'No position recorded'}
                        {person.department ? ` · ${person.department}` : ''}
                      </p>
                    </div>
                    <span className="shrink-0 text-xs text-muted-foreground">
                      {person.deployment_date ? formatDate(person.deployment_date) : ''}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

/**
 * The client's departments, each a way in to the people placed there.
 *
 * A department used to be a line of text. The first thing a client asks about
 * one is who is in it, and answering that meant leaving for the employee list
 * and filtering it by hand — so the row now carries its headcount and opens the
 * department.
 *
 * The whole row is the link rather than only a "View" button. A row that
 * responds to a click everywhere is easier to hit on a phone, and the visible
 * button is kept anyway because a row that happens to be clickable is not
 * discoverable on a desktop where nothing invites the click.
 */
function CompanyDepartments({ clientId, query, canEdit, canViewEmployees, onAdd }) {
  const { data, loading, error, refetch } = query

  if (loading) return <LoadingState label="Loading departments…" />
  if (error) {
    return (
      <Card>
        <ErrorState message="Unable to load departments. Please try again." onRetry={refetch} />
      </Card>
    )
  }

  const departments = data ?? []

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Departments</CardTitle>
        <CardDescription>
          Requests are raised against a department, so a deployment record shows exactly where a
          worker was placed. Open one to see who is working there.
        </CardDescription>
      </CardHeader>

      <CardContent>
        {departments.length === 0 ? (
          <EmptyState
            icon={Building2}
            title="No departments yet"
            description="Add the departments this client places workers in."
            action={
              canEdit ? (
                <Button size="sm" onClick={onAdd}>
                  Add department
                </Button>
              ) : null
            }
          />
        ) : (
          <ul className="divide-y">
            {departments.map((department) => {
              const headcount = department.employees_count ?? 0
              const former = department.former_employees_count ?? 0

              const row = (
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">{department.department_name}</p>
                    <p className="font-mono text-xs text-muted-foreground">
                      {department.department_code}
                    </p>
                  </div>

                  <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Users className="h-3.5 w-3.5" aria-hidden="true" />
                    <span>
                      {headcount} {headcount === 1 ? 'employee' : 'employees'}
                    </span>
                    {former > 0 && <span className="hidden sm:inline">· {former} former</span>}
                  </div>

                  <span className="hidden text-xs text-muted-foreground sm:inline">
                    {department.job_requests_count ?? 0} requests
                  </span>

                  <StatusBadge status={department.status} />

                  {canViewEmployees && (
                    <span className="inline-flex items-center gap-1 text-sm font-medium text-primary">
                      View
                      <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
                    </span>
                  )}
                </div>
              )

              return (
                <li key={department.id}>
                  {canViewEmployees ? (
                    <Link
                      to={`/clients/${clientId}/departments/${department.id}`}
                      // The accessible name says which department, since "View"
                      // repeated down a list tells a screen reader nothing.
                      aria-label={`View employees in ${department.department_name}`}
                      className="-mx-2 block rounded-md px-2 transition-colors hover:bg-accent"
                    >
                      {row}
                    </Link>
                  ) : (
                    row
                  )}
                </li>
              )
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

/**
 * The roles this client hires for.
 *
 * This is the list applicants choose from when they apply, and saying so on the
 * screen matters: adding a position here is not filing, it is publishing. The
 * agency intends to sign more clients, and every one of them will have its own
 * roles — which is why positions belong to a company rather than to a fixed list
 * somewhere in the code.
 *
 * A role no longer being hired for is withdrawn rather than deleted. The
 * requests and deployments that reference it are history and have to keep
 * reading correctly, and a client that stops hiring welders this year may want
 * them again next.
 */
function CompanyPositions({ clientId, canEdit }) {
  const toast = useToast()
  const { data, loading, error, refetch, setData } = useApi(`/clients/${clientId}/positions`)

  const [title, setTitle] = React.useState('')
  const [description, setDescription] = React.useState('')
  const [adding, setAdding] = React.useState(false)
  const [errors, setErrors] = React.useState({})
  const [busyId, setBusyId] = React.useState(null)

  if (loading) return <LoadingState label="Loading positions…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />

  const positions = data?.positions ?? []

  async function add(event) {
    event.preventDefault()

    if (adding) return

    setAdding(true)
    setErrors({})

    try {
      const response = await post(`/clients/${clientId}/positions`, {
        position_title: title.trim(),
        description: description.trim() || null,
      })

      // Appended in place rather than refetched, so the list does not jump and
      // whatever was being read stays where it was.
      setData((current) => ({
        ...current,
        positions: [...(current?.positions ?? []), response.data].sort((a, b) =>
          a.position_title.localeCompare(b.position_title)
        ),
      }))

      setTitle('')
      setDescription('')
      toast.success('Position added', response.message)
    } catch (err) {
      if (err.isValidation) {
        setErrors(err.errors)
      } else {
        toast.error('Could not add the position', err.message)
      }
    } finally {
      setAdding(false)
    }
  }

  async function setStatus(position, status) {
    setBusyId(position.id)

    try {
      const response = await put(`/clients/${clientId}/positions/${position.id}`, { status })

      setData((current) => ({
        ...current,
        positions: current.positions.map((row) =>
          row.id === position.id ? { ...row, ...response.data } : row
        ),
      }))

      toast.success(status === 'active' ? 'Position restored' : 'Position withdrawn', response.message)
    } catch (err) {
      toast.error('Could not update the position', err.message)
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Positions</CardTitle>
          <CardDescription>
            Applicants choose from this list when they apply. Adding a position here puts it on the
            application form straight away.
          </CardDescription>
        </CardHeader>

        <CardContent>
          {positions.length === 0 ? (
            <EmptyState
              icon={ClipboardList}
              title="No positions yet"
              description="Add the roles this client hires for so applicants can apply for them by name."
            />
          ) : (
            <ul className="divide-y">
              {positions.map((position) => (
                <li key={position.id} className="flex flex-wrap items-center gap-3 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">{position.position_title}</p>
                    <p className="text-xs text-muted-foreground">
                      {position.description || 'No description'}
                      {position.open_request_count > 0
                        ? ` · ${position.open_request_count} open request${position.open_request_count === 1 ? '' : 's'}`
                        : ''}
                      {position.applicant_count > 0
                        ? ` · ${position.applicant_count} applicant${position.applicant_count === 1 ? '' : 's'}`
                        : ''}
                    </p>
                  </div>

                  <Badge tone={position.status === 'active' ? 'success' : 'muted'}>
                    {position.status === 'active' ? 'On the form' : 'Withdrawn'}
                  </Badge>

                  {canEdit && (
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={busyId === position.id}
                      onClick={() =>
                        setStatus(position, position.status === 'active' ? 'inactive' : 'active')
                      }
                    >
                      {busyId === position.id && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
                      {position.status === 'active' ? 'Withdraw' : 'Restore'}
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      {canEdit && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Add a position</CardTitle>
            <CardDescription>
              Raising a manpower request for a role the client has not asked for before also creates
              it, so this is only needed to add one ahead of time.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={add} className="space-y-3" noValidate>
              <FormGrid>
                <Field
                  label="Position title"
                  htmlFor="position_title"
                  required
                  error={errors.position_title?.[0]}
                >
                  <Input
                    id="position_title"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="e.g. Machine Operator"
                    required
                  />
                </Field>
                <Field
                  label="Description"
                  htmlFor="position_description"
                  error={errors.description?.[0]}
                  hint="Shown to applicants. One line is plenty."
                >
                  <Input
                    id="position_description"
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    placeholder="What the role involves"
                  />
                </Field>
              </FormGrid>

              <div className="flex justify-end">
                <Button type="submit" disabled={adding || !title.trim()}>
                  {adding ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                  {adding ? 'Adding…' : 'Add position'}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      )}
    </div>
  )
}

/**
 * Adjust Criteria — what this client asks for as standard.
 *
 * The whole catalogue is shown with a tick beside what is in use, rather than
 * only the chosen entries: otherwise you would have to already know a
 * requirement existed before you could find it and switch it on.
 */
function CompanyRequirements({ clientId, onSaved }) {
  const { can } = useAuth()
  const toast = useToast()
  const { data, loading, error, refetch } = useApi(`/clients/${clientId}/criteria`)

  const [rows, setRows] = React.useState([])
  const [saving, setSaving] = React.useState(false)

  React.useEffect(() => {
    if (data?.criteria) setRows(data.criteria)
  }, [data])

  if (loading) return <LoadingState label="Loading requirements…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />

  const mayEdit = can('clients.update')
  const inUse = rows.filter((r) => r.in_use)
  const totalWeight = inUse.reduce((sum, r) => sum + (Number(r.weight_score) || 0), 0)

  function update(id, changes) {
    setRows((current) => current.map((r) => (r.criteria_id === id ? { ...r, ...changes } : r)))
  }

  async function save() {
    setSaving(true)
    try {
      await put(`/clients/${clientId}/criteria`, {
        criteria: rows
          .filter((r) => r.in_use)
          .map((r) => ({
            criteria_id: r.criteria_id,
            mandatory_flag: !!r.mandatory_flag,
            weight_score: Number(r.weight_score) || 0,
            expected_value: r.expected_value || null,
            min_value: r.min_value === '' || r.min_value === null ? null : Number(r.min_value),
            max_value: r.max_value === '' || r.max_value === null ? null : Number(r.max_value),
            note: r.note || null,
          })),
      })
      toast.success('Requirements saved', 'New requests for this client will start from these.')
      onSaved?.()
      refetch()
    } catch (err) {
      if (err.isValidation) {
        toast.error('Check the values', 'Some requirements need correcting.')
      } else {
        toast.error('Could not save requirements', err.message)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <CardTitle className="text-base">What this client requires</CardTitle>
            <CardDescription>
              Tick what matters to this client and set how much each is worth. New requests for
              them start from these instead of a blank form.
            </CardDescription>
          </div>
          {mayEdit && (
            <Button onClick={save} disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              Save requirements
            </Button>
          )}
        </div>
      </CardHeader>

      <CardContent className="space-y-3">
        {inUse.length > 0 && (
          <p className="rounded-lg border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            {inUse.length} in use, {totalWeight} points allocated. Points are relative — what
            matters is how they compare, not that they add to a particular total.
          </p>
        )}

        {rows.map((row) => (
          <div
            key={row.criteria_id}
            className={cn(
              'rounded-lg border p-3 transition-colors',
              row.in_use ? 'border-primary/30 bg-primary/[0.03]' : 'bg-card'
            )}
          >
            <label className="flex cursor-pointer items-start gap-3">
              <input
                type="checkbox"
                checked={!!row.in_use}
                disabled={!mayEdit}
                onChange={(e) => update(row.criteria_id, { in_use: e.target.checked })}
                className="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-input accent-primary"
              />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-sm font-medium">{row.name}</p>
                  {row.criteria_type === 'hard_filter' && (
                    <Badge tone="warning" className="text-[10px]">
                      Must meet
                    </Badge>
                  )}
                </div>
                <p className="mt-0.5 text-xs text-muted-foreground">{row.description}</p>
              </div>
            </label>

            {row.in_use && (
              <div className="mt-3 grid gap-3 border-t pt-3 sm:grid-cols-2 lg:grid-cols-4">
                {row.criteria_type !== 'hard_filter' && (
                  <Field label="Points" htmlFor={`w-${row.criteria_id}`}>
                    <Input
                      type="number"
                      min="0"
                      max="100"
                      value={row.weight_score ?? 0}
                      disabled={!mayEdit}
                      onChange={(e) => update(row.criteria_id, { weight_score: e.target.value })}
                    />
                  </Field>
                )}

                {row.value_type === 'number' && (
                  <>
                    <Field label="Minimum" htmlFor={`min-${row.criteria_id}`}>
                      <Input
                        type="number"
                        value={row.min_value ?? ''}
                        disabled={!mayEdit}
                        onChange={(e) => update(row.criteria_id, { min_value: e.target.value })}
                      />
                    </Field>
                    <Field label="Maximum" htmlFor={`max-${row.criteria_id}`}>
                      <Input
                        type="number"
                        value={row.max_value ?? ''}
                        disabled={!mayEdit}
                        onChange={(e) => update(row.criteria_id, { max_value: e.target.value })}
                      />
                    </Field>
                  </>
                )}

                {/*
                  The same editor the request form uses, rather than a text box
                  asking the officer to type "high_school" exactly. An
                  unrecognised value is not rejected — it simply never matches —
                  so guessing the spelling produced a criterion that scored
                  nobody and gave no reason.
                */}
                {row.accepts && row.accepts !== 'none' && (
                  <div className={row.accepts === 'list' ? 'sm:col-span-2' : undefined}>
                    <CriteriaValueEditor
                      accepts={mayEdit ? row.accepts : 'none'}
                      options={row.options}
                      value={row.expected_value ?? ''}
                      onChange={(next) => update(row.criteria_id, { expected_value: next ?? '' })}
                      label={row.accepts === 'list' ? 'What this client requires' : 'Required level'}
                    />

                    {/* Read-only for anyone without edit rights: the values
                        still matter to them, the controls do not. */}
                    {!mayEdit && (
                      <p className="mt-1 text-xs text-muted-foreground">
                        {row.expected_value || 'Nothing set'}
                      </p>
                    )}
                  </div>
                )}

                <Field
                  label="Why"
                  htmlFor={`note-${row.criteria_id}`}
                  className="sm:col-span-2 lg:col-span-1"
                >
                  <Input
                    value={row.note ?? ''}
                    disabled={!mayEdit}
                    placeholder="Optional note"
                    onChange={(e) => update(row.criteria_id, { note: e.target.value })}
                  />
                </Field>
              </div>
            )}
          </div>
        ))}
      </CardContent>
    </Card>
  )
}


function NewDepartmentDialog({ open, onOpenChange, clientId, onCreated }) {
  const [form, setForm] = React.useState({ department_code: '', department_name: '' })
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setForm({ department_code: '', department_name: '' })
      setErrors({})
    }
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setSubmitting(true)

    try {
      await post(`/clients/${clientId}/departments`, form)
      toast.success('Department added')
      onOpenChange(false)
      onCreated()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not add department', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add department</DialogTitle>
          <DialogDescription>
            Names are unique per client, so several companies can each have a "Packaging" department.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <FormGrid>
            <Field label="Department name" htmlFor="department_name" required error={errors.department_name?.[0]}>
              <Input
                value={form.department_name}
                onChange={(e) => setForm({ ...form, department_name: e.target.value })}
                placeholder="e.g. Production"
                autoFocus
              />
            </Field>

            <Field
              label="Short code"
              htmlFor="department_code"
              required
              error={errors.department_code?.[0]}
              hint="Used on request codes"
            >
              <Input
                value={form.department_code}
                onChange={(e) => setForm({ ...form, department_code: e.target.value.toUpperCase() })}
                placeholder="e.g. PROD"
              />
            </Field>
          </FormGrid>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Add department
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
