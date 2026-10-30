import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), upload: vi.fn(), patch: vi.fn(), del: vi.fn() }))
vi.mock('@/lib/api', () => api)

const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: PortalEmployment } = await import('./PortalEmployment')

function separation(overrides = {}) {
  return {
    eligibility: { can_request: true, reason: null },
    notice_days: 30,
    resignation: null,
    past_resignations: [],
    notice: null,
    ...overrides,
  }
}

function employment(overrides = {}) {
  return {
    employee_number: 'EMP-2026-0192',
    biometric_number: null,
    status: 'active',
    hire_date: '2026-08-03',
    current: { company: 'Best Tiwi Food Products Corporation', department: 'Chocolate', position: 'Packer', supervisor: null },
    deployments: [],
    violations: [],
    separation: separation(),
    ...overrides,
  }
}

function renderPage(data) {
  api.get.mockImplementation((url) => (url === '/portal/employment' ? ok(data) : ok({})))
  return renderAt(<PortalEmployment />, { path: '/portal/employment' })
}

describe('My employment', () => {
  beforeEach(() => {
    api.get.mockReset()
    api.post.mockReset()
    api.upload.mockReset()
    toast.current = toastDouble()
  })

  it('checks the request before sending it, then shows it as submitted', async () => {
    renderPage(employment())

    fireEvent.click(await screen.findByRole('button', { name: /Request to resign/ }))
    const dialog = await screen.findByRole('dialog')

    // Nothing is sent until the date, the reason and the acknowledgment are given.
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send request' }))
    expect(await within(dialog).findByText('Choose the last day you intend to work.')).toBeInTheDocument()
    expect(within(dialog).getByText('Tell HR why you are resigning.')).toBeInTheDocument()
    expect(within(dialog).getByText(/does not end your employment by itself/)).toBeInTheDocument()
    expect(api.upload).not.toHaveBeenCalled()

    api.upload.mockImplementation(() =>
      ok(
        separation({
          eligibility: { can_request: false, reason: 'You already have a resignation request in progress.' },
          resignation: {
            status: 'filed',
            status_label: 'Submitted, awaiting HR review',
            is_open: true,
            submitted_on: '2026-10-29',
            submitted_through: 'portal',
            reason: 'Moving back to the province',
            requested_last_day: '2099-12-31',
            confirmed_last_day: null,
            next_steps: ['HR will review your request and confirm your last working day.'],
            can_withdraw: true,
            can_respond: false,
            timeline: [],
          },
        })
      )
    )

    fireEvent.change(within(dialog).getByLabelText(/Last day you intend to work/), { target: { value: '2099-12-31' } })
    fireEvent.change(within(dialog).getByLabelText(/Reason for resigning/), { target: { value: 'Moving back to the province' } })
    fireEvent.click(within(dialog).getByRole('checkbox'))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send request' }))

    await waitFor(() => expect(api.upload).toHaveBeenCalledTimes(1))
    const [url, body] = api.upload.mock.calls[0]
    expect(url).toBe('/portal/resignation')
    expect(body.get('requested_exit_date')).toBe('2099-12-31')
    expect(body.get('acknowledged')).toBe('1')
    // Who is resigning and when it was filed are the server's to decide.
    expect(body.has('employee_id')).toBe(false)
    expect(body.has('filing_date')).toBe(false)

    expect(await screen.findByText('Submitted, awaiting HR review')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Request to resign/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Withdraw request' })).toBeInTheDocument()
  })

  it('offers no request to someone whose employment has ended', async () => {
    renderPage(
      employment({
        status: 'resigned',
        separation: separation({
          eligibility: { can_request: false, reason: 'Your employment has already ended, so there is nothing to resign from.' },
        }),
      })
    )

    expect(await screen.findByText('Last assignment')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Request to resign/ })).not.toBeInTheDocument()
  })

  it('shows HR’s question and sends the reply', async () => {
    renderPage(
      employment({
        separation: separation({
          eligibility: { can_request: false, reason: 'You already have a resignation request in progress.' },
          resignation: {
            status: 'info_requested',
            status_label: 'More information needed',
            is_open: true,
            submitted_on: '2026-10-29',
            submitted_through: 'portal',
            reason: 'Family',
            hr_message: 'Can you render the full 30 days?',
            next_steps: [],
            can_withdraw: true,
            can_respond: true,
            timeline: [],
          },
        }),
      })
    )

    expect(await screen.findByText('Can you render the full 30 days?')).toBeInTheDocument()

    api.post.mockImplementation(() => ok(separation({ eligibility: { can_request: false } })))
    fireEvent.change(screen.getByLabelText(/Your reply to HR/), { target: { value: 'Yes, I can.' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send reply' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/portal/resignation/respond', { message: 'Yes, I can.' })
    )
  })

  it('shows a notice with its message, and says acknowledging is not agreeing', async () => {
    renderPage(
      employment({
        status: 'terminated',
        separation: separation({
          eligibility: { can_request: false, reason: 'Your employment has already ended, so there is nothing to resign from.' },
          notice: {
            issued_on: '2026-10-29',
            effective_date: '2026-10-29',
            message: 'Please come to the office for your clearance.',
            clearance: { status: 'pending', label: 'Not started yet' },
            next_steps: ['Ask the HR office about your last pay and any obligations settled from it.'],
            viewed_at: '2026-10-29T10:00:00+08:00',
            acknowledged_at: null,
            office: { name: 'CDE Manpower Services', address: 'Brgy. Calios, Sta. Cruz', contact: null },
            timeline: [],
          },
        }),
      })
    )

    expect(await screen.findByText('Notice about your employment')).toBeInTheDocument()
    expect(screen.getByText('Please come to the office for your clearance.')).toBeInTheDocument()
    expect(screen.getByText(/does not mean you agree with the decision/)).toBeInTheDocument()

    api.post.mockImplementation(() => ok(separation({ eligibility: { can_request: false } })))
    fireEvent.click(screen.getByRole('button', { name: 'I have received this notice' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/portal/separation-notice/acknowledge'))
  })
})
