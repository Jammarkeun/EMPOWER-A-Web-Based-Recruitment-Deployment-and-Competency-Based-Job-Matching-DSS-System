import { describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, ok, renderAt } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => authWith(['applicants.view', 'requirements.view']) }))

const { default: Documents } = await import('./Documents')

const lastListQuery = () => api.get.mock.calls.filter(([url]) => url === '/documents/applicants').at(-1)?.[1]

describe('Documents › by applicant', () => {
  it("opens on HR's verification queue from the dashboard's link, and can leave it", async () => {
    api.get.mockImplementation(() => ok([], { meta: { current_page: 1, last_page: 1, total: 0 } }))
    renderAt(<Documents />, { path: '/documents', route: '/documents?filter=needs_review' })

    await waitFor(() => expect(lastListQuery()).toMatchObject({ needs_review: 1 }))
    expect(screen.getByRole('button', { name: 'Awaiting review' })).toHaveAttribute('aria-pressed', 'true')
    expect(await screen.findByText('Nothing is waiting for review')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'With documents' }))
    await waitFor(() => expect(lastListQuery().needs_review).toBeUndefined())
    expect(screen.getByRole('button', { name: 'With documents' })).toHaveAttribute('aria-pressed', 'true')
  })
})
