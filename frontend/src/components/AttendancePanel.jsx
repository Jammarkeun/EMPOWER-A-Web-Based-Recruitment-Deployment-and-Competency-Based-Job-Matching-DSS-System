import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { CalendarClock, ChevronLeft, ChevronRight, Loader2, Pencil, Save, X, Info, CalendarOff, Ban, ClipboardCheck } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useApi } from '@/hooks/useApi'
import { patch, post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input, NumberInput } from '@/components/ui/input'
import { Field, FormGrid } from '@/components/ui/form'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import AttendanceDayDialog from '@/components/attendance/AttendanceDayDialog'
import ExceptionDialog from '@/components/attendance/ExceptionDialog'
import {
  DAY_STATUS,
  DISCREPANCIES,
  REVIEW_STATUS,
  WEEKDAYS,
  currentMonth,
  discrepancyText,
  formatMinutes,
  monthBounds,
  monthParam,
  reviewQueuePath,
  shiftMonth,
  shiftText,
} from '@/lib/attendance'
import { cn, formatDate, formatDateTime, todayISO } from '@/lib/utils'

/** Kept for screens that imported it from here. */
export { formatMinutes }

const PERIOD_STATUS = {
  current: ['Current', 'success'],
  upcoming: ['Upcoming', 'info'],
  ended: ['Ended', 'muted'],
  voided: ['Voided', 'destructive'],
}

/**
 * One employee's schedule set beside their DTR, a month at a time.
 *
 * The individual view of the one attendance system. It shows this employee's
 * schedule, each day's DTR against it, and where every flagged day stands in
 * review - all read from the same endpoints and comparison as the
 * Attendance (DTR) page. The per-employee entry that belongs to one person
 * stays here: the schedule, typing in their DTR from the client's sheet, and
 * their leave. Review decisions do not: a flagged day links to that exact day
 * in the central review queue, so there is one place where they are made.
 *
 * The month lives in the address bar (`?month=`), so a link from the review
 * queue or the workforce summary opens on the period it came from.
 *
 * A day with nothing on file is "awaiting DTR" until the client's DTR for it
 * is known to have been received - it is never counted as an absence. Nothing
 * here is pay, and nothing here records a violation.
 */
