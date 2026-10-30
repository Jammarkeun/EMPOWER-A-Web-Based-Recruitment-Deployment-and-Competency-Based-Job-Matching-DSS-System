import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import { authWith, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), del: vi.fn() }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => authWith([], { user_type: 'applicant' }) }))

const { default: PortalOverview } = await import('./PortalOverview')

const STAGES = [
  { key: 'application', label: 'Application' },
  { key: 'screening', label: 'Screening' },
  { key: 'requirements', label: 'Requirements' },
  { key: 'training', label: 'Training' },
  { key: 'client', label: 'Client evaluation' },
  { key: 'deployed', label: 'Deployed' },
]

const SESSION = {
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
}

function overview({ application = {}, training = {} } = {}) {
  return {
    person: { full_name: 'Juan Dela Cruz', first_name: 'Juan', applicant_code: 'APP-2026-0007', initials: 'JD' },
    application: {
      status: 'training_scheduled',
      status_label: 'Training Scheduled',
      explanation: 'You have been scheduled for training. Your session details are shown below.',
      position: null,
      stage_number: 4,
      total_stages: 6,
      stages: STAGES,
      record_closed: false,
      awaiting_identity_check: false,
      office: null,
      placement_decision_due: false,
      placement_response: null,
      ...application,
    },
    documents: { is_complete: true, outstanding: [], bring_now: [], needed_later: [], verified_count: 9, total_count: 9 },
    deployment: null,
    employment: null,
    timeline: [],
    training: {
      status: 'scheduled',
      label: 'Scheduled',
      explanation: 'You are enrolled in a training session. Please attend it as scheduled.',
      required: true,
      sessions: [SESSION],
      ...training,
    },
  }
}

function renderOverview(data) {
  api.get.mockImplementation((url) => (url === '/portal' ? ok(data) : ok({})))
  return renderAt(<PortalOverview />, { path: '/portal' })
}

/** The roadmap's steps, each as the text a screen reader hears. */
async function steps() {
  const list = await screen.findByRole('list', { name: 'Application stages' })
  return within(list).getAllByRole('listitem').map((item) => item.textContent)
}

describe('Portal overview - training', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    api.get.mockReset()
    api.post.mockReset()
  })

  it('draws Training as the fourth step, between the documents and the client', async () => {
    renderOverview(overview())

    const path = await steps()
    expect(path.map((text) => text.split(' (')[0])).toEqual([
      'Application',
      'Screening',
      'Requirements',
      'Training',
      'Client evaluation',
      'Deployed',
    ])
    expect(path[3]).toContain('(current)')
    expect(path[3]).toContain('Scheduled')
    expect(path[3]).toContain('You are here')
    expect(screen.queryByText('Ready for placement')).not.toBeInTheDocument()
  })

  it('reminds an enrolled applicant of their session and links to its details', async () => {
    renderOverview(overview())

    expect(await screen.findByText('You are scheduled for training')).toBeInTheDocument()
    expect(screen.getByText('Food Safety Orientation')).toBeInTheDocument()
    expect(screen.getByText('Best Tiwi plant, Calamba')).toBeInTheDocument()
    expect(screen.getByText('Ms. Lopez')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /view training details/i })).toHaveAttribute('href', '/portal/training')

    // Seeing the reminder reads no notification.
    expect(api.post).not.toHaveBeenCalled()
  })

  it('shows no reminder to somebody not on a session', async () => {
    renderOverview(
      overview({
        application: { status: 'ready_for_deployment' },
        training: { status: 'pending', label: 'Waiting to be scheduled', sessions: [] },
      })
    )

    const path = await steps()
    expect(path[3]).toContain('Waiting to be scheduled')
    expect(screen.queryByRole('link', { name: /view training details/i })).not.toBeInTheDocument()
    expect(screen.queryByText('You are scheduled for training')).not.toBeInTheDocument()
  })

  it('does not tick training for somebody endorsed while it was not required', async () => {
    renderOverview(
      overview({
        application: { status: 'client_evaluation', stage_number: 5 },
        training: { status: 'not_required', label: 'Not required', required: false, sessions: [] },
      })
    )

    const path = await steps()
    expect(path[2]).toContain('(done)')
    expect(path[3]).toContain('(skipped)')
    expect(path[3]).not.toContain('(done)')
    expect(path[3]).toContain('Not required')
    expect(path[4]).toContain('(current)')
  })

  it('ticks training once its completion is recorded', async () => {
    renderOverview(
      overview({
        application: { status: 'client_evaluation', stage_number: 5 },
        training: {
          status: 'completed',
          label: 'Completed',
          sessions: [{ ...SESSION, state: 'completed', state_label: 'Completed', completion_status: 'completed' }],
        },
      })
    )

    const path = await steps()
    expect(path[3]).toContain('(done)')
    expect(path[3]).toContain('Completed')
    // A finished session needs nothing more from the applicant.
    expect(screen.queryByRole('link', { name: /view training details/i })).not.toBeInTheDocument()
  })

  it('tells somebody marked absent that their training is not complete', async () => {
    renderOverview(
      overview({
        training: {
          status: 'missed',
          label: 'Missed',
          explanation: 'You were marked absent from your training session, so your training is not complete yet.',
          sessions: [{ ...SESSION, state: 'missed', state_label: 'Marked absent', attendance_status: 'absent' }],
        },
      })
    )

    expect(await screen.findByText('Your training is not complete')).toBeInTheDocument()
    expect(screen.getByText(/marked absent from your training session/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /view training details/i })).toBeInTheDocument()
  })
})
