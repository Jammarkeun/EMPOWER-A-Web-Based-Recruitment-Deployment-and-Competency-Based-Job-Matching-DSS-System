import * as React from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { CornerUpLeft, Info, Loader2, Save, Send, Undo2 } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { post, put } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Field, FormGrid, useFocusFirstError } from '@/components/ui/form'
import { LoadingState, ErrorState } from '@/components/ui/states'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import EmployeePicker from '@/components/EmployeePicker'
import { useLeavePageWarning } from '@/components/UnsavedChanges'
import ScheduleFields, { scheduleBody } from '@/components/attendance/ScheduleFields'
import { ScheduleChecks, ScheduleCompare, ScheduleHistory, ScheduleStatusBadge } from '@/components/attendance/ScheduleSubmissionParts'
import { formatDate, formatDateTime, todayISO } from '@/lib/utils'
import { OutOfScope } from './CoordinatorEmployeeDetail'

/**
 * One work schedule the coordinator writes for a worker at their client.
 *
 * A form while it is a draft or HR has returned it; read-only once it is with
 * HR or decided. The hours must be the ones the client gave - the source field
 * says where they came from, so HR can check. Nothing here is compared with the
 * DTR until HR approves it, and an approved schedule is never edited: a change
 * is a new schedule from a later date.
 */
export default function CoordinatorSchedule() {
  const { id } = useParams()
  const [searchParams] = useSearchParams()
  const isNew = !id
  const preselected = isNew && /^\d+$/.test(searchParams.get('employee') ?? '') ? searchParams.get('employee') : null

  const submission = useApi(isNew ? null : `/coordinator/schedules/${id}`, undefined, { enabled: !isNew })

  if (!isNew && submission.loading) return <LoadingState label="Loading schedule…" />
  if (!isNew && submission.error?.status === 403) {
    return <OutOfScope message={submission.error.message} backTo="/coordinator/schedules" backLabel="Back to your schedules" />
  }
  if (!isNew && submission.error) return <ErrorState message={submission.error.message} onRetry={submission.refetch} />

  const data = isNew ? null : submission.data

  if (data && !data.is_editable) return <ScheduleView data={data} onChanged={submission.refetch} />

  return <ScheduleForm key={data?.id ?? 'new'} existing={data} preselectedId={preselected} onSaved={submission.refetch} />
}

/** What the server would refuse anyway, said before anything is sent. */
export function validateSchedule(form, { submit }) {
  const errors = {}
  if (!form.employee_id) errors.employee_id = ['Choose the employee.']
  if (!form.effective_from) errors.effective_from = ['Give the date the schedule starts.']
  if (form.effective_to && form.effective_from && form.effective_to < form.effective_from) {
    errors.effective_to = ['The end date cannot be before the start date.']
  }
  if (!form.work_days.length) errors.work_days = ['Choose at least one work day.']
  if (!form.shift_start) errors.shift_start = ['Give the time the shift starts.']
  if (!form.shift_end) errors.shift_end = ['Give the time the shift ends.']
  else if (form.shift_end === form.shift_start) errors.shift_end = ['The shift must end at a different time from when it starts.']
  if (form.break_minutes !== '' && !form.break_start) errors.break_start = ['Give the time the break starts, or leave the break blank.']
  if (submit && !form.source_note.trim()) {
    errors.source_note = ['Say where these hours come from, so HR can check them.']
  }
  return errors
}

