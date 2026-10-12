import * as React from 'react'
import { useParams, useSearchParams, Link } from 'react-router-dom'
import { Loader2, FileWarning, Truck, Clock, AlertTriangle, ArrowLeft } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { post, upload } from '@/lib/api'
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
import { cn, formatDate, formatDateTime, humanise, todayISO } from '@/lib/utils'
import SeparationPanel from '@/components/SeparationPanel'
import AttendancePanel from '@/components/AttendancePanel'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'

const TABS = ['assignment', 'attendance', 'violations', 'history']

export default function EmployeeDetail() {
  const { id } = useParams()
  const { can } = useAuth()
  const { data, loading, error, refetch } = useApi(`/employees/${id}`)

  const [violationPrefill, setViolationPrefill] = React.useState(null)
  const [separationOpen, setSeparationOpen] = React.useState(false)

  // The open tab lives in the URL, so the attendance summary can link
  // straight to one employee's DTR and the back button returns to it.
  const [searchParams, setSearchParams] = useSearchParams()
  const requested = searchParams.get('tab')
  const tab = TABS.includes(requested) && (requested !== 'attendance' || can('attendance.view')) ? requested : 'assignment'

  function selectTab(value) {
    const next = new URLSearchParams(searchParams)
    if (value === 'assignment') next.delete('tab')
    else next.set('tab', value)
    setSearchParams(next, { replace: true })
  }

  if (loading) return <LoadingState label="Loading employee…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const { employee, status_history, resignation, termination } = data
  const openCases = {
    resignation: Boolean(resignation?.is_open),
    termination: Boolean(termination?.is_open),
  }

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
                <Button variant="outline" onClick={() => setViolationPrefill({})}>
                  <FileWarning className="h-4 w-4" />
                  Record violation
                </Button>
              )}
              {can('separation.create') && !(openCases.resignation && openCases.termination) && (
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

      {(openCases.resignation || openCases.termination) && (
        <div className="mb-5 flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-sm">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
          <p>
            {openCases.termination
              ? 'A termination is awaiting review.'
              : 'A resignation is being processed.'}{' '}
            <button type="button" className="font-medium text-primary hover:underline" onClick={() => selectTab('assignment')}>
              See the separation below
            </button>
            .
          </p>
        </div>
      )}

      <Tabs value={tab} onValueChange={selectTab}>
        <TabsList>
          <TabsTrigger value="assignment">Assignment</TabsTrigger>
          {can('attendance.view') && <TabsTrigger value="attendance">Attendance (DTR)</TabsTrigger>}
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

          <SeparationPanel
            employee={employee}
            resignation={resignation}
            termination={termination}
            onChanged={refetch}
          />
        </TabsContent>

        {can('attendance.view') && (
          <TabsContent value="attendance">
            <AttendancePanel
              employee={employee}
              onRecordViolation={(prefill) => setViolationPrefill(prefill)}
            />
          </TabsContent>
        )}

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
                        {violation.reported_by
                          ? `Reported by coordinator ${violation.reported_by}${violation.report_code ? ` (${violation.report_code})` : ''}. `
                          : ''}
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

      <ViolationDialog
        open={violationPrefill !== null}
        onOpenChange={(open) => !open && setViolationPrefill(null)}
        employeeId={id}
        initial={violationPrefill}
        onSaved={refetch}
      />
      <SeparationDialog
        open={separationOpen}
        onOpenChange={setSeparationOpen}
        employee={employee}
        openCases={openCases}
        onSaved={refetch}
      />
    </>
  )
}

