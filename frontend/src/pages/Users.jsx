import * as React from 'react'
import { Plus, Search, UserCog, Loader2, KeyRound, Power, Copy, Check, ArrowLeftRight, History } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { get, post, patch } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Input, NameInput } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { formProblems } from '@/lib/validation'
import { Field, FormGrid } from '@/components/ui/form'
import { EmptyState, ErrorState, SkeletonRows, LoadingState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { formatDate, formatDateTime, humanise } from '@/lib/utils'
import { Pagination, useClampPage } from '@/components/Pagination'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import { TemporaryPassword } from '@/components/TemporaryPassword'

export default function Users() {
  const [search, setSearch] = React.useState('')
  const [userType, setUserType] = React.useState('')
  const [page, setPage] = React.useState(1)
  const [createOpen, setCreateOpen] = React.useState(false)
  const debouncedSearch = useDebounced(search)

  // Paged like every other list. It used to ask for one page of fifty with
  // no way to reach the next: sorted by account type, the applicant logins
  // filled it and every HR and coordinator account fell off the end.
  React.useEffect(() => setPage(1), [debouncedSearch, userType])

  const { data, meta, loading, error, refetch } = useApi('/users', {
    search: debouncedSearch || undefined,
    user_type: userType || undefined,
    page,
    per_page: 50,
  })

  useClampPage(meta, setPage)

  return (
    <>
      <PageHeader
        title="Users and access"
        description="System accounts, roles, coordinator assignments, and portal access for applicants and employees."
        actions={
          <Button onClick={() => setCreateOpen(true)}>
            <Plus className="h-4 w-4" />
            Add user
          </Button>
        }
      />

      <Tabs defaultValue="accounts">
        <TabsList>
          <TabsTrigger value="accounts">Accounts</TabsTrigger>
          <TabsTrigger value="permissions">Roles &amp; permissions</TabsTrigger>
        </TabsList>

        <TabsContent value="accounts">
          <Card className="mb-4">
            <CardContent className="flex flex-col gap-3 pt-5 sm:flex-row">
              <div className="relative flex-1">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search by name or email"
                  className="pl-9"
                  aria-label="Search users"
                />
              </div>
              <Select
                value={userType}
                onChange={(e) => setUserType(e.target.value)}
                placeholder="All account types"
                aria-label="Filter by account type"
                className="sm:w-52"
              >
                <option value="admin">Administrator</option>
                <option value="hr">HR staff</option>
                <option value="coordinator">Coordinator</option>
                <option value="applicant">Applicant portal</option>
                <option value="employee">Employee portal</option>
              </Select>
              {meta?.total !== undefined && (
                <span className="self-center whitespace-nowrap text-xs text-muted-foreground tabular-nums">
                  {meta.total} account{meta.total === 1 ? '' : 's'}
                </span>
              )}
            </CardContent>
          </Card>

          <Card>
            {loading ? (
              <SkeletonRows rows={6} columns={5} />
            ) : error ? (
              <ErrorState message={error.message} onRetry={refetch} />
            ) : !data?.length ? (
              <EmptyState icon={UserCog} title="No accounts match" />
            ) : (
              <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Email</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead>Last signed in</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.map((user) => (
                    <UserRow key={user.id} user={user} onChanged={refetch} />
                  ))}
                </TableBody>
              </Table>
              <div className="px-4 pb-3">
                <Pagination meta={meta} onPage={setPage} />
              </div>
              </>
            )}
          </Card>
        </TabsContent>

        <TabsContent value="permissions">
          <PermissionMatrix />
        </TabsContent>
      </Tabs>

      <CreateUserDialog open={createOpen} onOpenChange={setCreateOpen} onCreated={refetch} />
    </>
  )
}

