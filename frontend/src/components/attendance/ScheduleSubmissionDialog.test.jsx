import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { authWith, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: ScheduleSubmissionDialog } = await import('./ScheduleSubmissionDialog')

const CURRENT = {
  id: 3,
  effective_from: '2026-09-07',
  effective_to: null,
  work_days: [1, 2, 3, 4, 5],
  shift_start: '08:00',
  shift_end: '17:00',
  crosses_midnight: false,
  break_start: null,
  break_minutes: null,
  description: 'Mon Tue Wed Thu Fri, 08:00-17:00',
}

function detail(overrides = {}) {
  return {
    id: 9,
    submission_code: 'WS-2026-0009',
    status: 'submitted',
    status_label: 'Pending HR review',
    employee: { id: 5, full_name: 'Ana Reyes', employee_number: 'EMP-2026-0005', biometric_number: '0042', employment_status: 'active' },
    company: 'Best Tiwi Food Products Corporation',
    department: 'Loading Department',
    effective_from: '2026-10-05',
    effective_to: null,
    work_days: [1, 2, 3, 4, 5],
    shift_start: '22:00',
    shift_end: '06:00',
    crosses_midnight: true,
    break_start: null,
    break_minutes: null,
    description: 'Mon Tue Wed Thu Fri, 22:00-06:00 (next day)',
    source_note: "Supervisor's memo, 28 September",
    remarks: null,
    created_by: 'Coordinator One',
    deployment: { deployment_code: 'DEP-2026-0001', position: 'Packer', supervisor: 'Line Supervisor', deployment_date: '2026-08-30', end_date: null },
    hire_date: '2026-08-30',
    current_schedule: CURRENT,
    changes: ['shift_start', 'shift_end'],
    checks: [],
    events: [{ id: 1, label: 'Submitted to HR', by: 'Coordinator One', at: '2026-09-30T10:00:00+08:00', note: null, changes: null }],
    can_review: true,
    ...overrides,
  }
}

function open(record) {
  api.get.mockImplementation(() => ok(record))
  renderAt(<ScheduleSubmissionDialog submissionId={9} open onOpenChange={vi.fn()} onChanged={vi.fn()} />)
}

describe('HR review of a coordinator schedule', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    auth.current = authWith(['attendance.view', 'attendance.manage', 'employees.view'])
    api.get.mockReset()
    api.post.mockReset()
  })

  it('shows the proposal beside the approved schedule, with the night shift and the changed rows', async () => {
    open(detail())

    expect(await screen.findByText('Ana Reyes')).toBeInTheDocument()
    const table = screen.getByRole('table')
    expect(within(table).getByText('08:00–17:00')).toBeInTheDocument()
    expect(within(table).getByText(/22:00–06:00 \(ends next day\)/)).toBeInTheDocument()
    expect(within(table).getAllByText('Changed')).toHaveLength(1)
    expect(within(table).getAllByText('Sat Sun')).toHaveLength(2)
    expect(screen.getByText("Supervisor's memo, 28 September")).toBeInTheDocument()
  })

  it('cannot approve while a check says something must be fixed', async () => {
    open(detail({ checks: [{ level: 'error', code: 'later_schedule', message: 'An approved schedule already starts on 2026-10-07.' }] }))

    expect(await screen.findByText(/already starts on 2026-10-07/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Approve' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Return' })).toBeEnabled()
  })

  it('returns a schedule only with a reason', async () => {
    api.post.mockResolvedValue({ data: detail({ status: 'returned' }), message: 'Schedule returned to the coordinator' })
    open(detail())

    fireEvent.click(await screen.findByRole('button', { name: 'Return' }))
    fireEvent.click(screen.getByRole('button', { name: 'Return to coordinator' }))
    expect(await screen.findByText(/Say what needs correcting/)).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText(/^What needs correcting/), { target: { value: 'The memo says 08:00.' } })
    fireEvent.click(screen.getByRole('button', { name: 'Return to coordinator' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/attendance/schedule-submissions/9/review', { action: 'return', note: 'The memo says 08:00.' })
    )
  })

  it('asks HR to confirm before approving a schedule that changes past days', async () => {
    api.post.mockResolvedValue({ data: detail({ status: 'approved' }), message: 'Schedule approved' })
    const message = 'It starts on 2026-09-28, so 3 past day(s) already compared with the approved schedule would be compared with this one instead.'
    open(detail({ checks: [{ level: 'warning', code: 'replaces_past_days', message }] }))

    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }))
    const confirm = await screen.findByRole('dialog', { name: 'Approve this schedule?' })
    fireEvent.click(within(confirm).getByRole('button', { name: 'Approve schedule' }))
    await waitFor(() => expect(toast.current.error).toHaveBeenCalled())
    expect(api.post).not.toHaveBeenCalled()

    fireEvent.click(within(confirm).getByRole('checkbox'))
    fireEvent.click(within(confirm).getByRole('button', { name: 'Approve schedule' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/attendance/schedule-submissions/9/review', { action: 'approve', confirm_replaces_past: true })
    )
  })

  it('offers no decision to someone who may only view attendance', async () => {
    auth.current = authWith(['attendance.view'])
    open(detail({ can_review: false }))

    expect(await screen.findByText('Ana Reyes')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Return' })).not.toBeInTheDocument()
    expect(screen.getByText(/Only HR with attendance management can decide/)).toBeInTheDocument()
  })
})