function ViolationDialog({ open, onOpenChange, employeeId, initial, onSaved }) {
  const blank = () => ({
    violation_date: todayISO(),
    violation_type: 'late',
    description: '',
    penalty: '',
  })
  const [form, setForm] = React.useState(blank)
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  // Opened from a DTR row, the facts arrive already written; opened from the
  // header, it starts empty. Either way nothing is saved until somebody does.
  React.useEffect(() => {
    if (open) {
      setForm({ ...blank(), ...(initial ?? {}) })
      setErrors({})
    }
  }, [open, initial])

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
            {initial?.violation_date && ' Filled in from the DTR — check it before saving.'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <FormGrid>
            <Field label="Date" htmlFor="violation_date" required error={errors.violation_date?.[0]}>
              <Input
                type="date"
                value={form.violation_date}
                onChange={(e) => setForm({ ...form, violation_date: e.target.value })}
                max={todayISO()}
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

function SeparationDialog({ open, onOpenChange, employee, openCases, onSaved }) {
  const today = todayISO()
  const firstAvailable = openCases.resignation ? 'termination' : 'resignation'

  const blank = () => ({
    reason: '',
    filing_date: today,
    rendering_days: '30',
    exit_date: '',
    termination_date: today,
    remarks: '',
  })

  const [type, setType] = React.useState(firstAvailable)
  const [form, setForm] = React.useState(blank)
  const [file, setFile] = React.useState(null)
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const [reviewing, setReviewing] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setType(firstAvailable)
      setForm(blank())
      setFile(null)
      setErrors({})
      setReviewing(false)
    }
  }, [open])

  function buildBody() {
    const fields =
      type === 'resignation'
        ? {
            reason: form.reason,
            filing_date: form.filing_date,
            rendering_days: form.rendering_days,
            exit_date: form.exit_date,
            remarks: form.remarks,
          }
        : {
            reason: form.reason,
            termination_date: form.termination_date,
            remarks: form.remarks,
            status: 'for_review',
          }

    // Multipart so a letter or supporting document can travel with it.
    const body = new FormData()
    Object.entries(fields).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) body.append(key, value)
    })
    if (file) body.append(type === 'resignation' ? 'letter' : 'documents', file)

    return body
  }

  async function submit() {
    setErrors({})
    setSubmitting(true)

    try {
      await upload(`/employees/${employee.id}/${type === 'resignation' ? 'resignations' : 'terminations'}`, buildBody())

      if (type === 'resignation') {
        toast.success('Resignation filed', 'Record clearance and an exit date, then complete it.')
      } else {
        toast.success('Termination filed for review', 'An administrator must finalise or withdraw it.')
      }

      onOpenChange(false)
      onSaved()
    } catch (error) {
      setReviewing(false)
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not file the separation', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  function handleSubmit(event) {
    event.preventDefault()

    if (!form.reason.trim()) {
      setErrors({ reason: ['A reason is required.'] })
      return
    }

    // A termination is reviewed once more before it is filed: who, why, and
    // what filing it does and does not do.
    if (type === 'termination') {
      setReviewing(true)
      return
    }

    submit()
  }

  return (
    <>
      <Dialog open={open && !reviewing} onOpenChange={(next) => !submitting && onOpenChange(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>File separation</DialogTitle>
            <DialogDescription>
              Filing records the intent. Employment does not end until the resignation is cleared and
              completed, or the termination is finalised by an administrator.
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="space-y-4" noValidate>
            <Field label="Type" htmlFor="type" required>
              <Select value={type} onChange={(e) => setType(e.target.value)}>
                <option value="resignation" disabled={openCases.resignation}>
                  Resignation{openCases.resignation ? ' (one is already in progress)' : ''}
                </option>
                <option value="termination" disabled={openCases.termination}>
                  Termination{openCases.termination ? ' (one is already awaiting review)' : ''}
                </option>
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
                    min="0"
                    max="90"
                    value={form.rendering_days}
                    onChange={(e) => setForm({ ...form, rendering_days: e.target.value })}
                  />
                </Field>
                <Field label="Exit date" htmlFor="exit_date" error={errors.exit_date?.[0]} hint="Can be set later">
                  <Input
                    type="date"
                    value={form.exit_date}
                    min={form.filing_date}
                    onChange={(e) => setForm({ ...form, exit_date: e.target.value })}
                  />
                </Field>
              </FormGrid>
            ) : (
              <Field
                label="Termination date"
                htmlFor="termination_date"
                required
                error={errors.termination_date?.[0]}
              >
                <Input
                  type="date"
                  value={form.termination_date}
                  onChange={(e) => setForm({ ...form, termination_date: e.target.value })}
                />
              </Field>
            )}

            <Field label="Remarks" htmlFor="separation_remarks" error={errors.remarks?.[0]}>
              <Input value={form.remarks} onChange={(e) => setForm({ ...form, remarks: e.target.value })} />
            </Field>

            <Field
              label={type === 'resignation' ? 'Resignation letter' : 'Supporting documents'}
              htmlFor="separation_file"
              error={errors.letter?.[0] ?? errors.documents?.[0]}
              hint="Optional. PDF or image, up to 10 MB."
            >
              <Input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png,.webp"
                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              />
            </Field>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" variant={type === 'termination' ? 'destructive' : 'default'} disabled={submitting}>
                {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
                {type === 'termination' ? 'Review termination…' : 'File resignation'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <ConfirmActionDialog
        open={open && reviewing}
        onOpenChange={(next) => !next && setReviewing(false)}
        tone="warning"
        title="File a termination for review?"
        description="This does not end the employment yet. An administrator reviews it and either finalises or withdraws it."
        details={[
          ['Employee', employee.full_name],
          ['Employee no.', employee.employee_number],
          ['Reason', form.reason],
          ['Termination date', formatDate(form.termination_date)],
          ['Attachment', file?.name],
        ]}
        consequences={[
          'The termination is recorded as awaiting review, with you as the officer who filed it.',
          'The employee stays active until it is finalised.',
        ]}
        confirmLabel="File for review"
        onConfirm={submit}
      >
        <Button type="button" variant="link" size="sm" className="h-auto justify-start p-0" onClick={() => setReviewing(false)}>
          <ArrowLeft className="h-3.5 w-3.5" />
          Back to the form
        </Button>
      </ConfirmActionDialog>
    </>
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
