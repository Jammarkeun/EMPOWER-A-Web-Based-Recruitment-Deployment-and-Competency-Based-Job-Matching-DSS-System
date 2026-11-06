import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), upload: vi.fn() }))
const toast = vi.hoisted(() => ({ current: null }))
const auth = vi.hoisted(() => ({ current: { can: () => true } }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))

const { default: TerminationCase } = await import('./TerminationCase')

const item = (key, label, overrides = {}) => ({
  id: { outstanding_obligations: 2, violation_history_reviewed: 3 }[key],
  key,
  label,
  kind: key === 'violation_history_reviewed' ? 'record_review' : 'obligation',
  description: null,
  is_mandatory: true,
  requires_remarks: key === 'outstanding_obligations',
  status: 'pending_review',
  status_label: 'Pending review',
  is_resolved: false,
  allowed_statuses:
    key === 'violation_history_reviewed'
      ? ['pending_review', 'cleared']
      : ['pending_review', 'requires_action', 'awaiting_verification', 'cleared', 'not_applicable'],
  remarks: null,
  verified_by: null,
  verified_at: null,
  ...overrides,
})

const caseFile = (overrides = {}) => ({
  id: 7,
  reference: 'TRM-00007',
  status: 'for_review',
  status_label: 'Submitted for administrator review',
  stage: 'for_review',
  stage_label: 'Submitted for administrator review',
  is_open: true,
  is_editable: false,
  is_legacy: false,
  reason: 'Fourth AWOL within twelve months',
  termination_date: '2026-11-10',
  remarks: null,
  has_documents: false,
  employee_message: null,
  filed_by: 'Helen HR',
  filed_at: '2026-11-03T09:00:00+08:00',
  submitted_at: '2026-11-03T09:00:00+08:00',
  review: { decided_by: null, decided_at: null, note: null, approved_by: null, approved_at: null },
  finalized_by: null,
  finalized_at: null,
  withdrawn_by: null,
  withdrawn_at: null,
  withdrawal_reason: null,
  employee: {
    id: 12,
    full_name: 'Maria Clara Santos',
    employee_number: 'EMP-2026-00012',
    employment_status: 'active',
    company: 'Best Tiwi Food Products Corporation',
    department: 'Loading Department',
  },
  assignment: {
    deployment_code: 'DEP-1',
    company: 'Best Tiwi Food Products Corporation',
    department: 'Loading Department',
    position: 'Helper',
    deployment_date: '2026-08-03',
    status: 'active',
  },
  clearance: { items: [], outstanding: [], complete: null, status: null },
  obligations: {
    records_available: false,
    message: 'EMPOWER does not keep loan, salary-advance or cash-advance records.',
  },
  violations: { official: [], unvalidated_reports: [], active_count: 0, threshold: 4 },
  incidents: { note: 'Context only. None is attributed to this employee.', context: [] },
  notice: null,
  history: [],
  can: {
    edit: false,
    submit: false,
    review: true,
    review_blocked: null,
    update_clearance: false,
    finalise: false,
    withdraw: true,
    update_legacy_clearance: false,
  },
  ...overrides,
})

function show(data) {
  api.get.mockImplementation((url) => (url === '/terminations/7' ? ok(data) : ok(null)))
  return renderAt(<TerminationCase />, { path: '/separations/terminations/:id', route: '/separations/terminations/7' })
}

