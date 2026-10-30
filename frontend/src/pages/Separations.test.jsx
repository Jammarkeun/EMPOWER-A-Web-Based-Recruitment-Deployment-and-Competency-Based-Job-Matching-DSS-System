import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ok, renderAt } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
vi.mock('@/lib/api', () => api)

const { default: Separations } = await import('./Separations')

const WAITING = {
  type: 'resignation',
  id: 4,
  employee_id: 9,
  employee_name: 'Wanda Waiting',
  employee_number: 'EMP-2026-00009',
  source: 'portal',
  reason: 'Family',
  date: '2026-10-20',
  requested_exit_date: '2026-11-20',
  exit_date: null,
  clearance_status: 'pending',
  status: 'info_requested',
  is_open: true,
  notice: null,
}

function lastQuery() {
  return api.get.mock.calls.filter(([url]) => url === '/separations').at(-1)[1]
}

describe('Separations list', () => {
  beforeEach(() => {
    api.get.mockReset()
    api.get.mockImplementation((url) => (url === '/separations' ? ok([WAITING]) : ok(null)))
  })

  it('opens on the group a dashboard figure counted, names it, and can drop it', async () => {
    renderAt(<Separations />, {
      path: '/separations',
      route: '/separations?type=resignation&status=info_requested',
    })

    expect(await screen.findByText('Wanda Waiting')).toBeInTheDocument()
    expect(lastQuery()).toMatchObject({ type: 'resignation', status: 'info_requested', open_only: 1 })
    expect(screen.getByText('resignations waiting on the employee')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /show every case/i }))
    await waitFor(() => expect(lastQuery().status).toBeUndefined())
    expect(lastQuery()).toMatchObject({ type: 'resignation' })
  })
})
