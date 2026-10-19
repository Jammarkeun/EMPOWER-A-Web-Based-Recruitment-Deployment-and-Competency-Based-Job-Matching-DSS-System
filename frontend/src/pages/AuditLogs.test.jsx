import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ok, renderAt } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/lib/api', () => api)

const { default: AuditLogs, ChangeList } = await import('./AuditLogs')

const STATUS_CHANGE = {
  id: 1,
  created_at: '2026-10-18T09:30:00+08:00',
  action_type: 'update',
  module_key: 'applicants',
  actor: { id: 2, name: 'Rosa Santos' },
  actor_role: 'hr',
  record: { type: 'Applicant', id: 7, label: 'APP-2026-0007 · Juan Dela Cruz' },
  detail_level: 'changes',
  changes: [
    { field: 'current_status', label: 'Current status', kind: 'changed', before: 'applied', after: 'initial_screening' },
    { field: 'reason', label: 'Reason', kind: 'recorded', before: null, after: 'Resume received.' },
  ],
}

const LOGIN = {
  id: 2,
  created_at: '2026-10-18T08:00:00+08:00',
  action_type: 'login',
  module_key: 'auth',
  actor: { id: 2, name: 'Rosa Santos' },
  record: null,
  detail_level: 'activity',
  changes: [],
}

describe('Audit trail', () => {
  beforeEach(() => {
    api.get.mockImplementation((url) =>
      url === '/audit-logs/facets'
        ? ok({ action_types: ['login', 'update'], modules: ['applicants', 'auth'], record_types: ['Applicant'], roles: ['hr'], actors: [{ id: 2, name: 'Rosa Santos', user_type: 'hr' }] })
        : ok([STATUS_CHANGE, LOGIN], { meta: { current_page: 1, last_page: 1, total: 2 } })
    )
  })

  it('reads a change field by field, and calls a value recorded on its own just that', () => {
    render(<ChangeList entry={STATUS_CHANGE} />)

    expect(screen.getByText('applied')).toBeInTheDocument()
    expect(screen.getByLabelText('changed to')).toBeInTheDocument()
    expect(screen.getByText('initial_screening')).toBeInTheDocument()
    expect(screen.getByText('(recorded)')).toBeInTheDocument()
  })

  it('does not invent field details for an activity', () => {
    render(<ChangeList entry={LOGIN} />)
    expect(screen.getByText(/Activity only/)).toBeInTheDocument()
  })

  it('names the person and the record, and filters to one record’s history', async () => {
    renderAt(<AuditLogs />, { path: '/audit-logs' })

    expect(await screen.findByText('APP-2026-0007 · Juan Dela Cruz')).toBeInTheDocument()
    expect(screen.getAllByText('Rosa Santos').length).toBeGreaterThan(0)

    await userEvent.click(screen.getByRole('button', { name: /every entry for this record/i }))

    await waitFor(() =>
      expect(api.get).toHaveBeenLastCalledWith(
        '/audit-logs',
        expect.objectContaining({ record_type: 'Applicant', record_id: '7', page: 1 }),
        expect.anything()
      )
    )
  })
})