function ScheduleForm({ existing, preselectedId, onSaved }) {
  const toast = useToast()
  const navigate = useNavigate()
  const [employee, setEmployee] = React.useState(existing?.employee ?? null)
  const [employeeId, setEmployeeId] = React.useState(existing?.employee.id ?? (preselectedId ? Number(preselectedId) : null))

  // The worker's placement and approved schedule, for a new schedule. An
  // existing one carries its own (current_schedule) from the server.
  const worker = useApi(!existing && employeeId ? `/coordinator/employees/${employeeId}` : null, undefined, {
    enabled: Boolean(!existing && employeeId),
  })

  React.useEffect(() => {
    if (!existing && !employee && worker.data?.employee) setEmployee(worker.data.employee)
  }, [existing, employee, worker.data])

  const initial = React.useMemo(
    () => ({
      employee_id: employeeId,
      effective_from: existing?.effective_from ?? todayISO(),
      effective_to: existing?.effective_to ?? '',
      work_days: existing?.work_days ?? [1, 2, 3, 4, 5],
      shift_start: existing?.shift_start ?? '08:00',
      shift_end: existing?.shift_end ?? '17:00',
      break_start: existing?.break_start ?? '',
      break_minutes: existing?.break_minutes != null ? String(existing.break_minutes) : '',
      source_note: existing?.source_note ?? '',
      remarks: existing?.remarks ?? '',
    }),
    // Only the record it was opened on; a refresh must not reset typing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [existing?.id]
  )
  const [form, setForm] = React.useState(initial)
  const [savedForm, setSavedForm] = React.useState(initial)
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(null)
  const [confirmSubmit, setConfirmSubmit] = React.useState(false)
  const [confirmWithdraw, setConfirmWithdraw] = React.useState(false)
  const [formRef, focusFirstError] = useFocusFirstError()
  useLeavePageWarning(JSON.stringify(form) !== JSON.stringify(savedForm))

  React.useEffect(() => {
    setForm((f) => (f.employee_id === employeeId ? f : { ...f, employee_id: employeeId }))
  }, [employeeId])

  const set = (key) => (event) => setForm((f) => ({ ...f, [key]: event.target.value }))
  const err = (key) => errors[key]?.[0]

  const current = existing
    ? existing.current_schedule
    : (worker.data?.schedules ?? []).find((s) => s.id === worker.data?.current_schedule_id) ?? null
  const openElsewhere = !existing
    ? (worker.data?.schedule_submissions ?? []).find((s) => ['draft', 'submitted', 'returned'].includes(s.status))
    : null

  function checked(submit) {
    const found = validateSchedule(form, { submit })
    setErrors(found)
    if (Object.keys(found).length) {
      toast.error('Some fields need attention', 'Check the fields marked below.')
      focusFirstError()
      return false
    }
    return true
  }

  async function save(submit) {
    setSaving(submit ? 'submit' : 'draft')
    setErrors({})
    const body = {
      ...scheduleBody(form),
      effective_from: form.effective_from,
      effective_to: form.effective_to || null,
      source_note: form.source_note.trim() || null,
      submit,
    }

    try {
      const response = existing
        ? await put(`/coordinator/schedules/${existing.id}`, body)
        : await post(`/coordinator/employees/${form.employee_id}/schedules`, body)
      toast.success(response.message, submit ? 'HR has been notified. It is not compared with the DTR until approved.' : 'You can come back to it before sending.')
      setConfirmSubmit(false)
      setSavedForm(form)
      if (existing) onSaved()
      else navigate(`/coordinator/schedules/${response.data.id}`, { replace: true })
    } catch (error) {
      setConfirmSubmit(false)
      if (error.isValidation && Object.keys(error.errors ?? {}).length) {
        setErrors(error.errors)
        focusFirstError()
      } else toast.error('Could not save the schedule', error.message)
    } finally {
      setSaving(null)
    }
  }

  async function withdraw() {
    try {
      await post(`/coordinator/schedules/${existing.id}/withdraw`)
      toast.success('Schedule withdrawn', 'It stays on file, marked withdrawn.')
      setSavedForm(form)
      navigate('/coordinator/schedules', { replace: true })
    } catch (error) {
      toast.error('Could not withdraw the schedule', error.message)
    }
  }

  return (
    <div ref={formRef} className="space-y-5">
      <PageHeader
        breadcrumbs={[
          { label: 'Work Schedules', to: '/coordinator/schedules' },
          { label: existing?.submission_code ?? 'New schedule' },
        ]}
        title={existing ? `Schedule ${existing.submission_code}` : 'New work schedule'}
        description="The hours your client gave this worker. HR approves them before the DTR is compared with them."
      />

      {existing?.status === 'returned' && (
        <div className="flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-sm">
          <CornerUpLeft className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
          <p>
            HR returned this schedule for correction
            {existing.reviewed_by && ` (${existing.reviewed_by})`}: <span className="font-medium">{existing.review_note}</span>
          </p>
        </div>
      )}

      {openElsewhere && (
        <div className="flex items-start gap-2.5 rounded-lg border border-status-info-border bg-status-info-bg px-3 py-2.5 text-sm text-status-info-text">
          <Info className="mt-0.5 h-4 w-4 shrink-0" />
          <p>
            This employee already has a schedule that is {openElsewhere.status_label.toLowerCase()}.{' '}
            <Link to={`/coordinator/schedules/${openElsewhere.id}`} className="font-medium text-brand hover:underline">
              Open {openElsewhere.submission_code}
            </Link>{' '}
            instead of starting another.
          </p>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardContent className="space-y-4 pt-5">
            <Field label="Employee" htmlFor="employee_id" required error={err('employee_id')}>
              {existing ? (
                <Input value={`${existing.employee.full_name} · ${existing.employee.employee_number}`} readOnly />
              ) : (
                <EmployeePicker
                  value={form.employee_id}
                  selected={employee}
                  onChange={(picked) => {
                    setEmployee(picked)
                    setEmployeeId(picked?.id ?? null)
                  }}
                />
              )}
            </Field>

            <FormGrid>
              <Field label="Starts" htmlFor="effective_from" required error={err('effective_from')}>
                <Input type="date" value={form.effective_from} onChange={set('effective_from')} />
              </Field>
              <Field label="Ends" htmlFor="effective_to" error={err('effective_to')} hint="Leave blank if it runs until the next change.">
                <Input type="date" value={form.effective_to} min={form.effective_from || undefined} onChange={set('effective_to')} />
              </Field>
            </FormGrid>

            <ScheduleFields form={form} setForm={setForm} errors={errors} />

            <Field
              label="Where these hours come from"
              htmlFor="source_note"
              required
              error={err('source_note')}
              hint="Needed before sending. For example: the client's memo, the site supervisor's message."
            >
              <Textarea rows={2} maxLength={500} value={form.source_note} onChange={set('source_note')} placeholder="e.g. Production supervisor's memo dated 28 September 2026" />
            </Field>

            <Field label="Remarks" htmlFor="schedule_remarks" error={err('remarks')}>
              <Input maxLength={255} value={form.remarks} onChange={set('remarks')} placeholder="e.g. Moved to the night shift at the client's request" />
            </Field>

            <div className="flex flex-wrap justify-end gap-2 border-t pt-4">
              {existing && (
                <Button type="button" variant="ghost" onClick={() => setConfirmWithdraw(true)} disabled={Boolean(saving)}>
                  <Undo2 className="h-4 w-4" />
                  Withdraw
                </Button>
              )}
              <Button type="button" variant="outline" onClick={() => checked(false) && save(false)} disabled={Boolean(saving)}>
                {saving === 'draft' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                Save draft
              </Button>
              <Button type="button" onClick={() => checked(true) && setConfirmSubmit(true)} disabled={Boolean(saving)}>
                <Send className="h-4 w-4" />
                {existing?.status === 'returned' ? 'Send back to HR' : 'Send to HR'}
              </Button>
            </div>
          </CardContent>
        </Card>

        <div className="space-y-4">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-base">Approved schedule now</CardTitle>
              <CardDescription>What the DTR is compared with until HR approves this one.</CardDescription>
            </CardHeader>
            <CardContent className="text-sm">
              {!employeeId ? (
                <p className="text-muted-foreground">Choose an employee.</p>
              ) : worker.loading ? (
                <p className="text-muted-foreground">Loading…</p>
              ) : current ? (
                <>
                  <p className="font-medium">{current.description}</p>
                  <p className="text-xs text-muted-foreground">
                    Since {formatDate(current.effective_from)}
                    {current.effective_to && ` until ${formatDate(current.effective_to)}`}
                  </p>
                </>
              ) : (
                <p className="text-muted-foreground">No approved schedule yet.</p>
              )}
            </CardContent>
          </Card>

          {existing?.checks?.length > 0 && (
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-base">Before sending</CardTitle>
              </CardHeader>
              <CardContent>
                <ScheduleChecks checks={existing.checks} />
              </CardContent>
            </Card>
          )}

          {existing?.events?.length > 0 && (
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-base">History</CardTitle>
              </CardHeader>
              <CardContent>
                <ScheduleHistory events={existing.events} />
              </CardContent>
            </Card>
          )}
        </div>
      </div>

      <ConfirmActionDialog
        open={confirmSubmit}
        onOpenChange={setConfirmSubmit}
        title="Send this schedule to HR?"
        description="HR checks it against what the client gave and approves or returns it. You cannot change it while HR has it."
        details={[
          ['Employee', employee?.full_name ?? existing?.employee.full_name],
          ['From', `${formatDate(form.effective_from)}${form.effective_to ? ` to ${formatDate(form.effective_to)}` : ''}`],
          ['Source', form.source_note],
        ]}
        confirmLabel="Send to HR"
        tone="warning"
        onConfirm={() => save(true)}
      />
      <ConfirmActionDialog
        open={confirmWithdraw}
        onOpenChange={setConfirmWithdraw}
        title="Withdraw this schedule?"
        description="It will not go to HR. It stays on file, marked withdrawn, and you can write a new one."
        confirmLabel="Withdraw"
        tone="warning"
        onConfirm={withdraw}
      />
    </div>
  )
}

/** A schedule with HR, approved or withdrawn: read-only. */
function ScheduleView({ data, onChanged }) {
  const toast = useToast()
  const navigate = useNavigate()
  const [confirmWithdraw, setConfirmWithdraw] = React.useState(false)

  async function withdraw() {
    try {
      await post(`/coordinator/schedules/${data.id}/withdraw`)
      toast.success('Schedule withdrawn', 'HR will not review it. It stays on file, marked withdrawn.')
      onChanged()
    } catch (error) {
      toast.error('Could not withdraw the schedule', error.message)
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        breadcrumbs={[{ label: 'Work Schedules', to: '/coordinator/schedules' }, { label: data.submission_code }]}
        title={data.employee.full_name}
        description={`${data.submission_code} · ${data.description}`}
        actions={
          <>
            {data.status === 'submitted' && (
              <Button variant="outline" onClick={() => setConfirmWithdraw(true)}>
                <Undo2 className="h-4 w-4" />
                Withdraw
              </Button>
            )}
            {data.status === 'approved' && data.employee.employment_status === 'active' && (
              <Button onClick={() => navigate(`/coordinator/schedules/new?employee=${data.employee.id}`)}>New schedule from a later date</Button>
            )}
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <ScheduleStatusBadge status={data.status} />
        <span className="text-sm text-muted-foreground">
          {data.status === 'submitted' && `Sent ${formatDateTime(data.submitted_at)}. Not compared with the DTR until HR approves it.`}
          {data.status === 'approved' && `Approved by ${data.reviewed_by}, ${formatDateTime(data.reviewed_at)}. The DTR is compared with it from ${formatDate(data.effective_from)}.`}
          {data.status === 'withdrawn' && 'Withdrawn. It was never compared with the DTR.'}
        </span>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Schedule</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <ScheduleCompare current={data.current_schedule} proposed={data} changes={data.changes} />
            <dl className="grid gap-3 text-sm sm:grid-cols-2">
              <div>
                <dt className="text-xs text-muted-foreground">Source</dt>
                <dd>{data.source_note || '—'}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Remarks</dt>
                <dd>{data.remarks || '—'}</dd>
              </div>
            </dl>
            {data.review_note && (
              <p className="rounded-md border px-3 py-2 text-sm">
                <span className="text-muted-foreground">HR's note:</span> “{data.review_note}”
              </p>
            )}
            {data.checks?.length > 0 && <ScheduleChecks checks={data.checks} />}
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">History</CardTitle>
          </CardHeader>
          <CardContent>
            <ScheduleHistory events={data.events} />
          </CardContent>
        </Card>
      </div>

      <ConfirmActionDialog
        open={confirmWithdraw}
        onOpenChange={setConfirmWithdraw}
        title="Withdraw this schedule?"
        description="HR will not review it. It stays on file, marked withdrawn."
        confirmLabel="Withdraw"
        tone="warning"
        onConfirm={withdraw}
      />
    </div>
  )
}
