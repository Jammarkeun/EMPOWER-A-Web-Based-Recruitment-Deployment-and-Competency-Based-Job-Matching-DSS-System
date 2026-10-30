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

function respond({ rows = ROWS, meta = { current_page: 1, last_page: 1, total: rows.length } } = {}) {
  api.get.mockImplementation((url) => {
    switch (url) {
      case '/applicant-statuses':
        return ok({
          statuses: [
            { value: 'applied', label: 'Applied' },
            { value: 'ready_for_deployment', label: 'Ready For Deployment' },
            { value: 'initial_screening', label: 'Initial Screening' },
            { value: 'incomplete_requirements', label: 'Incomplete Requirements' },
            { value: 'deployed', label: 'Deployed' },
          ],
          stages: [],
          deployable: [],
        })
      case '/clients':
        return ok([{ id: 3, company_name: 'Best Tiwi Food Products Corporation' }])
      case '/positions':
        return ok({ positions: [{ id: 11, position_title: 'Production Helper', company: 'Best Tiwi' }] })
      case '/applicants':
        return ok(rows, { meta })
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
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Applied to any client' }), '3')

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

  it('asks the server for Deployed and shows where each person works, flagging a status that disagrees', async () => {
    respond({
      rows: [
        {
          ...ROWS[1],
          id: 21,
          full_name: 'Ana Active',
          current_status: 'active',
          status_label: 'Active',
          placement: { deployed: true, company: 'Best Tiwi Food Products Corporation', mismatch: null },
        },
        {
          ...ROWS[1],
          id: 22,
          full_name: 'Stella Stale',
          current_status: 'approved',
          status_label: 'Approved',
          placement: { deployed: true, company: 'Best Tiwi Food Products Corporation', mismatch: 'status_not_updated' },
        },
      ],
    })
    renderAt(<Applicants />, { path: '/applicants', route: '/applicants?current_status=deployed' })

    const ana = (await screen.findByText('Ana Active')).closest('tr')
    expect(lastApplicantQuery()).toMatchObject({ current_status: 'deployed' })
    expect(within(ana).getByText('Deployed · Best Tiwi Food Products Corporation')).toBeInTheDocument()
    expect(within(ana).queryByText('Status not updated')).not.toBeInTheDocument()

    const stella = screen.getByText('Stella Stale').closest('tr')
    expect(within(stella).getByText('Approved')).toBeInTheDocument()
    expect(within(stella).getByText('Status not updated')).toBeInTheDocument()
  })

  it('opens on several statuses from a dashboard figure, names them, and can drop them', async () => {
    renderAt(<Applicants />, {
      path: '/applicants',
      route: '/applicants?current_status=initial_screening,incomplete_requirements',
    })
    await screen.findByText('Juan Dela Cruz')

    expect(lastApplicantQuery()).toMatchObject({ current_status: 'initial_screening,incomplete_requirements' })
    await waitFor(() =>
      expect(screen.getByText('Initial Screening, Incomplete Requirements')).toBeInTheDocument()
    )
    expect(within(screen.getByRole('combobox', { name: 'All statuses' })).getByRole('option', { name: '2 statuses' })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /show every status/i }))
    await waitFor(() => expect(lastApplicantQuery().current_status).toBeUndefined())
  })

  it('opens the ready figure with deployed workers left out, shown as a toggle that can be switched off', async () => {
    renderAt(<Applicants />, {
      path: '/applicants',
      route: '/applicants?folder_category=folder_1&current_status=ready_for_deployment,client_evaluation,approved&exclude_deployed=1',
    })
    await screen.findByText('Juan Dela Cruz')

    expect(lastApplicantQuery()).toMatchObject({ folder_category: 'folder_1', exclude_deployed: 1 })
    const toggle = screen.getByRole('button', { name: 'Not deployed' })

    await userEvent.click(toggle)
    await waitFor(() => expect(lastApplicantQuery().exclude_deployed).toBeUndefined())
    expect(lastApplicantQuery()).toMatchObject({ folder_category: 'folder_1' })
  })

  it('moves to the last page instead of showing an empty page past the end', async () => {
    respond({ rows: [], meta: { current_page: 4, last_page: 2, total: 30 } })
    renderAt(<Applicants />, { path: '/applicants', route: '/applicants?page=4' })

    await waitFor(() => expect(lastApplicantQuery().page).toBe(2))
  })
})
