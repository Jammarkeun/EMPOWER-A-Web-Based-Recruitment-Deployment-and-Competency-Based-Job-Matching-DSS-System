import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { authWith, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: CoordinatorSchedule, validateSchedule } = await import('./CoordinatorSchedule')

const WORKER = {
  employee: { id: 5, full_name: 'Ana Reyes', employee_number: 'EMP-2026-0005', employment_status: 'active' },
  schedules: [{ id: 3, description: 'Mon Tue Wed Thu Fri, 07:00-16:00', effective_from: '2026-09-07', effective_to: null }],
  current_schedule_id: 3,
  schedule_submissions: [],
}

/** A submission as the coordinator API returns it. */
function submission(overrides = {}) {
  return {
    id: 9,
    submission_code: 'WS-2026-0009',
    status: 'returned',
    status_label: 'Returned for correction',
    is_editable: true,
    employee: { id: 5, full_name: 'Ana Reyes', employee_number: 'EMP-2026-0005', employment_status: 'active' },
    effective_from: '2026-10-05',
    effective_to: null,
    work_days: [1, 2, 3, 4, 5],
    shift_start: '07:00',
    shift_end: '16:00',
    crosses_midnight: false,
    break_start: null,
    break_minutes: null,
    description: 'Mon Tue Wed Thu Fri, 07:00-16:00',
    source_note: 'Supervisor memo',
    remarks: null,
    reviewed_by: 'HR Officer',
    reviewed_at: '2026-10-01T09:00:00+08:00',
    review_note: 'The memo says 08:00, not 07:00.',
    current_schedule: null,
    changes: [],
    checks: [],
    events: [],
    ...overrides,
  }
}

describe('Coordinator work schedule', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    auth.current = authWith(['coordination.access'], { user_type: 'coordinator' })
    api.get.mockReset()
    api.post.mockReset()
    api.put.mockReset()
  })

  it('asks for the source only when sending to HR, and checks dates and days', () => {
    const form = {
      employee_id: 5,
      effective_from: '2026-10-05',
      effective_to: '',
      work_days: [1, 2, 3, 4, 5],
      shift_start: '08:00',
      shift_end: '17:00',
      break_start: '',
      break_minutes: '',
      source_note: '',
      remarks: '',
    }
    expect(validateSchedule(form, { submit: false })).toEqual({})
    expect(Object.keys(validateSchedule(form, { submit: true }))).toEqual(['source_note'])
    expect(Object.keys(validateSchedule({ ...form, effective_to: '2026-10-01', work_days: [] }, { submit: false }))).toEqual([
      'effective_to',
      'work_days',
    ])
    expect(Object.keys(validateSchedule({ ...form, shift_end: '08:00' }, { submit: false }))).toEqual(['shift_end'])
  })

  it('shows the approved schedule beside a new one and sends it to HR for the chosen worker', async () => {
    api.get.mockImplementation(() => ok(WORKER))
    api.post.mockResolvedValue({ data: { id: 12 }, message: 'Schedule sent to HR for approval' })
    renderAt(<CoordinatorSchedule />, { path: '/coordinator/schedules/new', route: '/coordinator/schedules/new?employee=5' })

    expect(await screen.findByText('Mon Tue Wed Thu Fri, 07:00-16:00')).toBeInTheDocument()
    expect(api.get.mock.calls[0][0]).toBe('/coordinator/employees/5')

    // Without a source it is refused before anything is sent.
    fireEvent.click(screen.getByRole('button', { name: 'Send to HR' }))
    expect(await screen.findByText('Say where these hours come from, so HR can check them.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText(/^Where these hours come from/), { target: { value: "Client HR memo, 28 Sept" } })
    fireEvent.change(screen.getByLabelText(/^Shift starts/), { target: { value: '22:00' } })
    fireEvent.change(screen.getByLabelText(/^Shift ends/), { target: { value: '06:00' } })
    expect(screen.getByText('Ends the next day (night shift).')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Send to HR' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send to HR' }))

    await waitFor(() => expect(api.post).toHaveBeenCalled())
    const [url, body] = api.post.mock.calls[0]
    expect(url).toBe('/coordinator/employees/5/schedules')
    expect(body).toMatchObject({
      work_days: [1, 2, 3, 4, 5],
      shift_start: '22:00',
      shift_end: '06:00',
      source_note: 'Client HR memo, 28 Sept',
      submit: true,
    })
    expect(await screen.findByText('Navigated away')).toBeInTheDocument()
  })

  it("shows HR's reason on a returned schedule and resubmits the correction", async () => {
    api.get.mockImplementation(() => ok(submission()))
    api.put.mockResolvedValue({ data: submission({ status: 'submitted' }), message: 'Schedule sent to HR for approval' })
    renderAt(<CoordinatorSchedule />, { path: '/coordinator/schedules/:id', route: '/coordinator/schedules/9' })

    expect(await screen.findByText('The memo says 08:00, not 07:00.')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/^Shift starts/), { target: { value: '08:00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send back to HR' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Send to HR' }))

    await waitFor(() => expect(api.put).toHaveBeenCalled())
    expect(api.put.mock.calls[0][0]).toBe('/coordinator/schedules/9')
    expect(api.put.mock.calls[0][1]).toMatchObject({ shift_start: '08:00', submit: true })
  })

  it('shows an approved schedule read-only, with who approved it', async () => {
    api.get.mockImplementation(() =>
      ok(submission({ status: 'approved', status_label: 'Approved', is_editable: false, review_note: null, reviewed_by: 'HR Officer' }))
    )
    renderAt(<CoordinatorSchedule />, { path: '/coordinator/schedules/:id', route: '/coordinator/schedules/9' })

    expect(await screen.findByText(/Approved by HR Officer/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Send/ })).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/^Shift starts/)).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'New schedule from a later date' })).toBeInTheDocument()
  })
})
