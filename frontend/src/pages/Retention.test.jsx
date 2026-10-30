import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: Retention } = await import('./Retention')

const ROW = {
  key: 'unhired_applicant:7',
  category: 'unhired_applicant',
  category_label: 'Unhired applicant',
  applicant: { id: 7, applicant_code: 'APP-2026-0007', full_name: 'Juan Dela Cruz', current_status: 'incomplete_requirements', status_label: 'Incomplete Requirements' },
  relevant_date: '2026-06-01',
  relevant_date_label: 'Last recorded activity',
  due_on: '2026-09-01',
  days_overdue: 48,
  reason: 'No recorded activity on this application since 1 Jun 2026.',
  previous_decision: null,
  actions: ['retain', 'archive'],
}

function respond(rows) {
  api.get.mockImplementation(() =>
    ok(
      {
        policy: { unhired_applicant_months: 3, legal_document_years: 5 },
        counts: { unhired_applicant: rows.length, closed_record_documents: 0 },
        undated_closed_records: 0,
        rows,
      },
      { meta: { current_page: 1, last_page: 1, total: rows.length } }
    )
  )
}

describe('Retention review', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    auth.current = authWith(['archives.view', 'archives.create', 'applicants.view', 'applicants.change_status'])
  })

  it('states the policy and that being listed changes nothing', async () => {
    respond([ROW])
    renderAt(<Retention />)

    expect(await screen.findByText('Juan Dela Cruz')).toBeInTheDocument()
    expect(screen.getByText(/3 months/)).toBeInTheDocument()
    expect(screen.getByText(/not that anything will happen to the record/)).toBeInTheDocument()
  })

  it('offers only keeping and archiving, never deleting', async () => {
    respond([ROW])
    renderAt(<Retention />)
    await screen.findByText('Juan Dela Cruz')

    expect(screen.getByRole('button', { name: /keep on file/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /archive/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument()
  })

  it('records a keep decision with its reason', async () => {
    respond([ROW])
    api.post.mockImplementation(() => ok({}, { message: 'Kept on file until 1 Jan 2027.' }))
    renderAt(<Retention />)

    await userEvent.click(await screen.findByRole('button', { name: /keep on file/i }))
    await userEvent.type(screen.getByRole('textbox', { name: /reason/i }), 'Coming back after harvest.')
    await userEvent.click(screen.getByRole('button', { name: /^keep on file$/i }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith(
        '/retention-reviews',
        expect.objectContaining({ category: 'unhired_applicant', applicant_id: 7, note: 'Coming back after harvest.' })
      )
    )
    expect(toast.current.success).toHaveBeenCalledWith('Kept on file until 1 Jan 2027.')
  })

  it('hides the decisions from someone who may only look', async () => {
    respond([ROW])
    auth.current = authWith(['archives.view', 'applicants.view'])
    renderAt(<Retention />)
    await screen.findByText('Juan Dela Cruz')

    expect(screen.queryByRole('button', { name: /keep on file/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /archive/i })).not.toBeInTheDocument()
  })

  it('says when nothing is due', async () => {
    respond([])
    renderAt(<Retention />)

    expect(await screen.findByText('Nothing is due for review')).toBeInTheDocument()
  })
})