function UserRow({ user, onChanged }) {
  const [busy, setBusy] = React.useState(false)
  const [resetOpen, setResetOpen] = React.useState(false)
  const [assignOpen, setAssignOpen] = React.useState(false)
  const [historyOpen, setHistoryOpen] = React.useState(false)
  const toast = useToast()
  const isCoordinator = user.user_type === 'coordinator'

  async function toggleActive() {
    setBusy(true)
    try {
      const response = await patch(`/users/${user.id}/status`, { is_active: !user.is_active })
      toast.success(response.message)
      onChanged()
    } catch (error) {
      toast.error('Could not change account status', error.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <TableRow className={user.is_active ? '' : 'opacity-60'}>
        <TableCell className="font-medium">{user.full_name}</TableCell>
        <TableCell className="text-sm text-muted-foreground">{user.email}</TableCell>
        <TableCell>
          {/* A portal login is named by the portal it opens: one made at
              registration still says "applicant" after deployment, and opens
              onto the person's employment. */}
          <Badge tone={user.user_type === 'admin' ? 'info' : 'muted'}>{humanise(user.portal_access ?? user.user_type)}</Badge>
          {/* A coordinator's client is their whole scope, so it is shown
              wherever the account is. */}
          {isCoordinator && (
            <p className="mt-1 text-xs text-muted-foreground">
              {user.coordinator_assignment?.company_name ?? 'No client assigned'}
            </p>
          )}
        </TableCell>
        <TableCell className="text-sm">{user.roles?.join(', ') || '—'}</TableCell>
        <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
          {user.last_login_at ? formatDateTime(user.last_login_at) : 'Never'}
        </TableCell>
        <TableCell>
          <StatusBadge status={user.is_active ? 'active' : 'archived'} label={user.is_active ? 'Active' : 'Deactivated'} />
        </TableCell>
        <TableCell className="text-right">
          <div className="flex justify-end gap-1">
            {isCoordinator && (
              <>
                <Button variant="ghost" size="sm" onClick={() => setAssignOpen(true)}>
                  <ArrowLeftRight className="h-3.5 w-3.5" />
                  {user.coordinator_assignment ? 'Reassign' : 'Assign'}
                </Button>
                <Button variant="ghost" size="sm" onClick={() => setHistoryOpen(true)} aria-label="Assignment history">
                  <History className="h-3.5 w-3.5" />
                </Button>
              </>
            )}
            <Button variant="ghost" size="sm" onClick={() => setResetOpen(true)}>
              <KeyRound className="h-3.5 w-3.5" />
              Reset
            </Button>
            <Button variant="ghost" size="sm" onClick={toggleActive} disabled={busy}>
              {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Power className="h-3.5 w-3.5" />}
              {user.is_active ? 'Disable' : 'Enable'}
            </Button>
          </div>
        </TableCell>
      </TableRow>

      <ResetPasswordDialog open={resetOpen} onOpenChange={setResetOpen} user={user} />
      {isCoordinator && (
        <>
          <AssignCoordinatorDialog open={assignOpen} onOpenChange={setAssignOpen} user={user} onDone={onChanged} />
          <AssignmentHistoryDialog open={historyOpen} onOpenChange={setHistoryOpen} user={user} />
        </>
      )}
    </>
  )
}

/**
 * Moves a coordinator to another client.
 *
 * Confirmed first, because the effect is immediate: from their next request
 * the coordinator sees the new client only, and the old one is out of reach.
 * Their reports for the old client stay where they are.
 */
function AssignCoordinatorDialog({ open, onOpenChange, user, onDone }) {
  const toast = useToast()
  const companies = useApi('/clients', { status: 'active', per_page: 100 }, { enabled: open })
  const [companyId, setCompanyId] = React.useState('')
  const [remarks, setRemarks] = React.useState('')
  const [confirming, setConfirming] = React.useState(false)
  const [error, setError] = React.useState('')

  React.useEffect(() => {
    if (open) {
      setCompanyId('')
      setRemarks('')
      setError('')
      setConfirming(false)
    }
  }, [open])

  const target = (companies.data ?? []).find((c) => String(c.id) === companyId)

  async function assign() {
    try {
      const response = await post(`/users/${user.id}/coordinator-assignment`, {
        client_company_id: companyId,
        remarks: remarks || null,
      })
      toast.success(response.message, `${user.full_name} now covers ${target?.company_name}.`)
      setConfirming(false)
      onOpenChange(false)
      onDone()
    } catch (err) {
      setConfirming(false)
      setError(err.errors?.client_company_id?.[0] ?? err.message)
    }
  }

  return (
    <>
      <Dialog open={open && !confirming} onOpenChange={onOpenChange}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>{user.coordinator_assignment ? 'Reassign coordinator' : 'Assign coordinator'}</DialogTitle>
            <DialogDescription>
              {user.coordinator_assignment
                ? `${user.full_name} currently covers ${user.coordinator_assignment.company_name}.`
                : `${user.full_name} has no client yet.`}
            </DialogDescription>
          </DialogHeader>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              if (companyId) setConfirming(true)
            }}
            noValidate
          >
            <Field label="Client company" htmlFor="assign_company" required error={error}>
              <Select value={companyId} onChange={(e) => setCompanyId(e.target.value)} placeholder="Choose a client">
                {(companies.data ?? [])
                  .filter((c) => c.id !== user.coordinator_assignment?.client_company_id)
                  .map((c) => (
                    <option key={c.id} value={String(c.id)}>
                      {c.company_name}
                    </option>
                  ))}
              </Select>
            </Field>
            <Field label="Remarks" htmlFor="assign_remarks">
              <Input value={remarks} onChange={(e) => setRemarks(e.target.value)} placeholder="Optional" />
            </Field>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={!companyId}>
                Continue
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <ConfirmActionDialog
        open={open && confirming}
        onOpenChange={(next) => !next && setConfirming(false)}
        tone="warning"
        title={`Assign ${user.full_name} to ${target?.company_name ?? 'this client'}?`}
        description="Takes effect on their next request."
        consequences={[
          user.coordinator_assignment
            ? `Their access to ${user.coordinator_assignment.company_name} ends now.`
            : 'They gain access to this client now.',
          `They will see ${target?.company_name ?? 'the new client'}'s employees, deployments, and requests only.`,
          'Reports they made for a previous client stay with that client and are not changed.',
          'The change is kept in their assignment history and the audit trail.',
        ]}
        confirmLabel="Confirm assignment"
        onConfirm={assign}
      />
    </>
  )
}

