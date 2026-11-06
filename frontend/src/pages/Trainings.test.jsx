import { describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => authWith(['training.view']) }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toastDouble() }))

const { default: Trainings } = await import('./Trainings')

const SESSIONS = [
  { id: 1, training_title: 'Food Handling', training_code: 'TRN-1', training_date: '2026-10-20', location: 'CDE Office', trainer_name: 'Ms. Santos', status: 'scheduled' },
  { id: 2, training_title: 'Safety Orientation', training_code: 'TRN-2', training_date: '2026-10-21', location: 'Plant 2', trainer_name: 'Mr. Cruz', status: 'completed' },
]

const lastQuery = () => api.get.mock.calls.filter(([url]) => url === '/trainings').at(-1)?.[1]

describe('Trainings list', () => {
  it('searches on the server, keeps the filters in the address, and says the order is by title', async () => {
    api.get.mockImplementation((url, params) =>
      url === '/trainings'
        ? ok(params?.search ? [] : SESSIONS, { meta: { current_page: 1, last_page: 1, total: params?.search ? 0 : 2 } })
        : ok([])
    )
    renderAt(<Trainings />, { path: '/trainings', route: '/trainings?status=scheduled' })

    expect(await screen.findByText('Food Handling')).toBeInTheDocument()
    expect(lastQuery()).toMatchObject({ status: 'scheduled' })
    expect(screen.getByText(/by title A–Z/)).toBeInTheDocument()

    await userEvent.type(screen.getByRole('textbox', { name: /search by title/i }), 'zzz')
    await waitFor(() => expect(lastQuery()).toMatchObject({ search: 'zzz', status: 'scheduled', page: 1 }))
    expect(await screen.findByText('No sessions match this search')).toBeInTheDocument()

    await userEvent.click(screen.getAllByRole('button', { name: /clear/i }).at(-1))
    await waitFor(() => expect(lastQuery().search).toBeUndefined())
    expect(lastQuery().status).toBeUndefined()
  })
})
