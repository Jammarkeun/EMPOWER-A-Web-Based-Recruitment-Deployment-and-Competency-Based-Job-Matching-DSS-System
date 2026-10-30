import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { ok, renderAt } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), del: vi.fn() }))
vi.mock('@/lib/api', () => api)

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

    // No start time is stored, so none is shown - the page says where to ask.
    expect(screen.getByText(/told what time to arrive/)).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
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