function AssignmentHistoryDialog({ open, onOpenChange, user }) {
  const { data, loading, error } = useApi(`/users/${user.id}/coordinator-assignments`, undefined, { enabled: open })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Assignment history</DialogTitle>
          <DialogDescription>Every client {user.full_name} has covered, newest first.</DialogDescription>
        </DialogHeader>
        {loading ? (
          <LoadingState />
        ) : error ? (
          <p className="text-sm text-destructive">{error.message}</p>
        ) : (
          <ul className="divide-y">
            {(data ?? []).map((a) => (
              <li key={a.id} className="py-2.5">
                <div className="flex items-center justify-between gap-2">
                  <p className="text-sm font-medium">{a.company_name}</p>
                  <StatusBadge status={a.status === 'active' ? 'active' : 'closed'} label={a.status === 'active' ? 'Current' : 'Ended'} />
                </div>
                <p className="text-xs text-muted-foreground">
                  {formatDate(a.assigned_at)} – {a.unassigned_at ? formatDate(a.unassigned_at) : 'present'}
                  {a.assigned_by && ` · assigned by ${a.assigned_by}`}
                </p>
                {a.remarks && <p className="text-xs text-muted-foreground">{a.remarks}</p>}
              </li>
            ))}
          </ul>
        )}
      </DialogContent>
    </Dialog>
  )
}

