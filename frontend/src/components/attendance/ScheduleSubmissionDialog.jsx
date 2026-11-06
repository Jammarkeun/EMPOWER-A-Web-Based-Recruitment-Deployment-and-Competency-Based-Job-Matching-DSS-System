import * as React from 'react'
import { Link } from 'react-router-dom'
import { CheckCircle2, CornerUpLeft, Loader2 } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useApi } from '@/hooks/useApi'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Field } from '@/components/ui/form'
import { ErrorState, LoadingState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import { ScheduleChecks, ScheduleCompare, ScheduleHistory, ScheduleStatusBadge } from './ScheduleSubmissionParts'
import { employeeAttendancePath, monthOf } from '@/lib/attendance'
import { formatDate, formatDateTime } from '@/lib/utils'

/**
 * HR's review of one schedule a coordinator sent in.
 *
 * Shows the proposal beside the approved schedule it would replace, the
 * worker's placement, what the server's checks found, and every step so far.
 * Approving puts the schedule in force from its start date; returning sends it
 * back with what needs correcting. Both are decided again on the server, which
 * refuses anything the checks say must be fixed.
 */
export default function ScheduleSubmissionDialog({ submissionId, open, onOpenChange, onChanged }) {
  const { can } = useAuth()
  const toast = useToast()
  const { data, loading, error, refetch } = useApi(
    submissionId ? `/attendance/schedule-submissions/${submissionId}` : null,
    undefined,
    { enabled: Boolean(open && submissionId) }
  )

  const [returning, setReturning] = React.useState(false)
  const [note, setNote] = React.useState('')
  const [noteError, setNoteError] = React.useState(null)
  const [approving, setApproving] = React.useState(false)
  const [pastConfirmed, setPastConfirmed] = React.useState(false)
  const [saving, setSaving] = React.useState(false)

  React.useEffect(() => {
    if (open) {
      setReturning(false)
      setNote('')
      setNoteError(null)
      setPastConfirmed(false)
    }
  }, [open, submissionId])

  const checks = data?.checks ?? []
  const blocked = checks.some((c) => c.level === 'error')
  const pastCheck = checks.find((c) => c.code === 'replaces_past_days')
  const mayDecide = data?.status === 'submitted' && data?.can_review

  async function decide(action, extra = {}) {
    setSaving(true)
    try {
      const response = await post(`/attendance/schedule-submissions/${submissionId}/review`, { action, ...extra })
      toast.success(
        response.message,
        action === 'approve'
          ? `The DTR is compared with it from ${formatDate(data.effective_from)}. The coordinator has been told.`
          : 'The coordinator has been told what to correct.'
      )
      setApproving(false)
      setReturning(false)
      refetch()
      onChanged?.()
    } catch (err) {
      if (err.isValidation && err.errors?.note) setNoteError(err.errors.note[0])
      else toast.error(action === 'approve' ? 'Could not approve the schedule' : 'Could not return the schedule', err.message)
      if (action === 'approve') refetch()
    } finally {
      setSaving(false)
    }
  }

  async function sendBack(event) {
    event.preventDefault()
    if (note.trim().length < 5) {
      setNoteError('Say what needs correcting, so the coordinator knows what to change.')
      return
    }
    await decide('return', { note: note.trim() })
  }

  async function approve() {
    if (pastCheck && !pastConfirmed) {
      toast.error('Confirm the past days first', 'Tick the box to confirm you have read what approving changes.')
      return
    }
    await decide('approve', pastCheck ? { confirm_replaces_past: true } : {})
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Work schedule {data?.submission_code ?? ''}</DialogTitle>
          <DialogDescription>
            Proposed by the coordinator from what the client gave. It is compared with the DTR only once approved.
          </DialogDescription>
        </DialogHeader>

        {loading ? (
          <LoadingState label="Loading schedule…" />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : data ? (
          <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="font-semibold">
                  {can('employees.view') ? (
                    <Link to={employeeAttendancePath(data.employee.id, monthOf(data.effective_from))} className="hover:text-brand">
                      {data.employee.full_name}
                    </Link>
                  ) : (
                    data.employee.full_name
                  )}
                </p>
                <p className="text-xs text-muted-foreground">
                  <span className="font-mono">{data.employee.employee_number}</span>
                  {data.employee.biometric_number && ` · biometrics ${data.employee.biometric_number}`}
                  {data.company && ` · ${data.company}`}
                  {data.department && ` · ${data.department}`}
                </p>
              </div>
              <ScheduleStatusBadge status={data.status} />
            </div>

            {data.deployment && (
              <dl className="grid gap-x-6 gap-y-2 rounded-md border px-3 py-2.5 text-sm sm:grid-cols-3">
                {[
                  ['Placement', data.deployment.deployment_code],
                  ['Position', data.deployment.position],
                  ['Supervisor', data.deployment.supervisor],
                  ['Deployed', formatDate(data.deployment.deployment_date)],
                  ['Placement ends', data.deployment.end_date ? formatDate(data.deployment.end_date) : 'Open'],
                  ['Hire date', formatDate(data.hire_date)],
                ]
                  .filter(([, value]) => value)
                  .map(([label, value]) => (
                    <div key={label}>
                      <dt className="text-xs text-muted-foreground">{label}</dt>
                      <dd>{value}</dd>
                    </div>
                  ))}
              </dl>
            )}

            <section className="space-y-2">
              <h3 className="text-sm font-semibold">Proposed change</h3>
              <ScheduleCompare current={data.current_schedule} proposed={data} changes={data.changes} />
              <dl className="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                  <dt className="text-xs text-muted-foreground">Source given by the coordinator</dt>
                  <dd>{data.source_note || <span className="text-muted-foreground">None given</span>}</dd>
                </div>
                {data.remarks && (
                  <div>
                    <dt className="text-xs text-muted-foreground">Remarks</dt>
                    <dd>{data.remarks}</dd>
                  </div>
                )}
              </dl>
            </section>

            {checks.length > 0 && (
              <section className="space-y-2">
                <h3 className="text-sm font-semibold">Checks</h3>
                <ScheduleChecks checks={checks} />
              </section>
            )}

            {data.review_note && data.status !== 'submitted' && (
              <p className="rounded-md border px-3 py-2 text-sm">
                <span className="text-muted-foreground">
                  {data.status === 'approved' ? 'Approval note' : 'Returned'} by {data.reviewed_by}, {formatDateTime(data.reviewed_at)}:
                </span>{' '}
                “{data.review_note}”
              </p>
            )}

            <section className="space-y-2">
              <h3 className="text-sm font-semibold">History</h3>
              <ScheduleHistory events={data.events} />
            </section>

            {returning && (
              <form onSubmit={sendBack} className="space-y-3 rounded-md border border-warning/40 bg-warning/[0.04] p-3">
                <Field label="What needs correcting" htmlFor="schedule_return_note" required error={noteError}>
                  <Textarea
                    rows={3}
                    maxLength={1000}
                    value={note}
                    onChange={(e) => {
                      setNote(e.target.value)
                      setNoteError(null)
                    }}
                    placeholder="e.g. The client's memo says the shift starts at 08:00, not 07:00."
                  />
                </Field>
                <div className="flex justify-end gap-2">
                  <Button type="button" variant="outline" onClick={() => setReturning(false)} disabled={saving}>
                    Cancel
                  </Button>
                  <Button type="submit" disabled={saving}>
                    {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                    Return to coordinator
                  </Button>
                </div>
              </form>
            )}
          </div>
        ) : null}

        <DialogFooter>
          {data?.status === 'submitted' && !data?.can_review && (
            <p className="mr-auto text-xs text-muted-foreground">
              {can('attendance.manage') ? 'You wrote this schedule, so somebody else has to review it.' : 'Only HR with attendance management can decide.'}
            </p>
          )}
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            Close
          </Button>
          {mayDecide && !returning && (
            <>
              <Button variant="outline" onClick={() => setReturning(true)} disabled={saving}>
                <CornerUpLeft className="h-4 w-4" />
                Return
              </Button>
              <Button onClick={() => setApproving(true)} disabled={saving || blocked} title={blocked ? 'Fix the problems listed under Checks first.' : undefined}>
                <CheckCircle2 className="h-4 w-4" />
                Approve
              </Button>
            </>
          )}
        </DialogFooter>

        <ConfirmActionDialog
          open={approving}
          onOpenChange={setApproving}
          title="Approve this schedule?"
          description="It becomes the schedule the DTR is compared with. It cannot be edited afterwards; a later change is a new schedule from a later date."
          details={
            data
              ? [
                  ['Employee', data.employee.full_name],
                  ['Schedule', data.description],
                  ['From', `${formatDate(data.effective_from)}${data.effective_to ? ` to ${formatDate(data.effective_to)}` : ''}`],
                ]
              : []
          }
          consequences={
            data?.current_schedule
              ? [`The current schedule (${data.current_schedule.description}) ends the day before.`]
              : []
          }
          confirmLabel="Approve schedule"
          tone="warning"
          onConfirm={approve}
        >
          {pastCheck && (
            <label className="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/[0.06] px-3 py-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5"
                checked={pastConfirmed}
                onChange={(e) => setPastConfirmed(e.target.checked)}
              />
              <span>{pastCheck.message}</span>
            </label>
          )}
        </ConfirmActionDialog>
      </DialogContent>
    </Dialog>
  )
}
