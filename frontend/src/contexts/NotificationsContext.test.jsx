import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { ok } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@/lib/api', () => api)

const { NotificationsProvider, useNotifications } = await import('./NotificationsContext')

function Badge({ categories }) {
  const { unread, countFor } = useNotifications()
  return (
    <p>
      total {unread}, section {countFor(categories)}
    </p>
  )
}

describe('NotificationsProvider', () => {
  beforeEach(() => {
    api.get.mockImplementation(() => ok({ unread_count: 3, by_category: { application: 2, deployment: 1 } }))
  })

  it('shows exactly the unread count the server reports, and its breakdown', async () => {
    render(
      <NotificationsProvider enabled>
        <Badge categories={['application', 'deployment']} />
      </NotificationsProvider>
    )

    expect(await screen.findByText('total 3, section 3')).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/notifications/unread-count')
  })

  it('does not ask while the account cannot read notifications', async () => {
    // Signed out, or still on an office-issued password (the API would refuse).
    render(
      <NotificationsProvider enabled={false}>
        <Badge categories={['application']} />
      </NotificationsProvider>
    )

    await waitFor(() => expect(screen.getByText('total 0, section 0')).toBeInTheDocument())
    expect(api.get).not.toHaveBeenCalled()
  })
})
