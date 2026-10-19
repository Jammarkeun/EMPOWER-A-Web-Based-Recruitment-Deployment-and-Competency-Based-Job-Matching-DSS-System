import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, ok, renderAt } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => authWith(['applicants.view', 'applicants.create']) }))
vi.mock('@/components/dialogs/NewApplicantDialog', () => ({ default: () => null }))

const { default: Applicants } = await import('./Applicants')

const ROWS = [
  {
    id: 7,
    full_name: 'Juan Dela Cruz',
    applicant_code: 'APP-2026-0007',
    preferred_position: 'Production Helper',
    current_status: 'ready_for_deployment',
    status_label: 'Ready For Deployment',
    documents_lapsed: true,
    folder_category: 'folder_2',
    application_date: '2026-09-01',
  },
  {
    id: 8,
    full_name: 'Ana Reyes',
    applicant_code: null,
    preferred_position: null,
    current_status: 'applied',
    status_label: 'Applied',
    documents_lapsed: false,
    folder_category: 'folder_3',
    application_date: '2026-10-01',
  },
]

function respond({ rows = ROWS } = {}) {
  api.get.mockImplementation((url) => {
    switch (url) {
      case '/applicant-statuses':
        return ok({
          statuses: [
            { value: 'applied', label: 'Applied' },
            { value: 'ready_for_deployment', label: 'Ready For Deployment' },
          ],
          stages: [],
          deployable: [],
        })
      case '/clients':
        return ok([{ id: 3, company_name: 'Best Tiwi Food Products Corporation' }])
      case '/positions':
        return ok({ positions: [{ id: 11, position_title: 'Production Helper', company: 'Best Tiwi' }] })
      case '/applicants':
        return ok(rows, { meta: { current_page: 1, last_page: 1, total: rows.length } })
      default:
        return ok(null)
    }
  })
}

function lastApplicantQuery() {
  const calls = api.get.mock.calls.filter(([url]) => url === '/applicants')
  return calls.at(-1)[1]
}

describe('Applicants list', () => {
  beforeEach(() => respond())

  it('shows each applicant, never an invented code, and flags lapsed documents', async () => {
    renderAt(<Applicants />, { path: '/applicants' })

    expect(await screen.findByText('Juan Dela Cruz')).toBeInTheDocument()
    expect(screen.getByText('No code')).toBeInTheDocument()
    expect(screen.queryByText('APP-8')).not.toBeInTheDocument()

    const juan = screen.getByText('Juan Dela Cruz').closest('tr')
    expect(within(juan).getByText('Documents lapsed')).toBeInTheDocument()
  })

  it('has no selection checkboxes or bulk-action bar (audit FN-01)', async () => {
    renderAt(<Applicants />, { path: '/applicants' })
    await screen.findByText('Juan Dela Cruz')

    expect(screen.queryAllByRole('checkbox')).toHaveLength(0)
    expect(screen.queryByText(/selected/i)).not.toBeInTheDocument()
  })

  it('sends a full name to the server as typed, after a pause', async () => {
    renderAt(<Applicants />, { path: '/applicants' })
    await screen.findByText('Juan Dela Cruz')

    await userEvent.type(screen.getByRole('textbox', { name: /search by name/i }), 'Juan Cruz')

    await waitFor(() => expect(lastApplicantQuery().search).toBe('Juan Cruz'))
  })

  it('offers the statuses the server publishes and combines filters in one query', async () => {
    renderAt(<Applicants />, { path: '/applicants' })
    await screen.findByText('Juan Dela Cruz')

    const status = screen.getByRole('combobox', { name: 'All statuses' })
    await waitFor(() => expect(within(status).getByRole('option', { name: 'Ready For Deployment' })).toBeInTheDocument())

    await userEvent.selectOptions(status, 'ready_for_deployment')
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'All clients' }), '3')

    await waitFor(() =>
      expect(lastApplicantQuery()).toMatchObject({ current_status: 'ready_for_deployment', client_company_id: '3', page: 1 })
    )
  })

  it('opens the record when a row is clicked', async () => {
    renderAt(<Applicants />, { path: '/applicants', route: '/applicants' })
    await screen.findByText('Ana Reyes')

    await userEvent.click(screen.getByText('General Application'))

    expect(await screen.findByText('Navigated away')).toBeInTheDocument()
  })

  it('says when filters match nobody and offers to clear them', async () => {
    respond({ rows: [] })
    renderAt(<Applicants />, { path: '/applicants', route: '/applicants?search=zzz' })

    expect(await screen.findByText('No applicants match these filters')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /clear filters/i })).toBeInTheDocument()
  })
})
