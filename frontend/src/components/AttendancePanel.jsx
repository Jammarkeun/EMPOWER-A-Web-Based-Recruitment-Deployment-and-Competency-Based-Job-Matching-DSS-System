import * as React from 'react'
import { CalendarClock, ChevronLeft, ChevronRight, Loader2, Pencil, Save, X, FileWarning, Info } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useApi } from '@/hooks/useApi'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
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
import { cn, formatDate, todayISO } from '@/lib/utils'

const WEEKDAYS = [
  [1, 'Mon'],
  [2, 'Tue'],
  [3, 'Wed'],
  [4, 'Thu'],
  [5, 'Fri'],
  [6, 'Sat'],
  [7, 'Sun'],
]

/** "1h 15m", "25m", or a dash. */
export function formatMinutes(minutes) {
  if (!minutes) return '—'
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  if (!hours) return `${rest}m`
  return rest ? `${hours}h ${rest}m` : `${hours}h`
}

function monthBounds(month) {
  const [year, m] = month.split('-').map(Number)
  const last = new Date(year, m, 0).getDate()
  return {
    from: `${month}-01`,
    to: `${month}-${String(last).padStart(2, '0')}`,
  }
}

function shiftMonth(month, delta) {
  const [year, m] = month.split('-').map(Number)
  const date = new Date(year, m - 1 + delta, 1)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

function currentMonth() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

const STATUS = {
  present: { label: 'On schedule', tone: 'success' },
  absent: { label: 'Absent', tone: 'destructive' },
  rest_day: { label: 'Rest day', tone: 'muted' },
  rest_day_work: { label: 'Worked on rest day', tone: 'info' },
  no_schedule: { label: 'No schedule', tone: 'muted' },
  no_schedule_work: { label: 'Worked, no schedule', tone: 'warning' },
  awaiting_record: { label: 'No record yet', tone: 'muted' },
  outside_employment: { label: 'Not employed', tone: 'muted' },
  upcoming: { label: 'Upcoming', tone: 'muted' },
}

function flagBadges(day) {
  const labels = {
    late: [`Late ${formatMinutes(day.late_minutes)}`, 'warning'],
    undertime: [`Left early ${formatMinutes(day.undertime_minutes)}`, 'warning'],
    overtime: [`Past end ${formatMinutes(day.overtime_minutes)}`, 'info'],
    missing_time_in: ['No time-in', 'destructive'],
    missing_time_out: ['No time-out', 'destructive'],
  }

  return day.flags
    .filter((flag) => labels[flag])
    .map((flag) => (
      <Badge key={flag} tone={labels[flag][1]}>
        {labels[flag][0]}
      </Badge>
    ))
}

/**
 * The daily time record, set beside the schedule it should have followed.
 *
 * The review panel asked for the DTR to be compared with the schedule. Workers
 * clock in on the client's own biometrics; HR copies the resulting DTR in here,
 * a period at a time, and the comparison is worked out from what is on file -
 * so correcting a time or a schedule corrects the comparison too.
 *
 * It reports and stops. Late, left early, stayed past the end, missing a
 * punch, absent: each is shown, none is acted on. A violation is still a
 * separate decision somebody makes (the row offers to start one, already
 * filled in), and nothing on this screen is pay.
 */
export default function AttendancePanel({ employee, onRecordViolation }) {
  const { can } = useAuth()
  const [month, setMonth] = React.useState(currentMonth)
  const [editing, setEditing] = React.useState(false)
  const [scheduleOpen, setScheduleOpen] = React.useState(false)

  const period = monthBounds(month)
  const { data, loading, refreshing, error, refetch } = useApi(`/employees/${employee.id}/attendance`, period)

  const mayManage = can('attendance.manage')
  const mayRecordViolation = can('violations.create') && employee.employment_status === 'active'

  if (loading) return <LoadingState label="Loading attendance…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const { summary, days, policy, current_schedule: schedule, schedules } = data
  const grace = policy?.late_grace_minutes ?? 0

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
                <CardDescription>What this employee is meant to work.</CardDescription>
              </div>
              {mayManage && (
                <Button variant="outline" size="sm" onClick={() => setScheduleOpen(true)}>
                  {schedule ? 'Change' : 'Set schedule'}
                </Button>
              )}
            </div>
          </CardHeader>
          <CardContent className="space-y-3 text-sm">
            {schedule ? (
              <div>
                <p className="font-medium">{schedule.description}</p>
                <p className="text-xs text-muted-foreground">
                  Since {formatDate(schedule.effective_from)}
                  {schedule.set_by && ` · set by ${schedule.set_by}`}
                </p>
                {schedule.remarks && <p className="mt-1 text-xs text-muted-foreground">{schedule.remarks}</p>}
              </div>
            ) : (
              <p className="text-muted-foreground">
                No schedule in force today. Without one, the DTR has nothing to be compared with.
              </p>
            )}

            {schedules.length > 1 && (
              <details className="text-xs">
                <summary className="cursor-pointer text-muted-foreground">Earlier schedules</summary>
                <ul className="mt-2 space-y-1.5">
                  {schedules
                    .filter((s) => s.id !== schedule?.id)
                    .map((s) => (
                      <li key={s.id} className="text-muted-foreground">
                        <span className="text-foreground">{s.description}</span> ·{' '}
                        {formatDate(s.effective_from)} – {formatDate(s.effective_to, 'now')}
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
          <CardContent>
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <Tile label="Scheduled days" value={summary.scheduled_days} />
              <Tile label="Present" value={summary.days_present} />
              <Tile label="Absent" value={summary.days_absent} tone={summary.days_absent ? 'destructive' : null} />
              <Tile
                label="Late"
                value={summary.days_late}
                hint={summary.late_minutes ? formatMinutes(summary.late_minutes) : null}
                tone={summary.days_late ? 'warning' : null}
              />
              <Tile
                label="Left early"
                value={summary.days_undertime}
                hint={summary.undertime_minutes ? formatMinutes(summary.undertime_minutes) : null}
                tone={summary.days_undertime ? 'warning' : null}
              />
              <Tile label="Past scheduled end" value={formatMinutes(summary.overtime_minutes)} />
              <Tile
                label="Missing punches"
                value={summary.incomplete_records}
                tone={summary.incomplete_records ? 'destructive' : null}
              />
              <Tile label="Worked on rest day" value={summary.rest_day_work} />
            </dl>
          </CardContent>
        </Card>
      </div>

      <div className="flex gap-2.5 rounded-lg border bg-muted/40 px-3 py-2.5 text-xs text-muted-foreground">
        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
        <p>
          Late is counted from the scheduled start
          {grace > 0 ? `, once past the ${grace}-minute allowance set in Settings` : ' to the minute (no grace period is set)'}.
          “Past scheduled end” is time recorded after the shift ends — reported for HR, not approved
          overtime, and never priced. Entries come from the client’s DTR; this system does not connect
          to the biometric device.
        </p>
      </div>

      {editing ? (
        <DtrEditor
          employee={employee}
          days={days}
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
              {mayManage && (
                <Button size="sm" onClick={() => setEditing(true)}>
                  <Pencil className="h-3.5 w-3.5" />
                  Encode DTR
                </Button>
              )}
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {!schedules.length && !days.some((d) => d.has_record) ? (
              <div className="p-5 pt-0">
                <EmptyState
                  icon={CalendarClock}
                  title="No schedule and no DTR yet"
                  description="Set this employee's schedule, then encode their DTR from the client's time records to compare the two."
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
                      <TableHead className="w-0" />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {days.map((day) => {
                      const status = STATUS[day.status]
                      const quiet = ['outside_employment', 'upcoming', 'rest_day', 'no_schedule'].includes(day.status)
                      const violationType = day.flags.includes('absent')
                        ? 'absences'
                        : day.flags.includes('late')
                          ? 'late'
                          : null

                      return (
                        <TableRow key={day.date} className={cn(quiet && 'text-muted-foreground')}>
                          <TableCell className="whitespace-nowrap">
                            <span className="inline-block w-9 text-xs text-muted-foreground">{day.weekday}</span>
                            {formatDate(day.date)}
                          </TableCell>
                          <TableCell className="whitespace-nowrap text-sm">
                            {day.schedule
                              ? `${day.schedule.start}–${day.schedule.end}${day.schedule.crosses_midnight ? ' (+1)' : ''}`
                              : '—'}
                          </TableCell>
                          <TableCell className="font-mono text-sm">{day.time_in ?? '—'}</TableCell>
                          <TableCell className="font-mono text-sm">
                            {day.time_out ?? '—'}
                            {day.time_out && day.time_out_next_day && (
                              <span className="ml-1 text-xs text-muted-foreground">(+1)</span>
                            )}
                          </TableCell>
                          <TableCell>
                            <div className="flex flex-wrap items-center gap-1.5">
                              {day.status !== 'discrepancy' && status && (
                                <Badge tone={status.tone}>{status.label}</Badge>
                              )}
                              {flagBadges(day)}
                              {day.remarks && (
                                <span className="text-xs text-muted-foreground">{day.remarks}</span>
                              )}
                            </div>
                          </TableCell>
                          <TableCell className="text-right">
                            {mayRecordViolation && violationType && onRecordViolation && (
                              <Button
                                variant="ghost"
                                size="sm"
                                title="Start a violation record for this day. Nothing is recorded until you save it."
                                onClick={() =>
                                  onRecordViolation({
                                    violation_date: day.date,
                                    violation_type: violationType,
                                    description:
                                      violationType === 'late'
                                        ? `Timed in at ${day.time_in} against a ${day.schedule.start} scheduled start (${formatMinutes(day.late_minutes)} late), per the DTR for ${formatDate(day.date)}.`
                                        : `No time-in or time-out recorded on a scheduled work day (${day.schedule.start}–${day.schedule.end}), per the DTR for ${formatDate(day.date)}.`,
                                  })
                                }
                              >
                                <FileWarning className="h-3.5 w-3.5" />
                                Violation
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
        open={scheduleOpen}
        onOpenChange={setScheduleOpen}
        employee={employee}
        current={schedule}
        onSaved={refetch}
      />
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
 * Only the rows that were changed are sent. Clearing a row removes that day's
 * record, which is what blanking a line on the sheet means.
 */
function DtrEditor({ employee, days, onCancel, onSaved }) {
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
  const [saving, setSaving] = React.useState(false)
  const [errors, setErrors] = React.useState({})

  const changed = Object.keys(rows).filter((date) => JSON.stringify(rows[date]) !== JSON.stringify(initial[date]))

  function set(date, field, value) {
    setRows((current) => ({ ...current, [date]: { ...current[date], [field]: value } }))
  }

  async function save() {
    if (changed.length === 0) {
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
      })
      toast.success('DTR saved', response.message)
      onSaved()
    } catch (error) {
      if (error.isValidation) {
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
              Copy the times from the client’s DTR. A time-out earlier than the time-in is read as the
              next day. Leave a row blank for a day with no record.
            </CardDescription>
          </div>
          <div className="flex gap-2">
            <Button variant="ghost" size="sm" onClick={onCancel} disabled={saving}>
              <X className="h-3.5 w-3.5" />
              Cancel
            </Button>
            <Button size="sm" onClick={save} disabled={saving}>
              {saving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Save className="h-3.5 w-3.5" />}
              Save {changed.length ? `${changed.length} day${changed.length === 1 ? '' : 's'}` : ''}
            </Button>
          </div>
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
                  <TableRow key={day.date} className={cn(changed.includes(day.date) && 'bg-primary/[0.04]')}>
                    <TableCell className="whitespace-nowrap">
                      <span className="inline-block w-9 text-xs text-muted-foreground">{day.weekday}</span>
                      {formatDate(day.date)}
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                      {day.schedule ? `${day.schedule.start}–${day.schedule.end}` : 'Rest day'}
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

function ScheduleDialog({ open, onOpenChange, employee, current, onSaved }) {
  const toast = useToast()
  const today = todayISO()

  const blank = React.useCallback(
    () => ({
      effective_from: current ? today : employee.hire_date ?? today,
      work_days: current?.work_days ?? [1, 2, 3, 4, 5],
      shift_start: current?.shift_start ?? '08:00',
      shift_end: current?.shift_end ?? '17:00',
      remarks: '',
    }),
    [current, employee.hire_date, today]
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
    try {
      await post(`/employees/${employee.id}/schedules`, form)
      toast.success('Schedule saved', 'The DTR is now compared against it from the date it takes effect.')
      onOpenChange(false)
      onSaved()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not save the schedule', error.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{current ? 'Change schedule' : 'Set schedule'}</DialogTitle>
          <DialogDescription>
            {current
              ? 'The current schedule ends the day before this one starts, so earlier days are still compared with the schedule that applied to them.'
              : 'Use the schedule the client company gave this worker.'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Field label="Takes effect" htmlFor="effective_from" required error={errors.effective_from?.[0]}>
            <Input
              type="date"
              value={form.effective_from}
              min={employee.hire_date ?? undefined}
              onChange={(e) => setForm({ ...form, effective_from: e.target.value })}
            />
          </Field>

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

          <Field label="Remarks" htmlFor="schedule_remarks" error={errors.remarks?.[0]}>
            <Input
              value={form.remarks}
              onChange={(e) => setForm({ ...form, remarks: e.target.value })}
              placeholder="e.g. Moved to the night shift at the client's request"
            />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" disabled={saving || form.work_days.length === 0}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              Save schedule
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
