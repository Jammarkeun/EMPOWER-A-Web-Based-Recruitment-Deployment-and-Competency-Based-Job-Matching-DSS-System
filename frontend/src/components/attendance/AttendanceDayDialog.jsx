import * as React from 'react'
import { Link } from 'react-router-dom'
import { ClipboardCheck, FileWarning, History as HistoryIcon, Info, Loader2, UserRound } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useApi } from '@/hooks/useApi'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Select } from '@/components/ui/select'
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
import ViolationDialog from '@/components/dialogs/ViolationDialog'
import { DAY_STATUS, REVIEW_STATUS, discrepancyText, formatMinutes, shiftText, DISCREPANCIES } from '@/lib/attendance'
import { formatDate, formatDateTime } from '@/lib/utils'

const SOURCE_LABEL = { manual: 'Encoded by hand', import: 'Imported from a DTR file' }

/**
 * One day of one employee's attendance, in full, and HR's review of it.
 *
 * Shows what was scheduled beside what the DTR says, every change ever made to
 * that day's DTR, and each step of the review. It is the same dialog, reading
 * the same day endpoint, in both attendance views:
 *
 * - Opened from the review queue it is where the decision is made: under
 *   verification, verified, corrected, closed. Only a verified day offers to
 *   record a violation, and that opens the ordinary violation form - this
 *   screen records nothing disciplinary itself. `employeeHref` links back to
 *   the employee's own DTR.
 * - Opened from the employee's profile it only shows the review, and
 *   `reviewHref` takes the user to this exact day in the review queue, so
 *   decisions are taken in one place.
 */
