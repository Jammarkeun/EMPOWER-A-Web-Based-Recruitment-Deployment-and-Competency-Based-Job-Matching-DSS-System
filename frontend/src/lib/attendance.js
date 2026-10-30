/**
 * How attendance reads on every screen: the employee's DTR tab, the workforce
 * Attendance page, the review dialog and the coordinator's summary all word
 * a day the same way.
 *
 * The comparison only points at differences. None of these labels is a
 * finding - "No DTR record" is a gap in the data to look into, not an absence.
 */

/** "1h 15m", "25m", or a dash. */
export function formatMinutes(minutes) {
  if (!minutes) return '—'
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  if (!hours) return `${rest}m`
  return rest ? `${hours}h ${rest}m` : `${hours}h`
}

/** Days that are not discrepancies, as the comparison classes them. */
export const DAY_STATUS = {
  on_schedule: { label: 'On schedule', tone: 'success' },
  rest_day: { label: 'Rest day', tone: 'muted' },
  excused: { label: 'Leave / holiday', tone: 'info' },
  awaiting_dtr: { label: 'Awaiting DTR', tone: 'muted' },
  no_schedule: { label: 'No schedule', tone: 'muted' },
  in_progress: { label: 'Today', tone: 'muted' },
  outside_employment: { label: 'Not employed', tone: 'muted' },
  upcoming: { label: 'Upcoming', tone: 'muted' },
}

/** Statuses that mean there is not enough data to compare the day. */
export const INSUFFICIENT = ['awaiting_dtr', 'no_schedule']

export const DISCREPANCIES = {
  late: { label: 'Late', tone: 'warning', minutes: 'late_minutes' },
  early_departure: { label: 'Early departure', tone: 'warning', minutes: 'early_departure_minutes' },
  missing_time_in: { label: 'Missing time-in', tone: 'destructive' },
  missing_time_out: { label: 'Missing time-out', tone: 'destructive' },
  no_record: { label: 'No DTR record', tone: 'destructive' },
  outside_schedule: { label: 'Outside assigned schedule', tone: 'info' },
  unscheduled_record: { label: 'No schedule to compare with', tone: 'warning' },
  shift_mismatch: { label: 'Does not match the shift', tone: 'warning' },
}

export const REVIEW_STATUS = {
  for_review: { label: 'For review', tone: 'warning' },
  under_verification: { label: 'Under verification', tone: 'info' },
  verified: { label: 'Verified', tone: 'destructive' },
  corrected: { label: 'Corrected', tone: 'success' },
  closed: { label: 'Closed', tone: 'muted' },
}

export const EXCEPTION_TYPES = [
  { value: 'approved_leave', label: 'Approved leave' },
  { value: 'holiday', label: 'Holiday' },
  { value: 'client_non_working_day', label: 'Client non-working day' },
  { value: 'other', label: 'Other exception' },
]

export const WEEKDAYS = [
  [1, 'Mon'],
  [2, 'Tue'],
  [3, 'Wed'],
  [4, 'Thu'],
  [5, 'Fri'],
  [6, 'Sat'],
  [7, 'Sun'],
]

/** The badge text for one discrepancy on a day, with its minutes. */
export function discrepancyText(type, day) {
  const meta = DISCREPANCIES[type]
  if (!meta) return type
  const minutes = meta.minutes ? day?.[meta.minutes] : 0
  return minutes ? `${meta.label} ${formatMinutes(minutes)}` : meta.label
}

/** "08:00–17:00 (+1)" for a scheduled day, or a dash. */
export function shiftText(schedule) {
  if (!schedule) return '—'
  return `${schedule.start}–${schedule.end}${schedule.crosses_midnight ? ' (+1)' : ''}`
}

function pad(n) {
  return String(n).padStart(2, '0')
}

export function currentMonth() {
  const now = new Date()
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}`
}

export function shiftMonth(month, delta) {
  const [year, m] = month.split('-').map(Number)
  const date = new Date(year, m - 1 + delta, 1)
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}`
}

export function monthBounds(month) {
  const [year, m] = month.split('-').map(Number)
  return { from: `${month}-01`, to: `${month}-${pad(new Date(year, m, 0).getDate())}` }
}

export const MONTH_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/
export const DATE_PATTERN = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/

/** A month taken from the address bar, or this month when it is missing or malformed. */
export function monthParam(value) {
  return MONTH_PATTERN.test(value ?? '') ? value : currentMonth()
}

/** "2026-09" for "2026-09-08". */
export function monthOf(date) {
  return String(date).slice(0, 7)
}

/*
 * The two attendance views link to each other through these, so the employee
 * and the reporting period always travel with the link and both views open on
 * the same month. Routes are the existing ones; only query parameters are used.
 */

/** The employee's own Attendance (DTR) tab, on a given month. */
export function employeeAttendancePath(employeeId, month) {
  const params = new URLSearchParams({ tab: 'attendance' })
  if (month) params.set('month', month)
  return `/employees/${employeeId}?${params}`
}

/** The review queue narrowed to one employee and month, optionally opening one day. */
export function reviewQueuePath(employeeId, month, date) {
  const params = new URLSearchParams({ employee_id: String(employeeId) })
  if (month) params.set('month', month)
  if (date) params.set('review', date)
  return `/attendance?${params}`
}
