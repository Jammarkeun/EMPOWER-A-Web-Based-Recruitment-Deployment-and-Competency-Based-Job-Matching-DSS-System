import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { fail, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), del: vi.fn() }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: PortalTraining } = await import('./PortalTraining')

function session(overrides = {}) {
  return {
    enrollment_id: 31,
    training_code: 'TRN-2026-0004',
    title: 'Food Safety Orientation',
    date: '2026-11-03',
    location: 'Best Tiwi plant, Calamba',
    trainer_name: 'Ms. Lopez',
    state: 'scheduled',
    state_label: 'Scheduled',
    attendance_status: 'pending',
    completion_status: 'not_completed',
    completed_on: null,
    ...overrides,
  }
}

function renderPage(data) {
  api.get.mockImplementation((url) => (url === '/portal/training' ? ok(data) : ok({})))
  return renderAt(<PortalTraining />, { path: '/portal/training' })
}

describe('My training', () => {
  beforeEach(() => {
    api.get.mockReset()
    api.post.mockReset()
    toast.current = toastDouble()
  })

  it('shows the session to attend apart from the history, as recorded', async () => {
    renderPage({
      status: 'scheduled',
      label: 'Scheduled',
      explanation: 'You are enrolled in a training session. Please attend it as scheduled.',
      required: true,
      sessions: [
        session({ enrollment_id: 32, title: 'Line Safety', date: '2026-11-10' }),
        session({ enrollment_id: 30, title: 'Warehouse Basics', date: '2026-10-01', state: 'cancelled', state_label: 'Session cancelled' }),
      ],
    })

    await screen.findByText('Your session')
    expect(screen.getByText('Please attend as scheduled.')).toBeInTheDocument()
    expect(screen.getByText(/does not count towards your training/)).toBeInTheDocument()

    // The session to attend sits under "Your session", the cancelled one
    // under the history.
    const text = document.body.textContent
    expect(text.indexOf('Your session')).toBeLessThan(text.indexOf('Line Safety'))
    expect(text.indexOf('Line Safety')).toBeLessThan(text.indexOf('Training history'))
    expect(text.indexOf('Training history')).toBeLessThan(text.indexOf('Warehouse Basics'))

    // This session predates start times, so none is shown - the page says where to ask.
    expect(screen.queryByText('Time')).not.toBeInTheDocument()
    expect(screen.getByText(/told what time to arrive/)).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('shows the time HR set and lets the applicant say they will attend', async () => {
    const scheduled = session({ start_time: '08:00', end_time: '17:00', can_confirm: true, confirmed_at: null })
    const answered = { ...scheduled, can_confirm: false, confirmed_at: '2026-11-01T09:15:00+08:00' }
    const block = (s) => ({ status: 'scheduled', label: 'Scheduled', explanation: 'Enrolled.', required: true, sessions: [s] })

    renderPage(block(scheduled))
    api.post.mockImplementation(() => ok(block(answered), { message: 'Thank you. We have told the office you will attend.' }))

    expect(await screen.findByText('8:00 AM – 5:00 PM')).toBeInTheDocument()
    // The time is known, so nobody is sent to ask for it.
    expect(screen.queryByText(/told what time to arrive/)).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /I will attend/ }))

    expect(await screen.findByText(/You told the office you will attend/)).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('/portal/training/31/confirm')
    expect(toast.current.success).toHaveBeenCalledWith('Thank you. We have told the office you will attend.')
    expect(screen.queryByRole('button', { name: /I will attend/ })).not.toBeInTheDocument()
  })

  it('keeps the button when the office could not be told', async () => {
    renderPage({
      status: 'scheduled',
      label: 'Scheduled',
      explanation: 'Enrolled.',
      required: true,
      sessions: [session({ start_time: '08:00', can_confirm: true })],
    })
    api.post.mockImplementation(() => fail({ status: 409, message: 'This session has already started or is over.' }))

    fireEvent.click(await screen.findByRole('button', { name: /I will attend/ }))

    await vi.waitFor(() => expect(toast.current.error).toHaveBeenCalledWith('Not sent', 'This session has already started or is over.'))
    expect(screen.getByRole('button', { name: /I will attend/ })).toBeEnabled()
  })

  it('reports a recorded completion, not merely attendance', async () => {
    renderPage({
      status: 'completed',
      label: 'Completed',
      explanation: 'CDE Manpower Services has recorded that you completed your training.',
      required: true,
      sessions: [
        session({ state: 'completed', state_label: 'Completed', attendance_status: 'present', completion_status: 'completed', completed_on: '2026-10-20' }),
      ],
    })

    expect(await screen.findByText(/CDE recorded your completion on/)).toBeInTheDocument()
    expect(screen.queryByText('Your session')).not.toBeInTheDocument()
  })

  it('says so when there is no session yet', async () => {
    renderPage({
      status: 'pending',
      label: 'Waiting to be scheduled',
      explanation: 'Training is the next step in your application.',
      required: true,
      sessions: [],
    })

    expect(await screen.findByText('No training sessions yet')).toBeInTheDocument()
    expect(screen.getByText('Waiting to be scheduled')).toBeInTheDocument()
  })
})