export default function AttendanceDayDialog({ open, onOpenChange, employeeId, date, onChanged, reviewHref, employeeHref }) {
  const { can } = useAuth()
  const toast = useToast()
  const { data, loading, error, refetch } = useApi(
    employeeId && date ? `/employees/${employeeId}/attendance/days/${date}` : null,
    undefined,
    { enabled: Boolean(open && employeeId && date) }
  )

  const [next, setNext] = React.useState('')
  const [note, setNote] = React.useState('')
  const [saving, setSaving] = React.useState(false)
  const [violationOpen, setViolationOpen] = React.useState(false)

  React.useEffect(() => {
    if (open) {
      setNext('')
      setNote('')
    }
  }, [open, date])

  // Decided on the server, the same way for both views; null when the day is
  // not in the review queue at all.
  const status = data?.day?.review_status ?? null
  const mayReview = can('attendance.review') && !reviewHref
  const day = data?.day
  const review = day?.review
  const noteRequired = Boolean(next) && (data?.note_required?.includes(next) || status === 'closed')

  async function saveReview(event) {
    event.preventDefault()
    if (!next) return
    setSaving(true)
    try {
      const response = await post(`/employees/${employeeId}/attendance/days/${date}/review`, { status: next, note: note || null })
      toast.success('Review saved', response.message)
      setNext('')
      setNote('')
      refetch()
      onChanged?.()
    } catch (err) {
      toast.error('Could not save the review', err.message)
    } finally {
      setSaving(false)
    }
  }

  const violationPrefill = React.useMemo(() => {
    if (!day) return null
    const kinds = review?.discrepancy_types?.length ? review.discrepancy_types : day.discrepancies
    const snapshot = data?.review_events?.at(-1)?.snapshot ?? {}
    const timeIn = snapshot.time_in ?? day.time_in
    const start = snapshot.schedule?.start ?? day.schedule?.start
    const lines = []

    if (kinds.includes('late')) {
      lines.push(`Timed in at ${timeIn} against a ${start} scheduled start (${formatMinutes(snapshot.late_minutes ?? day.late_minutes)} late), per the DTR for ${formatDate(day.date)}.`)
    } else {
      lines.push(`${kinds.map((k) => DISCREPANCIES[k]?.label ?? k).join(', ')} on ${formatDate(day.date)}, per the DTR compared with the schedule.`)
    }
    if (review?.latest_note) lines.push(`Verified: ${review.latest_note}`)

    return {
      violation_date: day.date,
      violation_type: kinds.includes('late') ? 'late' : kinds.includes('no_record') ? 'absences' : 'policy_violation',
      description: lines.join(' '),
    }
  }, [day, review, data])

  const mayRecordViolation =
    !reviewHref &&
    status === 'verified' &&
    !review?.employee_violation_id &&
    can('violations.create') &&
    data?.employee?.employment_status === 'active'

  return (
    <>
      <Dialog open={open && !violationOpen} onOpenChange={(value) => !saving && onOpenChange(value)}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>
              {data?.employee?.full_name ?? 'Attendance'} · {day ? `${day.weekday} ${formatDate(day.date)}` : formatDate(date)}
            </DialogTitle>
            <DialogDescription>
              The schedule beside the DTR for this day, and the review of any difference. A discrepancy is
              something to check, not a finding.
            </DialogDescription>
          </DialogHeader>

          {loading ? (
            <LoadingState label="Loading the day…" />
          ) : error ? (
            <ErrorState message={error.message} onRetry={refetch} />
          ) : day ? (
            <div className="space-y-5 text-sm">
              <dl className="grid gap-3 sm:grid-cols-3">
                <Fact label="Scheduled">
                  {shiftText(day.schedule)}
                  {day.schedule?.break_minutes ? (
                    <span className="block text-xs text-muted-foreground">
                      Break {day.schedule.break_start ? `${day.schedule.break_start}, ` : ''}
                      {day.schedule.break_minutes} min (not deducted)
                    </span>
                  ) : null}
                </Fact>
                <Fact label="DTR">
                  <span className="font-mono">
                    {day.time_in ?? '—'} – {day.time_out ?? '—'}
                    {day.time_out && day.time_out_next_day ? ' (+1)' : ''}
                  </span>
                  {data.record && (
                    <span className="block text-xs text-muted-foreground">
                      {SOURCE_LABEL[data.record.source] ?? data.record.source}
                      {data.record.import_code && ` · ${data.record.import_code}`}
                      {data.record.source_row && `, row ${data.record.source_row}`}
                    </span>
                  )}
                </Fact>
                <Fact label="Comparison">
                  <div className="flex flex-wrap gap-1.5">
                    {day.discrepancies.length > 0
                      ? day.discrepancies.map((type) => (
                          <Badge key={type} tone={DISCREPANCIES[type]?.tone ?? 'warning'}>
                            {discrepancyText(type, day)}
                          </Badge>
                        ))
                      : DAY_STATUS[day.status] && <Badge tone={DAY_STATUS[day.status].tone}>{DAY_STATUS[day.status].label}</Badge>}
                  </div>
                  {day.minutes_after_end > 0 && (
                    <span className="mt-1 block text-xs text-muted-foreground">
                      {formatMinutes(day.minutes_after_end)} after the scheduled end (information only)
                    </span>
                  )}
                </Fact>
              </dl>

              {day.exception && (
                <Notice>
                  {day.exception.label}
                  {day.exception.remarks && `: ${day.exception.remarks}`}
                </Notice>
              )}
              {day.status === 'awaiting_dtr' && (
                <Notice>
                  No DTR is on file for this day and the client's DTR for it has not been marked as received, so
                  there is not enough data to compare it.
                </Notice>
              )}
              {review && !day.still_flagged && (
                <Notice>
                  The current DTR and schedule no longer show a discrepancy on this day. The review stays open
                  until it is closed.
                </Notice>
              )}
              {day.remarks && <p className="text-xs text-muted-foreground">DTR remarks: {day.remarks}</p>}

              {status && (
                <section className="space-y-3 rounded-lg border p-4">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-semibold">Review</h3>
                    <Badge tone={REVIEW_STATUS[status]?.tone}>{REVIEW_STATUS[status]?.label}</Badge>
                  </div>
                  {review?.latest_note && <p className="text-muted-foreground">“{review.latest_note}”</p>}

                  {data.violation && (
                    <p className="flex items-center gap-1.5 text-xs">
                      <FileWarning className="h-3.5 w-3.5 text-warning" />
                      Recorded as a violation ({data.violation.violation_type.replace('_', ' ')},{' '}
                      {formatDate(data.violation.violation_date)}) — see the employee's Discipline tab.
                    </p>
                  )}

                  {reviewHref ? (
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="text-xs text-muted-foreground">
                        Review decisions are made in the Attendance (DTR) review queue.
                      </p>
                      <Button size="sm" asChild>
                        <Link to={reviewHref}>
                          <ClipboardCheck className="h-4 w-4" />
                          Open in review queue
                        </Link>
                      </Button>
                    </div>
                  ) : mayReview ? (
                    <form onSubmit={saveReview} className="space-y-3">
                      <Field label="Move the review to" htmlFor="review_status">
                        <Select id="review_status" value={next} onChange={(e) => setNext(e.target.value)}>
                          <option value="">Choose…</option>
                          {data.transitions.map((value) => (
                            <option key={value} value={value}>
                              {REVIEW_STATUS[value]?.label ?? value}
                            </option>
                          ))}
                        </Select>
                      </Field>
                      <Field
                        label={status === 'closed' ? 'Reason for reopening' : 'Explanation or finding'}
                        htmlFor="review_note"
                        required={noteRequired}
                        hint={noteRequired ? 'Required. Kept with this step of the review.' : 'Optional for this step.'}
                      >
                        <Textarea
                          value={note}
                          onChange={(e) => setNote(e.target.value)}
                          placeholder="e.g. Client supervisor confirmed the worker clocked out at 17:00; the punch was missed by the device."
                        />
                      </Field>
                      <div className="flex flex-wrap justify-end gap-2">
                        {mayRecordViolation && (
                          <Button type="button" variant="outline" onClick={() => setViolationOpen(true)}>
                            <FileWarning className="h-4 w-4" />
                            Record violation
                          </Button>
                        )}
                        <Button type="submit" disabled={!next || saving || (noteRequired && !note.trim())}>
                          {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                          Save review
                        </Button>
                      </div>
                      {status === 'verified' && !review?.employee_violation_id && (
                        <p className="text-xs text-muted-foreground">
                          Verified does not record a violation. If this needs formal action, record it as a
                          violation; otherwise close the review with the outcome.
                        </p>
                      )}
                    </form>
                  ) : (
                    <p className="text-xs text-muted-foreground">You can view this review but not change it.</p>
                  )}
                </section>
              )}

              {data.review_events.length > 0 && (
                <Timeline title="Review history">
                  {data.review_events.map((event) => (
                    <li key={event.id}>
                      <p className="font-medium">{event.to_label}</p>
                      {event.note && <p className="text-muted-foreground">{event.note}</p>}
                      <p className="text-xs text-muted-foreground">
                        {event.by} · {formatDateTime(event.at)}
                      </p>
                    </li>
                  ))}
                </Timeline>
              )}

              {data.revisions.length > 0 && (
                <Timeline title="DTR changes for this day">
                  {data.revisions.map((revision) => (
                    <li key={revision.id}>
                      <p className="font-medium capitalize">
                        {revision.action}
                        {revision.import_code && ` from ${revision.import_code}`}
                      </p>
                      <p className="font-mono text-xs">
                        {revision.action !== 'created' &&
                          `${revision.old.time_in ?? '—'}–${revision.old.time_out ?? '—'} → `}
                        {revision.action === 'cleared' ? 'cleared' : `${revision.new.time_in ?? '—'}–${revision.new.time_out ?? '—'}`}
                      </p>
                      {revision.reason && <p className="text-muted-foreground">{revision.reason}</p>}
                      <p className="text-xs text-muted-foreground">
                        {revision.by} · {formatDateTime(revision.at)}
                      </p>
                    </li>
                  ))}
                </Timeline>
              )}
            </div>
          ) : null}

          <DialogFooter className="gap-2">
            {employeeHref && (
              <Button variant="ghost" asChild className="mr-auto">
                <Link to={employeeHref}>
                  <UserRound className="h-4 w-4" />
                  Open employee's DTR
                </Link>
              </Button>
            )}
            <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ViolationDialog
        open={violationOpen}
        onOpenChange={setViolationOpen}
        employeeId={employeeId}
        initial={violationPrefill}
        attendanceReviewId={review?.id}
        onSaved={() => {
          refetch()
          onChanged?.()
        }}
      />
    </>
  )
}

function Fact({ label, children }) {
  return (
    <div className="rounded-md border bg-card px-3 py-2">
      <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="mt-0.5">{children}</dd>
    </div>
  )
}

function Notice({ children }) {
  return (
    <div className="flex gap-2.5 rounded-lg border bg-muted/40 px-3 py-2.5 text-xs text-muted-foreground">
      <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
      <p>{children}</p>
    </div>
  )
}

function Timeline({ title, children }) {
  return (
    <section className="space-y-2">
      <h3 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        <HistoryIcon className="h-3.5 w-3.5" />
        {title}
      </h3>
      <ol className="space-y-2.5 border-l pl-4 text-sm">{children}</ol>
    </section>
  )
}