export default function AttendancePanel({ employee }) {
  const { can } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const month = monthParam(searchParams.get('month'))

  function setMonth(value) {
    const next = new URLSearchParams(searchParams)
    if (value === currentMonth()) next.delete('month')
    else next.set('month', value)
    setSearchParams(next, { replace: true })
  }

  const [editing, setEditing] = React.useState(false)
  const [scheduleDialog, setScheduleDialog] = React.useState(null)
  const [voiding, setVoiding] = React.useState(null)
  const [exceptionOpen, setExceptionOpen] = React.useState(false)
  const [openDay, setOpenDay] = React.useState(null)

  const period = monthBounds(month)
  const { data, loading, refreshing, error, refetch } = useApi(`/employees/${employee.id}/attendance`, period)

  const mayManage = can('attendance.manage')

  if (loading) return <LoadingState label="Loading attendance…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const { summary, days, policy, current_schedule: schedule, schedules, schedule_changes: changes, exceptions, review_counts: reviewCounts } = data
  const grace = policy?.late_grace_minutes ?? 0
  const latest = schedules.find((s) => s.status === 'active')

  return (
    <div className="space-y-4">
      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader className="pb-3">
            <div className="flex items-start justify-between gap-2">
              <div className="space-y-1">
                <CardTitle className="flex items-center gap-2">
                  <CalendarClock className="h-4 w-4 text-muted-foreground" />
                  Schedule
                </CardTitle>
                <CardDescription>What the client set this employee to work.</CardDescription>
              </div>
              {mayManage && employee.employment_status === 'active' && (
                <Button variant="outline" size="sm" onClick={() => setScheduleDialog({ mode: 'new' })}>
                  {schedule ? 'Change' : 'Set schedule'}
                </Button>
              )}
            </div>
          </CardHeader>
          <CardContent className="space-y-3 text-sm">
            {schedule ? (
              <div>
                <p className="font-medium">{schedule.description}</p>
                {schedule.break_minutes ? (
                  <p className="text-xs text-muted-foreground">
                    Break {schedule.break_start ? `from ${schedule.break_start}, ` : ''}
                    {schedule.break_minutes} min — shown, not deducted
                  </p>
                ) : null}
                <p className="text-xs text-muted-foreground">
                  Since {formatDate(schedule.effective_from)}
                  {schedule.company && ` · ${schedule.company}`}
                  {schedule.department && `, ${schedule.department}`}
                  {schedule.set_by && ` · set by ${schedule.set_by}`}
                </p>
                {schedule.remarks && <p className="mt-1 text-xs text-muted-foreground">{schedule.remarks}</p>}
                {mayManage && (
                  <div className="mt-2 flex gap-1.5">
                    <Button variant="ghost" size="sm" onClick={() => setScheduleDialog({ mode: 'correct', schedule })}>
                      <Pencil className="h-3.5 w-3.5" />
                      Correct
                    </Button>
                    {latest?.id === schedule.id && (
                      <Button variant="ghost" size="sm" onClick={() => setVoiding(schedule)}>
                        <Ban className="h-3.5 w-3.5" />
                        Void
                      </Button>
                    )}
                  </div>
                )}
              </div>
            ) : (
              <p className="text-muted-foreground">
                No schedule in force today. Without one, the DTR has nothing to be compared with.
              </p>
            )}

            {schedules.filter((s) => s.id !== schedule?.id).length > 0 && (
              <details className="text-xs">
                <summary className="cursor-pointer text-muted-foreground">Schedule history</summary>
                <ul className="mt-2 space-y-2">
                  {schedules
                    .filter((s) => s.id !== schedule?.id)
                    .map((s) => (
                      <li key={s.id} className={cn('text-muted-foreground', s.status === 'voided' && 'line-through decoration-1')}>
                        <Badge tone={PERIOD_STATUS[s.period_status]?.[1]} className="mr-1.5 no-underline">
                          {PERIOD_STATUS[s.period_status]?.[0]}
                        </Badge>
                        <span className="text-foreground">{s.description}</span> · {formatDate(s.effective_from)} –{' '}
                        {formatDate(s.effective_to, 'now')}
                        {s.void_reason && <span className="block no-underline">Voided: {s.void_reason}</span>}
                      </li>
                    ))}
                </ul>
              </details>
            )}

            {changes.length > 0 && (
              <details className="text-xs">
                <summary className="cursor-pointer text-muted-foreground">Corrections ({changes.length})</summary>
                <ul className="mt-2 space-y-2">
                  {changes.map((change, i) => (
                    <li key={i} className="text-muted-foreground">
                      <span className="text-foreground">{change.action === 'void' ? 'Voided' : 'Corrected'}</span>
                      {change.action !== 'void' &&
                        Object.keys(change.after ?? {}).map((field) => (
                          <span key={field} className="block">
                            {field.replace('_', ' ')}: {String(change.before?.[field] ?? '—')} → {String(change.after[field] ?? '—')}
                          </span>
                        ))}
                      {change.reason && <span className="block">“{change.reason}”</span>}
                      <span className="block">
                        {change.by} · {formatDateTime(change.at)}
                      </span>
                    </li>
                  ))}
                </ul>
              </details>
            )}
          </CardContent>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader className="pb-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="space-y-1">
                <CardTitle>DTR against schedule</CardTitle>
                <CardDescription>
                  {formatDate(period.from)} – {formatDate(period.to)}
                  {refreshing && <Loader2 className="ml-2 inline h-3 w-3 animate-spin" />}
                </CardDescription>
              </div>
              <div className="flex items-center gap-1">
                <Button variant="ghost" size="icon" onClick={() => setMonth(shiftMonth(month, -1))} aria-label="Previous month" disabled={editing}>
                  <ChevronLeft className="h-4 w-4" />
                </Button>
                <Input
                  type="month"
                  value={month}
                  onChange={(e) => e.target.value && setMonth(e.target.value)}
                  className="h-8 w-36"
                  aria-label="Month"
                  disabled={editing}
                />
                <Button variant="ghost" size="icon" onClick={() => setMonth(shiftMonth(month, 1))} aria-label="Next month" disabled={editing}>
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-3">
            <DataStatus summary={summary} />
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <Tile label="Scheduled days" value={summary.scheduled_days} />
              <Tile label="On schedule" value={summary.on_schedule_days} />
              <Tile label="Discrepancies" value={summary.discrepancy_days} tone={summary.discrepancy_days ? 'warning' : null} />
              <Tile label="Awaiting DTR" value={summary.awaiting_dtr_days} />
              <Tile
                label="Late"
                value={summary.late_days}
                hint={summary.late_minutes ? formatMinutes(summary.late_minutes) : null}
                tone={summary.late_days ? 'warning' : null}
              />
              <Tile
                label="Early departure"
                value={summary.early_departure_days}
                hint={summary.early_departure_minutes ? formatMinutes(summary.early_departure_minutes) : null}
                tone={summary.early_departure_days ? 'warning' : null}
              />
              <Tile label="Missing punches" value={summary.missing_punch_days} tone={summary.missing_punch_days ? 'destructive' : null} />
              <Tile label="No DTR record" value={summary.no_record_days} tone={summary.no_record_days ? 'destructive' : null} />
            </dl>
          </CardContent>
        </Card>
      </div>

      <div className="flex gap-2.5 rounded-lg border bg-muted/40 px-3 py-2.5 text-xs text-muted-foreground">
        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
        <p>
          Late is counted from the scheduled start
          {grace > 0 ? `, once past the ${grace}-minute allowance set in Settings` : ' to the minute (no grace period is set — CDE has not stated one)'}.
          A day with nothing on file is <strong>awaiting DTR</strong>, not an absence; it becomes “No DTR record” only once
          the client's DTR for it has been imported or marked complete. Time after the scheduled end is information,
          not approved overtime. Breaks are shown, never deducted. This system does not connect to the biometric device.
        </p>
      </div>

      {exceptions.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <span className="text-muted-foreground">Leave and holidays this month:</span>
          {exceptions.map((e) => (
            <Badge key={e.id} tone="info">
              {e.label} {formatDate(e.date_from)}
              {e.date_to !== e.date_from && ` – ${formatDate(e.date_to)}`}
              {e.scope === 'client' && ' (whole client)'}
            </Badge>
          ))}
        </div>
      )}

      {editing ? (
        <DtrEditor
          employee={employee}
          days={days}
          period={period}
          onCancel={() => setEditing(false)}
          onSaved={() => {
            setEditing(false)
            refetch()
          }}
        />
      ) : (
        <Card>
          <CardHeader className="pb-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <CardTitle className="text-base">Day by day</CardTitle>
              <div className="flex flex-wrap gap-2">
                {reviewCounts?.open > 0 && (
                  <Button size="sm" variant="outline" asChild>
                    <Link to={reviewQueuePath(employee.id, month)}>
                      <ClipboardCheck className="h-3.5 w-3.5" />
                      {reviewCounts.open} open in the review queue
                    </Link>
                  </Button>
                )}
              {mayManage && (
                <div className="flex gap-2">
                  <Button size="sm" variant="outline" onClick={() => setExceptionOpen(true)}>
                    <CalendarOff className="h-3.5 w-3.5" />
                    Record leave
                  </Button>
                  <Button size="sm" onClick={() => setEditing(true)}>
                    <Pencil className="h-3.5 w-3.5" />
                    Encode DTR
                  </Button>
                </div>
              )}
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {!schedules.length && !days.some((d) => d.has_record) ? (
              <div className="p-5 pt-0">
                <EmptyState
                  icon={CalendarClock}
                  title="No schedule and no DTR yet"
                  description="Set this employee's schedule, then import or encode their DTR from the client's time records to compare the two."
                />
              </div>
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Date</TableHead>
                      <TableHead>Scheduled</TableHead>
                      <TableHead>Time in</TableHead>
                      <TableHead>Time out</TableHead>
                      <TableHead>Comparison</TableHead>
                      <TableHead>Review</TableHead>
                      <TableHead className="w-0" />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {days.map((day) => {
                      const status = DAY_STATUS[day.status]
                      const quiet = ['outside_employment', 'upcoming', 'rest_day', 'no_schedule', 'awaiting_dtr'].includes(day.status)
                      const reviewStatus = REVIEW_STATUS[day.review_status]

                      return (
                        <TableRow key={day.date} className={cn(quiet && 'text-muted-foreground')}>
                          <TableCell className="whitespace-nowrap">
                            <span className="inline-block w-9 text-xs text-muted-foreground">{day.weekday}</span>
                            {formatDate(day.date)}
                          </TableCell>
                          <TableCell className="whitespace-nowrap text-sm">{shiftText(day.schedule)}</TableCell>
                          <TableCell className="font-mono text-sm">{day.time_in ?? '—'}</TableCell>
                          <TableCell className="font-mono text-sm">
                            {day.time_out ?? '—'}
                            {day.time_out && day.time_out_next_day && <span className="ml-1 text-xs text-muted-foreground">(+1)</span>}
                          </TableCell>
                          <TableCell>
                            <div className="flex flex-wrap items-center gap-1.5">
                              {day.status !== 'discrepancy' && status && (
                                <Badge tone={status.tone}>{day.exception?.label ?? status.label}</Badge>
                              )}
                              {day.discrepancies.map((type) => (
                                <Badge key={type} tone={DISCREPANCIES[type]?.tone ?? 'warning'}>
                                  {discrepancyText(type, day)}
                                </Badge>
                              ))}
                              {day.minutes_after_end > 0 && (
                                <span className="text-xs text-muted-foreground">+{formatMinutes(day.minutes_after_end)} after end</span>
                              )}
                              {day.source === 'import' && (
                                <span className="text-xs text-muted-foreground">{day.import_code}</span>
                              )}
                              {day.remarks && <span className="text-xs text-muted-foreground">{day.remarks}</span>}
                            </div>
                          </TableCell>
                          <TableCell>
                            {reviewStatus && <Badge tone={reviewStatus.tone}>{reviewStatus.label}</Badge>}
                          </TableCell>
                          <TableCell className="text-right">
                            {(reviewStatus || day.has_record) && (
                              <Button variant="ghost" size="sm" onClick={() => setOpenDay(day.date)}>
                                Details
                              </Button>
                            )}
                          </TableCell>
                        </TableRow>
                      )
                    })}
                  </TableBody>
                </Table>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      <ScheduleDialog
        state={scheduleDialog}
        onClose={() => setScheduleDialog(null)}
        employee={employee}
        current={schedule}
        onSaved={refetch}
      />
      <VoidScheduleDialog
        schedule={voiding}
        employee={employee}
        onClose={() => setVoiding(null)}
        onSaved={refetch}
      />
      <ExceptionDialog open={exceptionOpen} onOpenChange={setExceptionOpen} employee={employee} onSaved={refetch} />
      <AttendanceDayDialog
        key={openDay ?? 'none'}
        open={openDay !== null}
        onOpenChange={(value) => !value && setOpenDay(null)}
        employeeId={employee.id}
        date={openDay}
        reviewHref={openDay ? reviewQueuePath(employee.id, month, openDay) : undefined}
      />
    </div>
  )
}

/** Says plainly when there is not enough data for the figures to mean anything. */
function DataStatus({ summary }) {
  const message = {
    no_dtr: 'No DTR is on file for this period yet. Until the client’s DTR is imported or encoded, these figures say nothing about this employee’s attendance.',
    no_schedule: 'No schedule covers this period, so the DTR cannot be compared with anything.',
    partial: `${summary.awaiting_dtr_days + summary.no_schedule_days} day(s) in this period cannot be compared yet — awaiting the DTR or without a schedule.`,
  }[summary.data_status]

  if (!message) return null

  return (
    <div className="flex gap-2.5 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2 text-xs">
      <Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
      <p>{message}</p>
    </div>
  )
}

function Tile({ label, value, hint, tone }) {
  return (
    <div className="rounded-md border bg-card px-3 py-2">
      <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd
        className={cn(
          'mt-0.5 text-lg font-semibold tabular-nums',
          tone === 'destructive' && 'text-destructive',
          tone === 'warning' && 'text-warning'
        )}
      >
        {value ?? 0}
        {hint && <span className="ml-1.5 text-xs font-normal text-muted-foreground">{hint}</span>}
      </dd>
    </div>
  )
}

/**
 * Copying a DTR sheet in: every day of the period as a row of inputs.
 *
 * Only the rows that were changed are sent. Changing or clearing a day already
 * on file asks for a reason; the earlier times are kept in that day's history.
 * Ticking "complete" tells the comparison the client's DTR for the month has
 * been read in full, so a scheduled day still blank had no time record.
 */
function DtrEditor({ employee, days, period, onCancel, onSaved }) {
  const toast = useToast()
  const editable = days.filter((d) => !['outside_employment', 'upcoming'].includes(d.status))

  const initial = React.useMemo(
    () =>
      Object.fromEntries(
        editable.map((d) => [d.date, { time_in: d.time_in ?? '', time_out: d.time_out ?? '', remarks: d.remarks ?? '' }])
      ),
    [days]
  )

  const [rows, setRows] = React.useState(initial)
  const [reason, setReason] = React.useState('')
  const [complete, setComplete] = React.useState(false)
  const [saving, setSaving] = React.useState(false)
  const [errors, setErrors] = React.useState({})

  const changed = Object.keys(rows).filter((date) => JSON.stringify(rows[date]) !== JSON.stringify(initial[date]))
  const touchesExisting = changed.filter((date) => days.find((d) => d.date === date)?.has_record).length

  function set(date, field, value) {
    setRows((current) => ({ ...current, [date]: { ...current[date], [field]: value } }))
  }

  async function save() {
    if (changed.length === 0 && !complete) {
      onCancel()
      return
    }

    setSaving(true)
    setErrors({})
    try {
      const response = await post(`/employees/${employee.id}/attendance`, {
        entries: changed.map((date) => ({
          work_date: date,
          time_in: rows[date].time_in || null,
          time_out: rows[date].time_out || null,
          remarks: rows[date].remarks || null,
        })),
        reason: reason || null,
        ...(complete ? { complete_from: period.from, complete_to: period.to > todayISO() ? todayISO() : period.to } : {}),
      })
      toast.success('DTR saved', response.message)
      onSaved()
    } catch (error) {
      if (error.isValidation && Object.keys(error.errors).length) {
        setErrors(error.errors)
        toast.error('Some entries need fixing', 'Times are 24-hour HH:MM.')
      } else {
        toast.error('Could not save the DTR', error.message)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card className="border-primary/40">
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="space-y-1">
            <CardTitle className="text-base">Encode DTR</CardTitle>
            <CardDescription>
              Copy the times from the client’s DTR. A time-out earlier than the time-in is read as the next day.
              Leave a row blank for a day with no record.
            </CardDescription>
          </div>
          <div className="flex gap-2">
            <Button variant="ghost" size="sm" onClick={onCancel} disabled={saving}>
              <X className="h-3.5 w-3.5" />
              Cancel
            </Button>
            <Button size="sm" onClick={save} disabled={saving || (touchesExisting > 0 && !reason.trim())}>
              {saving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Save className="h-3.5 w-3.5" />}
              Save {changed.length ? `${changed.length} day${changed.length === 1 ? '' : 's'}` : ''}
            </Button>
          </div>
        </div>
        <div className="grid gap-3 pt-2 sm:grid-cols-2">
          {touchesExisting > 0 && (
            <Field
              label={`Reason for changing ${touchesExisting} day(s) already on file`}
              htmlFor="dtr_reason"
              required
              hint="The earlier times are kept with this reason."
            >
              <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Corrected from the client's signed DTR" />
            </Field>
          )}
          <label className="flex cursor-pointer items-start gap-2 text-xs text-muted-foreground sm:col-span-2">
            <input
              type="checkbox"
              checked={complete}
              onChange={(e) => setComplete(e.target.checked)}
              className="mt-0.5 h-4 w-4 cursor-pointer rounded border-input accent-primary"
            />
            <span>
              The client’s DTR for {formatDate(period.from)} – {formatDate(period.to)} has been read in full. Scheduled days
              left blank had no time record and will be flagged “No DTR record” for review.
            </span>
          </label>
        </div>
      </CardHeader>
      <CardContent className="p-0">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Date</TableHead>
                <TableHead>Scheduled</TableHead>
                <TableHead>Time in</TableHead>
                <TableHead>Time out</TableHead>
                <TableHead>Remarks</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {editable.map((day, index) => {
                const row = rows[day.date]
                const rowErrors = Object.entries(errors)
                  .filter(([key]) => key.startsWith(`entries.${changed.indexOf(day.date)}.`))
                  .map(([, messages]) => messages[0])

                return (
                  <TableRow key={day.date} className={cn(changed.includes(day.date) && '[&>td]:!bg-inset-selected')}>
                    <TableCell className="whitespace-nowrap">
                      <span className="inline-block w-9 text-xs text-muted-foreground">{day.weekday}</span>
                      {formatDate(day.date)}
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                      {day.schedule ? shiftText(day.schedule) : day.exception?.label ?? 'Rest day'}
                    </TableCell>
                    <TableCell>
                      <Input
                        type="time"
                        value={row.time_in}
                        onChange={(e) => set(day.date, 'time_in', e.target.value)}
                        className="h-8 w-28"
                        aria-label={`Time in, ${day.date}`}
                        tabIndex={index * 3 + 1}
                      />
                    </TableCell>
                    <TableCell>
                      <Input
                        type="time"
                        value={row.time_out}
                        onChange={(e) => set(day.date, 'time_out', e.target.value)}
                        className="h-8 w-28"
                        aria-label={`Time out, ${day.date}`}
                        tabIndex={index * 3 + 2}
                      />
                    </TableCell>
                    <TableCell>
                      <Input
                        value={row.remarks}
                        onChange={(e) => set(day.date, 'remarks', e.target.value)}
                        className="h-8 min-w-[10rem]"
                        placeholder="Optional"
                        aria-label={`Remarks, ${day.date}`}
                        tabIndex={index * 3 + 3}
                      />
                      {rowErrors.length > 0 && <p className="mt-1 text-xs text-destructive">{rowErrors[0]}</p>}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>
      </CardContent>
    </Card>
  )
}

/**
 * A new schedule from a date (the old one closes the day before), or a
 * correction of one entered wrongly, which keeps its dates and needs a reason.
 */
function ScheduleDialog({ state, onClose, employee, current, onSaved }) {
  const toast = useToast()
  const today = todayISO()
  const open = state !== null
  const correcting = state?.mode === 'correct'
  const target = state?.schedule ?? current

  const blank = React.useCallback(
    () => ({
      effective_from: current ? today : employee.hire_date ?? today,
      work_days: target?.work_days ?? [1, 2, 3, 4, 5],
      shift_start: target?.shift_start ?? '08:00',
      shift_end: target?.shift_end ?? '17:00',
      break_start: target?.break_start ?? '',
      break_minutes: target?.break_minutes ?? '',
      remarks: correcting ? target?.remarks ?? '' : '',
      reason: '',
    }),
    [current, target, correcting, employee.hire_date, today]
  )

  const [form, setForm] = React.useState(blank)
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(false)

  React.useEffect(() => {
    if (open) {
      setForm(blank())
      setErrors({})
    }
  }, [open, blank])

  const crossesMidnight = form.shift_end && form.shift_start && form.shift_end <= form.shift_start

  function toggleDay(day) {
    setForm((f) => ({
      ...f,
      work_days: f.work_days.includes(day) ? f.work_days.filter((d) => d !== day) : [...f.work_days, day].sort(),
    }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    const body = {
      work_days: form.work_days,
      shift_start: form.shift_start,
      shift_end: form.shift_end,
      break_start: form.break_start || null,
      break_minutes: form.break_minutes === '' ? null : Number(form.break_minutes),
      remarks: form.remarks || null,
    }
    try {
      if (correcting) {
        await patch(`/employees/${employee.id}/schedules/${target.id}`, { ...body, reason: form.reason })
        toast.success('Schedule corrected', 'The old values are kept in its correction history.')
      } else {
        await post(`/employees/${employee.id}/schedules`, { ...body, effective_from: form.effective_from })
        toast.success('Schedule saved', 'The DTR is now compared against it from the date it takes effect.')
      }
      onClose()
      onSaved()
    } catch (error) {
      if (error.isValidation && Object.keys(error.errors).length) setErrors(error.errors)
      else toast.error('Could not save the schedule', error.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !saving && !next && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{correcting ? 'Correct schedule' : current ? 'Change schedule' : 'Set schedule'}</DialogTitle>
          <DialogDescription>
            {correcting
              ? `For a schedule entered wrongly. Its dates stay; the old values and your reason are kept. Every day from ${formatDate(target?.effective_from)} is then compared with the corrected times, including days already reviewed — their reviews keep what was shown at the time. To change hours from a date onward, use Change instead.`
              : current
                ? 'The current schedule ends the day before this one starts, so earlier days are still compared with the schedule that applied to them.'
                : 'Use the schedule the client company gave this worker.'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          {!correcting && (
            <Field label="Takes effect" htmlFor="effective_from" required error={errors.effective_from?.[0]}>
              <Input
                type="date"
                value={form.effective_from}
                min={employee.hire_date ?? undefined}
                onChange={(e) => setForm({ ...form, effective_from: e.target.value })}
              />
            </Field>
          )}

          <Field label="Work days" htmlFor="work_days" required error={errors.work_days?.[0]}>
            <div className="flex flex-wrap gap-1.5">
              {WEEKDAYS.map(([value, label]) => (
                <button
                  key={value}
                  type="button"
                  onClick={() => toggleDay(value)}
                  aria-pressed={form.work_days.includes(value)}
                  className={cn(
                    'h-8 w-12 rounded-control border text-xs font-medium transition-colors',
                    form.work_days.includes(value)
                      ? 'border-primary bg-primary text-primary-foreground'
                      : 'bg-card text-muted-foreground hover:bg-accent'
                  )}
                >
                  {label}
                </button>
              ))}
            </div>
          </Field>

          <FormGrid>
            <Field label="Shift starts" htmlFor="shift_start" required error={errors.shift_start?.[0]}>
              <Input type="time" value={form.shift_start} onChange={(e) => setForm({ ...form, shift_start: e.target.value })} />
            </Field>
            <Field
              label="Shift ends"
              htmlFor="shift_end"
              required
              error={errors.shift_end?.[0]}
              hint={crossesMidnight ? 'Ends the next day (night shift).' : undefined}
            >
              <Input type="time" value={form.shift_end} onChange={(e) => setForm({ ...form, shift_end: e.target.value })} />
            </Field>
          </FormGrid>

          <FormGrid>
            <Field label="Break starts" htmlFor="break_start" error={errors.break_start?.[0]} hint="Optional.">
              <Input type="time" value={form.break_start} onChange={(e) => setForm({ ...form, break_start: e.target.value })} />
            </Field>
            <Field label="Break length (minutes)" htmlFor="break_minutes" error={errors.break_minutes?.[0]} hint="Shown only; never deducted.">
              <NumberInput
                value={form.break_minutes}
                onChange={(e) => setForm({ ...form, break_minutes: e.target.value })}
                min={1}
                max={600}
              />
            </Field>
          </FormGrid>

          <Field label="Remarks" htmlFor="schedule_remarks" error={errors.remarks?.[0]}>
            <Input
              value={form.remarks}
              onChange={(e) => setForm({ ...form, remarks: e.target.value })}
              placeholder="e.g. Moved to the night shift at the client's request"
            />
          </Field>

          {correcting && (
            <Field label="Reason for the correction" htmlFor="schedule_reason" required error={errors.reason?.[0]}>
              <Input
                value={form.reason}
                onChange={(e) => setForm({ ...form, reason: e.target.value })}
                placeholder="e.g. Shift start mistyped; the client sheet says 07:00"
              />
            </Field>
          )}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" disabled={saving || form.work_days.length === 0 || (correcting && form.reason.trim().length < 5)}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              {correcting ? 'Save correction' : 'Save schedule'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function VoidScheduleDialog({ schedule, employee, onClose, onSaved }) {
  const toast = useToast()
  const [reason, setReason] = React.useState('')
  const [saving, setSaving] = React.useState(false)

  React.useEffect(() => setReason(''), [schedule])

  async function submit(event) {
    event.preventDefault()
    setSaving(true)
    try {
      await post(`/employees/${employee.id}/schedules/${schedule.id}/void`, { reason })
      toast.success('Schedule voided', 'It is kept on file and no longer compared against.')
      onClose()
      onSaved()
    } catch (error) {
      toast.error('Could not void the schedule', error.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={schedule !== null} onOpenChange={(next) => !saving && !next && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Void this schedule?</DialogTitle>
          <DialogDescription>
            For a schedule entered in error. It stays on file, marked voided, and the schedule before it runs on
            in its place. To change hours from a date, set a new schedule instead.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <p className="text-sm font-medium">{schedule?.description}</p>
          <Field label="Reason" htmlFor="void_reason" required>
            <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Entered against the wrong employee" />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" variant="destructive" disabled={saving || reason.trim().length < 5}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              Void schedule
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
