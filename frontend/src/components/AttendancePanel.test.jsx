import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: AttendancePanel } = await import('./AttendancePanel')

const EMPLOYEE = { id: 5, full_name: 'Juan Reyes', employment_status: 'active', hire_date: '2026-09-01' }
const SCHEDULE = {
  id: 1,
  description: 'Mon Tue Wed Thu Fri, 08:00-17:00',
  effective_from: '2026-09-01',
  effective_to: null,
  work_days: [1, 2, 3, 4, 5],
  shift_start: '08:00',
  shift_end: '17:00',
  status: 'active',
  period_status: 'current',
}

function dayOf(date, weekday, extra = {}) {
  return {
    date,
    weekday,
    status: 'awaiting_dtr',
    is_scheduled: true,
    schedule: { start: '08:00', end: '17:00', crosses_midnight: false },
    has_record: false,
    time_in: null,
    time_out: null,
    time_out_next_day: false,
    remarks: null,
    source: null,
    exception: null,
    discrepancies: [],
    late_minutes: 0,
    early_departure_minutes: 0,
    minutes_after_end: 0,
    review_status: null,
    review: null,
    ...extra,
  }
}

function respond({ days, dataStatus, reviewCounts = { open: 0 }, dayDetail = null }) {
  api.get.mockImplementation((url) =>
    url.includes('/attendance/days/') ? ok(dayDetail) : ok({
      employee: EMPLOYEE,
      current_schedule: SCHEDULE,
      schedules: [SCHEDULE],
      schedule_changes: [],
      exceptions: [],
      dtr_received: [],
      days,
      summary: {
        scheduled_days: days.length,
        compared_days: days.filter((d) => d.has_record).length,
        on_schedule_days: days.filter((d) => d.status === 'on_schedule').length,
        discrepancy_days: 0,
        late_days: 0,
        late_minutes: 0,
        early_departure_days: 0,
        early_departure_minutes: 0,
        missing_punch_days: 0,
        no_record_days: 0,
        awaiting_dtr_days: days.filter((d) => d.status === 'awaiting_dtr').length,
        no_schedule_days: 0,
        data_status: dataStatus,
      },
      review_counts: reviewCounts,
      policy: { late_grace_minutes: 0 },
    })
  )
}

describe('Attendance panel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    toast.current = toastDouble()
    auth.current = authWith(['attendance.view', 'attendance.manage', 'attendance.review'])
  })

  it('shows days with nothing on file as awaiting the DTR, never as absences', async () => {
    respond({ days: [dayOf('2026-09-07', 'Mon'), dayOf('2026-09-08', 'Tue')], dataStatus: 'no_dtr' })
    renderAt(<AttendancePanel employee={EMPLOYEE} />)

    expect(await screen.findByText(/No DTR is on file for this period yet/)).toBeInTheDocument()
    expect(screen.getAllByText('Awaiting DTR').length).toBeGreaterThan(0)
    expect(screen.queryByText(/^Absent$/)).not.toBeInTheDocument()
  })

  it('opens on the month in the address, so a link from the review queue lands on its period', async () => {
    respond({ days: [dayOf('2026-09-07', 'Mon')], dataStatus: 'no_dtr' })
    renderAt(<AttendancePanel employee={EMPLOYEE} />, { path: '/employees/:id', route: '/employees/5?tab=attendance&month=2026-09' })

    await screen.findByText(/No DTR is on file for this period yet/)
    expect(api.get).toHaveBeenCalledWith('/employees/5/attendance', { from: '2026-09-01', to: '2026-09-30' }, expect.anything())

    await userEvent.click(screen.getByRole('button', { name: /previous month/i }))
    await waitFor(() =>
      expect(api.get).toHaveBeenCalledWith('/employees/5/attendance', { from: '2026-08-01', to: '2026-08-31' }, expect.anything())
    )
  })

  it('shows where a flagged day stands and sends review decisions to that day in the queue', async () => {
    const late = dayOf('2026-09-08', 'Tue', {
      status: 'discrepancy',
      has_record: true,
      time_in: '08:45',
      time_out: '17:00',
      discrepancies: ['late'],
      late_minutes: 45,
      still_flagged: true,
      review_status: 'under_verification',
      review: { id: 3, status: 'under_verification', latest_note: 'Asked the client', discrepancy_types: ['late'] },
    })
    respond({
      days: [late],
      dataStatus: 'complete',
      reviewCounts: { open: 1, under_verification: 1 },
      dayDetail: {
        employee: { ...EMPLOYEE, employee_number: 'EMP-2026-00005' },
        day: late,
        record: { source: 'manual' },
        revisions: [],
        review_events: [],
        violation: null,
        transitions: ['verified', 'corrected', 'closed'],
        note_required: ['verified', 'corrected', 'closed'],
      },
    })
    auth.current = authWith(['attendance.view', 'attendance.manage', 'attendance.review', 'violations.create'])
    renderAt(<AttendancePanel employee={EMPLOYEE} />, { path: '/employees/:id', route: '/employees/5?tab=attendance&month=2026-09' })

    expect(await screen.findByText('Under verification')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /1 open in the review queue/i })).toHaveAttribute(
      'href',
      '/attendance?employee_id=5&month=2026-09'
    )

    await userEvent.click(screen.getByRole('button', { name: 'Details' }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByRole('link', { name: /open in review queue/i })).toHaveAttribute(
      'href',
      '/attendance?employee_id=5&month=2026-09&review=2026-09-08'
    )
    // One place decides: no review form or violation button on the profile.
    expect(within(dialog).queryByRole('button', { name: /save review/i })).not.toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: /record violation/i })).not.toBeInTheDocument()
    expect(within(dialog).getByText('“Asked the client”')).toBeInTheDocument()
  })

  it('asks for a reason before a day already on file is changed', async () => {
    respond({
      days: [dayOf('2026-09-07', 'Mon', { status: 'on_schedule', has_record: true, time_in: '08:00', time_out: '17:00', source: 'manual' })],
      dataStatus: 'complete',
    })
    api.post.mockImplementation(() => ok({ saved: 1, cleared: 0, unchanged: 0 }, { message: '1 day(s) saved' }))
    renderAt(<AttendancePanel employee={EMPLOYEE} />)

    await userEvent.click(await screen.findByRole('button', { name: /encode dtr/i }))
    const timeIn = screen.getByLabelText('Time in, 2026-09-07')
    await userEvent.clear(timeIn)
    await userEvent.type(timeIn, '08:30')

    const save = screen.getByRole('button', { name: /save 1 day/i })
    expect(save).toBeDisabled()
    expect(screen.getByText(/Reason for changing 1 day\(s\) already on file/)).toBeInTheDocument()

    await userEvent.click(screen.getByPlaceholderText(/corrected from the client/i))
    await userEvent.paste('Copied wrongly from the sheet')
    await userEvent.click(save)

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/employees/5/attendance', expect.objectContaining({
        reason: 'Copied wrongly from the sheet',
        entries: [expect.objectContaining({ work_date: '2026-09-07', time_in: '08:30' })],
      }))
    )
  })
})