describe('Termination case', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    toast.current = toastDouble()
  })

  it('lets an administrator approve, and says approval does not terminate anybody', async () => {
    api.post.mockImplementation(() => ok(null, { message: 'Request approved.' }))
    show(caseFile())

    expect(await screen.findByText('Termination request: Maria Clara Santos')).toBeInTheDocument()
    // The records beside the request say what they are.
    expect(screen.getByText(/does not keep loan/)).toBeInTheDocument()
    expect(screen.getByText('No violation is on record for this employee.')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Approve…' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/Approval does not terminate the employee/)).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Approve request' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/employees/12/terminations/7/review', { decision: 'approve', note: undefined })
    )
  })

  it('needs a reason to return a request', async () => {
    show(caseFile())

    await userEvent.click(await screen.findByRole('button', { name: 'Return for correction…' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Return for correction' }))

    expect(await within(dialog).findByText(/Write the reason/)).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('tells an administrator who filed the request that someone else must decide it', async () => {
    show(caseFile({ can: { ...caseFile().can, review: false, review_blocked: 'You filed this request, so another administrator must review it.' } }))

    expect(await screen.findByText(/another administrator must review it/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Approve…' })).not.toBeInTheDocument()
  })

  it('keeps finalising blocked, and names what is outstanding, until the clearance is complete', async () => {
    show(
      caseFile({
        status: 'approved',
        stage: 'clearance_in_progress',
        stage_label: 'Approved - clearance in progress',
        review: { decided_by: 'Ada Admin', decided_at: '2026-11-03T10:00:00+08:00', note: null, approved_by: 'Ada Admin', approved_at: '2026-11-03T10:00:00+08:00' },
        clearance: {
          items: [
            item('outstanding_obligations', 'Outstanding obligations verified', { status: 'requires_action', status_label: 'Requires action' }),
            item('violation_history_reviewed', 'Violation history reviewed'),
          ],
          outstanding: ['Outstanding obligations verified', 'Violation history reviewed'],
          complete: false,
          status: null,
        },
        can: { ...caseFile().can, review: false, update_clearance: true, finalise: false },
      })
    )

    expect(await screen.findByText('2 mandatory items still to resolve.')).toBeInTheDocument()
    expect(screen.getByText('Outstanding obligations verified: requires action')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Finalise termination…' })).toBeDisabled()
  })

  it('will not mark an obligation cleared without saying what was verified', async () => {
    show(
      caseFile({
        status: 'approved',
        stage: 'clearance_in_progress',
        clearance: {
          items: [item('outstanding_obligations', 'Outstanding obligations verified')],
          outstanding: ['Outstanding obligations verified'],
          complete: false,
          status: null,
        },
        can: { ...caseFile().can, review: false, update_clearance: true },
      })
    )

    await userEvent.click(await screen.findByRole('combobox', { name: 'Outcome' }))
    await userEvent.click(await screen.findByRole('option', { name: 'Cleared' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save item' }))

    expect(await screen.findByText(/Record what was verified, and with whom/)).toBeInTheDocument()
    expect(api.put).not.toHaveBeenCalled()

    await userEvent.type(screen.getByLabelText(/What was verified/), 'Accounting: nothing owed.')
    api.put.mockImplementation(() => ok(null))
    await userEvent.click(screen.getByRole('button', { name: 'Save item' }))
    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith('/employees/12/terminations/7/clearance-items/2', {
        status: 'cleared',
        remarks: 'Accounting: nothing owed.',
      })
    )
    // No reference is asked for.
    expect(screen.queryByLabelText(/^Reference/)).not.toBeInTheDocument()
  })

  it('saves a violation-history review with no reference and shows its saved remarks', async () => {
    api.put.mockImplementation(() => ok(null))
    show(
      caseFile({
        status: 'approved',
        stage: 'clearance_in_progress',
        clearance: {
          items: [
            item('outstanding_obligations', 'Outstanding obligations verified', {
              status: 'cleared',
              status_label: 'Cleared',
              is_resolved: true,
              remarks: 'Accounting: nothing owed.',
            }),
            item('violation_history_reviewed', 'Violation history reviewed'),
          ],
          outstanding: ['Violation history reviewed'],
          complete: false,
          status: null,
        },
        can: { ...caseFile().can, review: false, update_clearance: true },
      })
    )

    expect(await screen.findByText('1 mandatory item still to resolve.')).toBeInTheDocument()
    expect(screen.queryByText(/signed by each office/i)).not.toBeInTheDocument()
    expect(screen.getByLabelText(/What was verified/)).toHaveValue('Accounting: nothing owed.')

    const outcomes = screen.getAllByRole('combobox', { name: 'Outcome' })
    await userEvent.click(outcomes[1])
    await userEvent.click(await screen.findByRole('option', { name: 'Reviewed' }))
    await userEvent.click(screen.getAllByRole('button', { name: 'Save item' })[1])

    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith('/employees/12/terminations/7/clearance-items/3', { status: 'cleared', remarks: '' })
    )
  })
  it('opens the full details of a violation on the record, read-only', async () => {
    const violation = {
      id: 41,
      date: '2026-11-03',
      type: 'late',
      description: 'Arrived 2 hours late without notice.',
      company: 'Best Tiwi Food Products Corporation',
      department: 'Loading Department',
      status: 'open',
      penalty: 'Written warning',
      remarks: 'Second late this month.',
      resolution_date: null,
      recorded_by: 'Helen HR',
      recorded_at: '2026-11-03T10:00:00+08:00',
      reported_by: 'Cora Coordinator',
      from_report: 'VR-2026-00007',
      report_id: 7,
      has_evidence: true,
      counts_toward_threshold: true,
      expires_on: '2027-11-03',
    }
    show(caseFile({ violations: { official: [violation], unvalidated_reports: [], active_count: 1, threshold: 4 } }))

    await userEvent.click(await screen.findByRole('button', { name: /View details: Late/ }))
    const dialog = await screen.findByRole('dialog')

    expect(within(dialog).getByText('Arrived 2 hours late without notice.')).toBeInTheDocument()
    expect(within(dialog).getByText('Written warning')).toBeInTheDocument()
    expect(within(dialog).getByText('Second late this month.')).toBeInTheDocument()
    expect(within(dialog).getByText('Loading Department')).toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: 'VR-2026-00007' })).toHaveAttribute('href', '/violation-reports/7')
    // Nothing on it changes the record.
    expect(within(dialog).queryByRole('button', { name: /save|edit|resolve/i })).not.toBeInTheDocument()

    api.get.mockImplementation((url) =>
      url === '/employees/12/violations/41/evidence' ? ok({ url: 'https://files.example/e.pdf' }) : ok(caseFile())
    )
    const open = vi.spyOn(window, 'open').mockReturnValue({})
    await userEvent.click(within(dialog).getByRole('button', { name: 'Evidence' }))
    await waitFor(() => expect(open).toHaveBeenCalledWith('https://files.example/e.pdf', '_blank', 'noopener'))
  })
})
