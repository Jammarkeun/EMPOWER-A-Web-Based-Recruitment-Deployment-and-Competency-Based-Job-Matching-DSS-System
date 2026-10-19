import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, fail, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), del: vi.fn() }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => authWith(['training.view', 'training.update']) }))

const { default: TrainingDetail } = await import('./TrainingDetail')

function session(overrides = {}) {
  return {
    id: 4,
    training_code: 'TRN-2026-0004',
    training_title: 'Food Safety Orientation',
    training_date: '2026-10-01',
    location: 'Best Tiwi plant',
    trainer_name: 'Ms. Lopez',
    status: 'scheduled',
    remarks: null,
    accepts_enrolment: true,
    can_record_outcomes: true,
    counts: { enrolled: 1, present: 0, absent: 0, completed: 0 },
    enrollments: [
      {
        id: 21,
        applicant: { id: 7, full_name: 'Juan Dela Cruz', applicant_code: 'APP-2026-0007', current_status: 'training_scheduled' },
        attendance_status: 'pending',
        completion_status: 'not_completed',
        can_remove: true,
      },
    ],
    ...overrides,
  }
}

function renderSession(data = session()) {
  api.get.mockImplementation((url) => (url === '/trainings/4' ? ok(data) : ok([])))
  return renderAt(<TrainingDetail />, { path: '/trainings/:id', route: '/trainings/4' })
}

describe('Training session', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    api.patch.mockImplementation(() => ok({}, { message: 'Saved' }))
  })

  it('shows a loading state, then the roster', async () => {
    renderSession()
    expect(screen.getByText('Loading the roster…')).toBeInTheDocument()
    expect(await screen.findByText('Juan Dela Cruz')).toBeInTheDocument()
  })

  it('will not record a completion before the trainee is marked present', async () => {
    renderSession()
    const button = await screen.findByRole('button', { name: /record completion/i })
    expect(button).toBeDisabled()
  })

  it('records attendance against the right enrolment', async () => {
    renderSession()
    const row = (await screen.findByText('Juan Dela Cruz')).closest('tr')

    await userEvent.click(within(row).getByRole('radio', { name: 'Present' }))

    await waitFor(() =>
      expect(api.patch).toHaveBeenCalledWith('/trainings/4/enrollments/21', { attendance_status: 'present' })
    )
    expect(toast.current.success).toHaveBeenCalledWith('Attendance updated')
  })

  it('lets the session details be corrected, sending only what changed', async () => {
    renderSession()
    await userEvent.click(await screen.findByRole('button', { name: /edit details/i }))

    const location = screen.getByRole('textbox', { name: /location/i })
    await userEvent.clear(location)
    await userEvent.type(location, 'CDE office, Calios')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('/trainings/4', { location: 'CDE office, Calios' }))
  })

  it('keeps what was typed when the server refuses a change', async () => {
    api.patch.mockImplementation(() => fail({ status: 409, message: 'Attendance is already recorded for this session.' }))
    renderSession()
    await userEvent.click(await screen.findByRole('button', { name: /edit details/i }))

    const title = screen.getByRole('textbox', { name: /title/i })
    await userEvent.type(title, ' (revised)')
    await userEvent.click(screen.getByRole('button', { name: /save changes/i }))

    await waitFor(() => expect(toast.current.error).toHaveBeenCalledWith('Session not updated', 'Attendance is already recorded for this session.'))
    expect(screen.getByRole('textbox', { name: /title/i })).toHaveValue('Food Safety Orientation (revised)')
  })

  it('says when a session has not taken place yet and locks attendance', async () => {
    renderSession(session({ can_record_outcomes: false, training_date: '2026-12-01' }))

    expect(await screen.findByText(/has not taken place yet/)).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'Present' })).toBeDisabled()
  })

  it('offers reopening, not enrolment, once a session is completed', async () => {
    renderSession(session({ status: 'completed', accepts_enrolment: false }))

    expect(await screen.findByRole('button', { name: /reopen/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /enrol applicants/i })).not.toBeInTheDocument()
  })

  it('reports a session that cannot be loaded', async () => {
    api.get.mockImplementation(() => fail({ status: 404, message: 'Not found' }))
    renderAt(<TrainingDetail />, { path: '/trainings/:id', route: '/trainings/99' })

    expect(await screen.findByText('This training session does not exist.')).toBeInTheDocument()
  })
})
