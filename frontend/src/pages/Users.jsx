import * as React from 'react'
import { Plus, Search, UserCog, Loader2, KeyRound, Power, Copy, Check } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { get, post, patch } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
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
import { formatDateTime, humanise } from '@/lib/utils'

export default function Users() {
  const [search, setSearch] = React.useState('')
  const [userType, setUserType] = React.useState('')
  const [createOpen, setCreateOpen] = React.useState(false)
  const debouncedSearch = useDebounced(search)

  const { data, loading, error, refetch } = useApi('/users', {
    search: debouncedSearch || undefined,
    user_type: userType || undefined,
    per_page: 50,
  })

  return (
    <>
      <PageHeader
        title="Users and access"
        description="System accounts, roles, and portal access for applicants and employees."
        actions={
          <Button onClick={() => setCreateOpen(true)}>
            <Plus className="h-4 w-4" />
            Add staff user
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
                <option value="applicant">Applicant portal</option>
                <option value="employee">Employee portal</option>
              </Select>
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
  const toast = useToast()

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
          <Badge tone={user.user_type === 'admin' ? 'info' : 'muted'}>{humanise(user.user_type)}</Badge>
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
    </>
  )
}

function CreateUserDialog({ open, onOpenChange, onCreated }) {
  const [form, setForm] = React.useState(empty())
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setForm(empty())
      setErrors({})
    }
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setSubmitting(true)

    try {
      await post('/users', form)
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
          <DialogTitle>Add staff user</DialogTitle>
          <DialogDescription>
            For administrator and HR accounts. Portal logins for applicants and employees are created
            from their own record, so the account is always linked to the right person.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <FormGrid>
            <Field label="First name" htmlFor="first_name" required error={errors.first_name?.[0]}>
              <Input value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} autoFocus />
            </Field>
            <Field label="Last name" htmlFor="last_name" required error={errors.last_name?.[0]}>
              <Input value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} />
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
              </Select>
            </Field>
            <Field label="Role" htmlFor="role" required error={errors.role?.[0]}>
              <Select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
                <option value="hr">HR</option>
                <option value="admin">Administrator</option>
              </Select>
            </Field>
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

function ResetPasswordDialog({ open, onOpenChange, user }) {
  const [password, setPassword] = React.useState('')
  const [confirmation, setConfirmation] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setPassword('')
      setConfirmation('')
      setErrors({})
    }
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)

    try {
      await post(`/users/${user.id}/reset-password`, {
        password,
        password_confirmation: confirmation,
      })
      toast.success('Password reset', 'They have been signed out everywhere.')
      onOpenChange(false)
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not reset password', error.message)
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
            This signs them out of every device immediately. Give them the new password directly —
            it is not emailed.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Field label="New password" htmlFor="password" required error={errors.password?.[0]} hint="At least 10 characters">
            <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoFocus />
          </Field>
          <Field label="Confirm password" htmlFor="password_confirmation" required>
            <Input type="password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Reset password
            </Button>
          </DialogFooter>
        </form>
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

/**
 * Creates a portal login for an applicant or employee.
 *
 * Exported so it can be opened from the applicant and employee detail screens,
 * where the person is already in context.
 */
export function PortalAccessDialog({ open, onOpenChange, applicantId, employeeId, personName }) {
  const [email, setEmail] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const [created, setCreated] = React.useState(null)
  const [copied, setCopied] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setEmail('')
      setErrors({})
      setCreated(null)
      setCopied(false)
    }
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)

    try {
      const response = await post('/users/portal-access', {
        applicant_id: applicantId,
        employee_id: employeeId,
        email,
      })
      setCreated(response.data)
      toast.success('Portal access created', `${personName} can now sign in with ${email}.`)
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not create portal access', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Portal access for {personName}</DialogTitle>
          <DialogDescription>
            Lets them check their own application status and documents, which removes most of the
            phone calls asking for an update.
          </DialogDescription>
        </DialogHeader>

        {created ? (
          <div className="space-y-4">
            <div className="rounded-md border border-success/40 bg-success/5 p-4">
              <p className="text-sm font-medium">Account created</p>
              <p className="mt-1 text-sm text-muted-foreground">{created.notice}</p>

              <div className="mt-3 space-y-2">
                <div>
                  <p className="text-xs text-muted-foreground">Email</p>
                  <p className="font-mono text-sm">{created.user.email}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Temporary password</p>
                  <div className="flex items-center gap-2">
                    <code className="rounded bg-muted px-2 py-1 font-mono text-sm">
                      {created.temporary_password}
                    </code>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => {
                        navigator.clipboard.writeText(created.temporary_password)
                        setCopied(true)
                        setTimeout(() => setCopied(false), 2000)
                      }}
                    >
                      {copied ? <Check className="h-3.5 w-3.5 text-success" /> : <Copy className="h-3.5 w-3.5" />}
                      {copied ? 'Copied' : 'Copy'}
                    </Button>
                  </div>
                </div>
              </div>
            </div>

            <DialogFooter>
              <Button onClick={() => onOpenChange(false)}>Done</Button>
            </DialogFooter>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4" noValidate>
            <Field
              label="Email address"
              htmlFor="portal_email"
              required
              error={errors.email?.[0]}
              hint="They sign in with this address"
            >
              <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoFocus />
            </Field>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
                Create access
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  )
}

function empty() {
  return {
    first_name: '',
    last_name: '',
    email: '',
    user_type: 'hr',
    role: 'hr',
    password: '',
    password_confirmation: '',
  }
}
