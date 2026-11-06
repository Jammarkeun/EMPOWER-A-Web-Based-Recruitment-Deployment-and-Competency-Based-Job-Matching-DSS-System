import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { authWith, ok, renderAt } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn() }))
const auth = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
// Chart.js needs a real canvas; the charts are not what is under test.
vi.mock('@/components/charts', () => ({ TrendChart: () => null, CategoryBarChart: () => null, PipelineChart: () => null }))

const { default: Dashboard } = await import('./Dashboard')

/** The summary in the server's shape, links included as /dashboard/summary sends them. */
function summary(attention = {}) {
  return {
    headline: {
      total_applicants: 40, active_applicants: 30, ready_for_deployment: 5, active_employees: 12,
      client_companies: 3, open_requests: 4, positions_to_fill: 9, deployments_this_month: 2,
      resignations_this_month: 0, terminations_this_month: 0,
    },
    attention: {
      incomplete_requirements: 0,
      expired_documents: 0, applicants_with_expired_documents: 0,
      employee_renewals_overdue: 0, employees_with_overdue_renewals: 0,
      overdue_requests: 0,
      open_violations: 0, employees_with_open_violations: 0,
      pending_clearance: 0, resignations_awaiting_employee: 0, terminations_for_review: 0,
      documents_awaiting_review: 0, applicants_with_documents_awaiting_review: 0, documents_unopened: 0,
      requisitions_awaiting_review: 0, requisitions_new: 0,
      violation_reports_awaiting_review: 0, violation_reports_new: 0,
      incident_reports_open: 0, incident_reports_new: 0,
      identity_checks: 0,
      ...attention,
    },
    applicants_by_status: [], applicants_by_folder: [], deployments_by_company: [],
    violations_by_type: [], monthly_trend: [], open_requests: [],
    links: {
      expired_documents: { expired_documents: 1 },
      employee_renewals_overdue: { renewal_overdue: 1 },
      open_violations: { open_violations: 1 },
      overdue_requests: { overdue: 1 },
      terminations_for_review: { type: 'termination', status: 'for_review' },
      documents_awaiting_review: { filter: 'needs_review' },
      requisitions_awaiting_review: { status: 'pending' },
      violation_reports_awaiting_review: { status: 'pending' },
      incident_reports_open: { status: 'open' },
      identity_checks: { awaiting_identity_check: 1 },
    },
  }
}

function renderDashboard(attention) {
  api.get.mockImplementation(() => ok(summary(attention)))
  renderAt(<Dashboard />, { path: '/dashboard' })
}

const row = async (name) => screen.findByRole('link', { name: new RegExp(name) })

describe('Dashboard › Needs attention', () => {
  beforeEach(() => {
    auth.current = authWith(['dashboard.view'], { full_name: 'Maria Santos' })
  })

  it('opens each figure onto exactly the records it counted', async () => {
    renderDashboard({
      expired_documents: 3, applicants_with_expired_documents: 2,
      employee_renewals_overdue: 2, employees_with_overdue_renewals: 1,
      overdue_requests: 1,
      open_violations: 4, employees_with_open_violations: 3,
      terminations_for_review: 1,
    })

    expect(await row('Expired documents')).toHaveAttribute('href', '/applicants?expired_documents=1')
    expect(await row('Employee renewals overdue')).toHaveAttribute('href', '/employees?renewal_overdue=1')
    expect(await row('Requests past their deadline')).toHaveAttribute('href', '/job-requests?overdue=1')
    expect(await row('Unresolved violations')).toHaveAttribute('href', '/employees?open_violations=1')
    expect(await row('Terminations awaiting review')).toHaveAttribute('href', '/separations?type=termination&status=for_review')
  })

  it('says how many people a document or violation figure opens onto', async () => {
    renderDashboard({
      expired_documents: 3, applicants_with_expired_documents: 2,
      employee_renewals_overdue: 2, employees_with_overdue_renewals: 1,
      open_violations: 4, employees_with_open_violations: 3,
    })

    expect(await row('Expired documents')).toHaveTextContent('Held by 2 applicants waiting for a posting')
    expect(await row('Employee renewals overdue')).toHaveTextContent('1 employee: annual medical or health card lapsed')
    expect(await row('Unresolved violations')).toHaveTextContent('Across 3 employees')
  })

  it("lists HR's review inboxes, each opened on its waiting view, telling new work from work taken up", async () => {
    renderDashboard({
      documents_awaiting_review: 5, applicants_with_documents_awaiting_review: 3, documents_unopened: 2,
      requisitions_awaiting_review: 3, requisitions_new: 1,
      violation_reports_awaiting_review: 2, violation_reports_new: 2,
      incident_reports_open: 1, incident_reports_new: 0,
      identity_checks: 4,
    })

    const documents = await row('Documents awaiting verification')
    expect(documents).toHaveAttribute('href', '/documents?filter=needs_review')
    expect(documents).toHaveTextContent('2 not opened yet · from 3 applicants')

    const requisitions = await row('Requisitions awaiting review')
    expect(requisitions).toHaveAttribute('href', '/requisitions?status=pending')
    expect(requisitions).toHaveTextContent('1 new · 2 under review')

    expect(await row('Violation reports awaiting review')).toHaveTextContent('2 new')
    expect(await row('Incident reports not yet resolved')).toHaveTextContent('1 under review')
    expect(await row('ID checks waiting')).toHaveAttribute('href', '/applicants?awaiting_identity_check=1')
  })

  it('leaves out a figure at zero rather than showing a reassuring nought', async () => {
    renderDashboard({ overdue_requests: 2 })

    await row('Requests past their deadline')
    expect(screen.queryByRole('link', { name: /Expired documents/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Unresolved violations/ })).not.toBeInTheDocument()
  })
})