function CreateUserDialog({ open, onOpenChange, onCreated }) {
  const [form, setForm] = React.useState(empty())
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()
  const isCoordinator = form.user_type === 'coordinator'
  const companies = useApi('/clients', { status: 'active', per_page: 100 }, { enabled: open && isCoordinator })

  React.useEffect(() => {
    if (open) {
      setForm(empty())
      setErrors({})
    }
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()

    // Named beside the field before sending; the server checks again.
    const problems = formProblems(form, { names: ['first_name', 'last_name'] })
    setErrors(problems)
    if (Object.keys(problems).length) return

    setSubmitting(true)

    try {
      await post('/users', {
        ...form,
        role: isCoordinator ? null : form.role,
        client_company_id: isCoordinator ? form.client_company_id : null,
      })
      toast.success('User created', `${form.first_name} ${form.last_name} can now sign in.`)
      onOpenChange(false)
      onCreated()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not create user', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add user</DialogTitle>
          <DialogDescription>
            For administrator, HR, and coordinator accounts. Portal logins for applicants and employees
            are created from their own record, so the account is always linked to the right person.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <FormGrid>
            <Field label="First name" htmlFor="first_name" required error={errors.first_name?.[0]}>
              <NameInput value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} autoFocus />
            </Field>
            <Field label="Last name" htmlFor="last_name" required error={errors.last_name?.[0]}>
              <NameInput value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} />
            </Field>
          </FormGrid>

          <Field label="Email address" htmlFor="email" required error={errors.email?.[0]}>
            <Input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          </Field>

          <FormGrid>
            <Field label="Account type" htmlFor="user_type" required error={errors.user_type?.[0]}>
              <Select
                value={form.user_type}
                onChange={(e) => setForm({ ...form, user_type: e.target.value, role: e.target.value })}
              >
                <option value="hr">HR staff</option>
                <option value="admin">Administrator</option>
                <option value="coordinator">Coordinator</option>
              </Select>
            </Field>
            {isCoordinator ? (
              // A coordinator's role is fixed; what varies is their client.
              <Field
                label="Assigned client company"
                htmlFor="client_company_id"
                required
                error={errors.client_company_id?.[0]}
                hint="They will see this client's records only."
              >
                <Select
                  value={form.client_company_id}
                  onChange={(e) => setForm({ ...form, client_company_id: e.target.value })}
                  placeholder="Choose a client"
                >
                  {(companies.data ?? []).map((c) => (
                    <option key={c.id} value={String(c.id)}>
                      {c.company_name}
                    </option>
                  ))}
                </Select>
              </Field>
            ) : (
              <Field label="Role" htmlFor="role" required error={errors.role?.[0]}>
                <Select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
                  <option value="hr">HR</option>
                  <option value="admin">Administrator</option>
                </Select>
              </Field>
            )}
          </FormGrid>

          <Field
            label="Password"
            htmlFor="password"
            required
            error={errors.password?.[0]}
            hint="At least 10 characters"
          >
            <Input
              type="password"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
            />
          </Field>

          <Field label="Confirm password" htmlFor="password_confirmation" required>
            <Input
              type="password"
              value={form.password_confirmation}
              onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
            />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Create user
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/**
 * Issuing a temporary password (audit SEC-07, decided 2026-10-19).
 *
 * The administrator no longer types the new password, so the office never
 * knows a working one: the system generates it and shows it here once, and
 * the holder must replace it at their next sign-in before anything else.
 */
function ResetPasswordDialog({ open, onOpenChange, user }) {
  const [submitting, setSubmitting] = React.useState(false)
  const [issued, setIssued] = React.useState(null)
  const toast = useToast()

  React.useEffect(() => {
    if (open) setIssued(null)
  }, [open])

  async function issue() {
    setSubmitting(true)
    try {
      const response = await post(`/users/${user.id}/reset-password`)
      setIssued(response.data)
      toast.success('Temporary password issued', 'They have been signed out everywhere.')
    } catch (error) {
      toast.error('Could not reset password', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Reset password for {user.full_name}</DialogTitle>
          <DialogDescription>
            {issued
              ? 'Shown once. Give it to them directly; it is not emailed.'
              : 'Confirm who is asking before you do this. They are signed out of every device, and given a temporary password that only lets them choose their own.'}
          </DialogDescription>
        </DialogHeader>

        {issued ? (
          <div className="space-y-4">
            <TemporaryPassword email={user.email} password={issued.temporary_password} notice={issued.notice} />
            <DialogFooter>
              <Button onClick={() => onOpenChange(false)}>Done</Button>
            </DialogFooter>
          </div>
        ) : (
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button onClick={issue} disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Issue temporary password
            </Button>
          </DialogFooter>
        )}
      </DialogContent>
    </Dialog>
  )
}

/**
 * The permission matrix, grouped by module.
 *
 * Around fifty permissions in one flat list is unreviewable; grouped by module
 * an administrator can actually check what each role can do.
 */
function PermissionMatrix() {
  const [data, setData] = React.useState(null)
  const [error, setError] = React.useState(null)

  React.useEffect(() => {
    get('/users/roles')
      .then((response) => setData(response.data))
      .catch(setError)
  }, [])

  if (error) return <ErrorState message={error.message} />
  if (!data) return <LoadingState />

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-3">
        {data.roles.map((role) => (
          <Card key={role.id}>
            <CardContent className="pt-5">
              <div className="flex items-start justify-between gap-2">
                <p className="font-medium capitalize">{role.name}</p>
                {role.is_system_role && <Badge tone="muted">System</Badge>}
              </div>
              <p className="mt-1 text-xs text-muted-foreground">{role.description}</p>
              <p className="mt-2 text-xs text-muted-foreground">
                {role.users_count} user{role.users_count === 1 ? '' : 's'} · {role.permissions_count} permissions
              </p>
            </CardContent>
          </Card>
        ))}
      </div>

      {data.permission_matrix.map((module) => (
        <Card key={module.module}>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm">{module.label}</CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Permission</TableHead>
                  <TableHead>What it allows</TableHead>
                  <TableHead className="w-24">Admin</TableHead>
                  <TableHead className="w-24">HR</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {module.permissions.map((permission) => (
                  <TableRow key={permission.id}>
                    <TableCell className="font-mono text-xs">{permission.name}</TableCell>
                    <TableCell className="text-sm text-muted-foreground">{permission.description}</TableCell>
                    <TableCell>
                      {permission.roles?.includes('admin') ? (
                        <Check className="h-4 w-4 text-success" aria-label="Granted" />
                      ) : (
                        <span className="text-muted-foreground" aria-label="Not granted">—</span>
                      )}
                    </TableCell>
                    <TableCell>
                      {permission.roles?.includes('hr') ? (
                        <Check className="h-4 w-4 text-success" aria-label="Granted" />
                      ) : (
                        <span className="text-muted-foreground" aria-label="Not granted">—</span>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

function empty() {
  return {
    first_name: '',
    last_name: '',
    email: '',
    user_type: 'hr',
    client_company_id: '',
    role: 'hr',
    password: '',
    password_confirmation: '',
  }
}
