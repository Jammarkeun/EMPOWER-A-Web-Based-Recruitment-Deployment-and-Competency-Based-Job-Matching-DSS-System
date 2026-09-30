import * as React from 'react'
import { useParams, Link } from 'react-router-dom'
import { Loader2, Plus, FileWarning, Truck, Clock, AlertTriangle } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { Field, FormGrid } from '@/components/ui/form'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn, formatDate, formatDateTime, humanise } from '@/lib/utils'

export default function EmployeeDetail() {
  const { id } = useParams()
  const { can } = useAuth()
  const { data, loading, error, refetch } = useApi(`/employees/${id}`)

  const [violationOpen, setViolationOpen] = React.useState(false)
  const [separationOpen, setSeparationOpen] = React.useState(false)

  if (loading) return <LoadingState label="Loading employee…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const { employee, status_history, resignation, termination } = data

  return (
    <>
      <PageHeader
        breadcrumbs={[{ label: 'Employees', to: '/employees' }, { label: employee.full_name }]}
        title={employee.full_name}
        description={
          <>
            <span className="font-mono">{employee.employee_number}</span>
            {employee.current_position_title && <> · {employee.current_position_title}</>}
            {employee.current_company && <> at {employee.current_company}</>}
          </>
        }
        actions={
          employee.employment_status === 'active' && (
            <>
              {can('violations.create') && (
                <Button variant="outline" onClick={() => setViolationOpen(true)}>
                  <FileWarning className="h-4 w-4" />
                  Record violation
                </Button>
              )}
              {can('separation.create') && (
                <Button variant="outline" onClick={() => setSeparationOpen(true)}>
                  File separation
                </Button>
              )}
            </>
          )
        }
      />

      <div className="mb-5 flex flex-wrap items-center gap-2">
        <StatusBadge status={employee.employment_status} />
        {employee.applicant_id && (
          <Button variant="link" size="sm" className="h-auto p-0" asChild>
            <Link to={`/applicants/${employee.applicant_id}`}>View recruitment record</Link>
          </Button>
        )}
      </div>

      <Tabs defaultValue="assignment">
        <TabsList>
          <TabsTrigger value="assignment">Assignment</TabsTrigger>
          <TabsTrigger value="violations">
            Discipline{employee.violations?.length ? ` (${employee.violations.length})` : ''}
          </TabsTrigger>
          <TabsTrigger value="history">History</TabsTrigger>
        </TabsList>

        <TabsContent value="assignment">
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>Current assignment</CardTitle>
              </CardHeader>
              <CardContent>
                <dl className="grid gap-3 sm:grid-cols-2">
                  {[
                    ['Client company', employee.current_company],
                    ['Department', employee.current_department],
                    ['Position', employee.current_position_title],
                    ['Supervisor', employee.current_supervisor_name],
                    ['Biometrics number', employee.biometric_number],
                    ['Hire date', formatDate(employee.hire_date)],
                  ]
                    .filter(([, v]) => v)
                    .map(([label, value]) => (
                      <div key={label}>
                        <dt className="text-xs text-muted-foreground">{label}</dt>
                        <dd className="text-sm">{value}</dd>
                      </div>
                    ))}
                </dl>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Deployment history</CardTitle>
                <CardDescription>Every placement, including reassignments.</CardDescription>
              </CardHeader>
              <CardContent>
                {!employee.deployments?.length ? (
                  <EmptyState icon={Truck} title="No deployments recorded" />
                ) : (
                  <ul className="space-y-3">
                    {employee.deployments.map((deployment) => (
                      <li key={deployment.id} className="rounded-md border p-3">
                        <div className="flex items-start justify-between gap-2">
                          <div className="min-w-0">
                            <p className="text-sm font-medium">{deployment.position_title}</p>
                            <p className="text-xs text-muted-foreground">
                              {deployment.company_name} · {deployment.department_name}
                            </p>
                          </div>
                          <StatusBadge status={deployment.deployment_status} />
                        </div>
                        <p className="mt-1.5 text-xs text-muted-foreground">
                          From {formatDate(deployment.deployment_date)}
                          {deployment.end_date && ` to ${formatDate(deployment.end_date)}`}
                          <span className="ml-1 font-mono">· {deployment.deployment_code}</span>
                        </p>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>

          {(resignation || termination) && (
            <Card className="mt-4 border-warning/40 bg-warning/5">
              <CardHeader>
                <CardTitle>Separation</CardTitle>
              </CardHeader>
              <CardContent className="text-sm">
                {resignation && (
                  <p>
                    Resignation filed {formatDate(resignation.filing_date)} — {resignation.reason}.
                    Clearance: {humanise(resignation.clearance_status)}. Status:{' '}
                    {humanise(resignation.status)}.
                  </p>
                )}
                {termination && (
                  <p>
                    Termination dated {formatDate(termination.termination_date)} — {termination.reason}.
                    Status: {humanise(termination.status)}.
                  </p>
                )}
              </CardContent>
            </Card>
          )}
        </TabsContent>

        <TabsContent value="violations">
          <Card>
            <CardHeader>
              <CardTitle>Disciplinary record</CardTitle>
              <CardDescription>
                The full history is kept. An offence older than the agency's window stays on file
                and in reports but stops counting towards the review threshold.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <ViolationPolicySummary violations={employee.violations} />

              {!employee.violations?.length ? (
                <EmptyState icon={FileWarning} title="No violations recorded" description="A clean disciplinary record." />
              ) : (
                <ul className="divide-y">
                  {employee.violations.map((violation) => (
                    <li
                      key={violation.id}
                      // Expired offences recede rather than disappear: still
                      // readable, visibly no longer counting.
                      className={cn('py-3', violation.is_expired && 'opacity-60')}
                    >
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge tone="warning">{violation.type_label}</Badge>
                        <StatusBadge status={violation.status} />
                        {violation.is_expired ? (
                          <Badge tone="muted">No longer counting</Badge>
                        ) : (
                          <Badge tone="destructive">Counting</Badge>
                        )}
                        <span className="text-xs text-muted-foreground">
                          {formatDate(violation.violation_date)}
                        </span>
                      </div>
                      <p className="mt-1.5 text-sm">{violation.description}</p>
                      {violation.penalty && (
                        <p className="mt-0.5 text-xs text-muted-foreground">Penalty: {violation.penalty}</p>
                      )}
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        {violation.issued_by ? `Issued by ${violation.issued_by}. ` : ''}
                        {violation.is_expired
                          ? `Stopped counting ${formatDate(violation.expires_on)}.`
                          : `Counts until ${formatDate(violation.expires_on)}.`}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="history">
          <Card>
            <CardHeader>
              <CardTitle>Employment history</CardTitle>
            </CardHeader>
            <CardContent>
              {!status_history?.length ? (
                <EmptyState icon={Clock} title="No status changes recorded" />
              ) : (
                <ol className="relative space-y-4 border-l pl-6">
                  {status_history.map((entry) => (
                    <li key={entry.id} className="relative">
                      <span className="absolute -left-[31px] flex h-6 w-6 items-center justify-center rounded-full border bg-card">
                        <Clock className="h-3 w-3 text-muted-foreground" />
                      </span>
                      <p className="text-sm font-medium">
                        {entry.from_status ? `${humanise(entry.from_status)} → ` : ''}
                        {humanise(entry.to_status)}
                      </p>
                      {entry.reason && <p className="text-xs text-muted-foreground">{entry.reason}</p>}
                      <p className="text-xs text-muted-foreground">{formatDateTime(entry.changed_at)}</p>
                    </li>
                  ))}
                </ol>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <ViolationDialog open={violationOpen} onOpenChange={setViolationOpen} employeeId={id} onSaved={refetch} />
      <SeparationDialog open={separationOpen} onOpenChange={setSeparationOpen} employeeId={id} onSaved={refetch} />
    </>
  )
}

function ViolationDialog({ open, onOpenChange, employeeId, onSaved }) {
  const [form, setForm] = React.useState({
    violation_date: new Date().toISOString().slice(0, 10),
    violation_type: 'late',
    description: '',
    penalty: '',
  })
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setSubmitting(true)

    try {
      await post(`/employees/${employeeId}/violations`, form)
      toast.success('Violation recorded', 'Added to the employee’s permanent file.')
      onOpenChange(false)
      onSaved()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not record violation', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Record violation</DialogTitle>
          <DialogDescription>
            This becomes part of the employee's permanent record and may later support a termination,
            so describe what happened factually.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <FormGrid>
            <Field label="Date" htmlFor="violation_date" required error={errors.violation_date?.[0]}>
              <Input
                type="date"
                value={form.violation_date}
                onChange={(e) => setForm({ ...form, violation_date: e.target.value })}
                max={new Date().toISOString().slice(0, 10)}
              />
            </Field>

            <Field label="Type" htmlFor="violation_type" required error={errors.violation_type?.[0]}>
              <Select
                value={form.violation_type}
                onChange={(e) => setForm({ ...form, violation_type: e.target.value })}
              >
                <option value="late">Late</option>
                <option value="absences">Absences</option>
                <option value="awol">AWOL</option>
                <option value="suspension">Suspension</option>
                <option value="misconduct">Misconduct</option>
                <option value="policy_violation">Policy violation</option>
              </Select>
            </Field>
          </FormGrid>

          <Field label="Description" htmlFor="description" required error={errors.description?.[0]}>
            <Textarea
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
              placeholder="What happened, when, and who reported it"
            />
          </Field>

          <Field label="Penalty" htmlFor="penalty" error={errors.penalty?.[0]}>
            <Input
              value={form.penalty}
              onChange={(e) => setForm({ ...form, penalty: e.target.value })}
              placeholder="e.g. Written warning"
            />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Record violation
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function SeparationDialog({ open, onOpenChange, employeeId, onSaved }) {
  const [type, setType] = React.useState('resignation')
  const [form, setForm] = React.useState({
    reason: '',
    filing_date: new Date().toISOString().slice(0, 10),
    rendering_days: '30',
    exit_date: '',
    termination_date: new Date().toISOString().slice(0, 10),
  })
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setSubmitting(true)

    try {
      if (type === 'resignation') {
        await post(`/employees/${employeeId}/resignations`, {
          reason: form.reason,
          filing_date: form.filing_date,
          rendering_days: form.rendering_days || undefined,
          exit_date: form.exit_date || undefined,
        })
        toast.success('Resignation filed', 'Grant clearance before completing it.')
      } else {
        await post(`/employees/${employeeId}/terminations`, {
          reason: form.reason,
          termination_date: form.termination_date,
          status: 'for_review',
        })
        toast.success('Termination filed for review', 'An administrator must finalise it.')
      }

      onOpenChange(false)
      onSaved()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not file separation', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>File separation</DialogTitle>
          <DialogDescription>
            Filing records the intent. Employment does not end until the resignation is cleared or
            the termination is finalised.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Field label="Type" htmlFor="type" required>
            <Select value={type} onChange={(e) => setType(e.target.value)}>
              <option value="resignation">Resignation</option>
              <option value="termination">Termination</option>
            </Select>
          </Field>

          <Field label="Reason" htmlFor="reason" required error={errors.reason?.[0]}>
            <Textarea value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
          </Field>

          {type === 'resignation' ? (
            <FormGrid>
              <Field label="Filing date" htmlFor="filing_date" required error={errors.filing_date?.[0]}>
                <Input
                  type="date"
                  value={form.filing_date}
                  onChange={(e) => setForm({ ...form, filing_date: e.target.value })}
                />
              </Field>
              <Field
                label="Rendering days"
                htmlFor="rendering_days"
                error={errors.rendering_days?.[0]}
                hint="Standard notice is 30 days"
              >
                <Input
                  type="number"
                  value={form.rendering_days}
                  onChange={(e) => setForm({ ...form, rendering_days: e.target.value })}
                />
              </Field>
              <Field label="Exit date" htmlFor="exit_date" error={errors.exit_date?.[0]}>
                <Input
                  type="date"
                  value={form.exit_date}
                  onChange={(e) => setForm({ ...form, exit_date: e.target.value })}
                />
              </Field>
            </FormGrid>
          ) : (
            <Field label="Termination date" htmlFor="termination_date" required error={errors.termination_date?.[0]}>
              <Input
                type="date"
                value={form.termination_date}
                onChange={(e) => setForm({ ...form, termination_date: e.target.value })}
              />
            </Field>
          )}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              File {type}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/**
 * Where this employee stands against the client's disciplinary policy.
 *
 * Two numbers that are easy to conflate and must not be: how many offences are
 * on file, and how many still count. CDE's record clears after a year, so an
 * employee with six historical offences may be nowhere near review — and the
 * screen has to say which is which before anybody acts on it.
 *
 * Reaching the threshold is stated as a prompt to review, never as an outcome.
 * The system does not terminate anybody; an administrator decides.
 */
function ViolationPolicySummary({ violations }) {
  const rows = violations ?? []

  if (rows.length === 0) return null

  const active = rows.filter((v) => !v.is_expired)
  const expired = rows.length - active.length

  // Read from the rows themselves rather than a second request: every row
  // already carries whether it counts, so the count cannot disagree with the list.
  const flagged = active.length >= 4

  return (
    <div
      className={cn(
        'flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border px-3 py-2.5 text-sm',
        flagged ? 'border-warning/50 bg-warning/[0.06]' : 'bg-muted/40'
      )}
    >
      <span>
        <span className="font-semibold tabular-nums">{active.length}</span> counting
      </span>
      {expired > 0 && (
        <span className="text-muted-foreground">
          <span className="tabular-nums">{expired}</span> no longer counting
        </span>
      )}
      {flagged && (
        <span className="flex items-center gap-1.5 text-warning">
          <AlertTriangle className="h-3.5 w-3.5" />
          At the review threshold — an administrator should review this record.
        </span>
      )}
    </div>
  )
}
