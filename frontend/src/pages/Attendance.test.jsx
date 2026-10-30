import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), upload: vi.fn() }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: Attendance } = await import('./Attendance')

const EMPLOYEE = { id: 5, employee_number: 'EMP-2026-00005', full_name: 'Juan Reyes', employment_status: 'active' }

const FLAGGED = {
  employee: EMPLOYEE,
  company: 'Best Tiwi Food Products',
  department: 'Loading',
  still_flagged: true,
  date: '2026-09-08',
  weekday: 'Tue',
  status: 'discrepancy',
  schedule: { start: '08:00', end: '17:00', crosses_midnight: false },
  time_in: '08:45',
  time_out: '17:00',
  time_out_next_day: false,
  discrepancies: ['late'],
  late_minutes: 45,
  early_departure_minutes: 0,
  minutes_after_end: 0,
  exception: null,
  remarks: null,
  source: 'import',
  import_code: 'DTR-2026-00001',
  review_status: 'for_review',
  review: null,
}

function day(review = null) {
  return {
    employee: EMPLOYEE,
    day: { ...FLAGGED, review, review_status: review?.status ?? 'for_review' },
    record: { source: 'import', import_code: 'DTR-2026-00001', source_row: 3 },
    revisions: [],
    review_events: review ? [{ id: 1, to_status: review.status, to_label: 'Verified', note: review.latest_note, by: 'HR Officer', at: '2026-09-10T09:00:00+08:00', snapshot: { late_minutes: 45, time_in: '08:45', schedule: { start: '08:00' } } }] : [],
    violation: null,
    transitions: review?.status === 'verified' ? ['under_verification', 'corrected', 'closed'] : ['under_verification', 'verified', 'corrected', 'closed'],
    note_required: ['verified', 'corrected', 'closed'],
  }
}

function respond(dayData) {
  api.get.mockImplementation((url) => {
    if (url === '/attendance/discrepancies') {
      return ok([FLAGGED], {
        meta: {
          total: 1,
          current_page: 1,
          last_page: 1,
          status_counts: { for_review: 1 },
          insufficient_data: { no_dtr: 2, no_schedule: 0, awaiting_dtr_days: 30 },
        },
      })
    }
    if (url.startsWith('/employees/5/attendance/days/')) return ok(dayData)
    return ok([])
  })
}

describe('Attendance review queue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    toast.current = toastDouble()
    auth.current = authWith(['attendance.view', 'attendance.manage', 'attendance.review', 'violations.create', 'employees.view'])
  })

  it('lists flagged days and says plainly that missing data is not an absence', async () => {
    respond(day())
    renderAt(<Attendance />)

    expect(await screen.findByText('Juan Reyes')).toBeInTheDocument()
    expect(screen.getByText('Late 45m')).toBeInTheDocument()
    expect(screen.getByText('For review')).toBeInTheDocument()
    expect(screen.getByText(/2 employee\(s\) have no DTR on file at all/)).toBeInTheDocument()
    expect(screen.getByText(/missing data is not an absence/)).toBeInTheDocument()
    expect(screen.queryByText(/^Absent$/)).not.toBeInTheDocument()
  })

  it('needs a finding to verify a day, and offers no violation before it is verified', async () => {
    respond(day())
    api.post.mockImplementation(() => ok({ status: 'verified' }, { message: 'Review saved: Verified' }))
    renderAt(<Attendance />)

    await userEvent.click(await screen.findByRole('button', { name: 'Review' }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText(/Imported from a DTR file/)).toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: /record violation/i })).not.toBeInTheDocument()

    await userEvent.click(within(dialog).getByRole('combobox', { name: /move the review to/i }))
    await userEvent.click(await screen.findByRole('option', { name: 'Verified' }))

    const save = within(dialog).getByRole('button', { name: /save review/i })
    expect(save).toBeDisabled()

    // Pasted rather than typed: a keystroke per character is slow enough to
    // time out when the whole suite runs at once.
    await userEvent.click(within(dialog).getByPlaceholderText(/client supervisor confirmed/i))
    await userEvent.paste('Supervisor confirms the late arrival')
    expect(save).toBeEnabled()
    await userEvent.click(save)

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/employees/5/attendance/days/2026-09-08/review', {
        status: 'verified',
        note: 'Supervisor confirms the late arrival',
      })
    )
  })

  it('opens straight on one employee and one day when linked from their profile', async () => {
    respond(day())
    renderAt(<Attendance />, { path: '/attendance', route: '/attendance?employee_id=5&month=2026-09&review=2026-09-08' })

    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText(/Imported from a DTR file/)

    expect(api.get).toHaveBeenCalledWith(
      '/attendance/discrepancies',
      expect.objectContaining({ employee_id: '5', from: '2026-09-01', to: '2026-09-30' }),
      expect.anything()
    )
    expect(api.get).toHaveBeenCalledWith('/employees/5/attendance/days/2026-09-08', expect.anything(), expect.anything())
    // The decision is made here, and the way back keeps the month.
    expect(within(dialog).getByRole('combobox', { name: /move the review to/i })).toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: /open employee's dtr/i })).toHaveAttribute(
      'href',
      '/employees/5?tab=attendance&month=2026-09'
    )
  })

  it('links every employee to their own DTR on the same month, and narrows to one employee on request', async () => {
    respond(day())
    renderAt(<Attendance />, { path: '/attendance', route: '/attendance?month=2026-09&employee_id=5' })

    expect(await screen.findByText('Juan Reyes only')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Juan Reyes' })).toHaveAttribute('href', '/employees/5?tab=attendance&month=2026-09')
    expect(screen.getByRole('button', { name: /show everyone/i })).toBeInTheDocument()
  })

  it('shows names without links to those who may not open employee records, and ignores a malformed link', async () => {
    auth.current = authWith(['attendance.view'])
    respond(day())
    renderAt(<Attendance />, { path: '/attendance', route: '/attendance?employee_id=abc&review=2026-13-40' })

    expect(await screen.findByText('Juan Reyes')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Juan Reyes' })).not.toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/attendance/discrepancies', expect.not.objectContaining({ employee_id: expect.anything() }), expect.anything())
    // No imports tab without the permission to manage them.
    expect(screen.queryByRole('tab', { name: /dtr imports/i })).not.toBeInTheDocument()
  })

  it('records a violation from a verified day through the ordinary violation form, tied to the review', async () => {
    respond(day({ id: 9, status: 'verified', latest_note: 'Supervisor confirms the late arrival', discrepancy_types: ['late'], employee_violation_id: null }))
    api.post.mockImplementation(() => ok({}, { message: 'Violation recorded' }))
    renderAt(<Attendance />)

    await userEvent.click(await screen.findByRole('button', { name: 'Review' }))
    await userEvent.click(await screen.findByRole('button', { name: /record violation/i }))

    expect(await screen.findByText(/Filled in from a verified attendance discrepancy/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /^record violation$/i }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith(
        '/employees/5/violations',
        expect.objectContaining({ attendance_review_id: 9, violation_date: '2026-09-08', violation_type: 'late' })
      )
    )
  })
})
