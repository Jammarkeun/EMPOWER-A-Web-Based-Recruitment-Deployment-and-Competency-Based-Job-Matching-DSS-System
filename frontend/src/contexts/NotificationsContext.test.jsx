import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { ok } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@/lib/api', () => api)

const { NotificationsProvider, useNotifications, useMarkSeenOnVisit } = await import('./NotificationsContext')
const { MemoryRouter, Route, Routes } = await import('react-router-dom')

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
    // A poll, so it must not count as the person being active (audit V-02).
    expect(api.get).toHaveBeenCalledWith('/notifications/unread-count', null, { background: true })
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

const SIDEBAR = [
  { to: '/coordinator', categories: [] },
  { to: '/coordinator/reports', categories: ['coordination'] },
  { to: '/coordinator/incidents', categories: [] },
  { to: '/coordinator/requisitions', categories: [] },
  { to: '/applicants', categories: ['application'] },
  { to: '/documents', categories: ['requirements'] },
]

function Sidebar({ items }) {
  const { countsFor } = useNotifications()
  const counts = countsFor(items)
  return <p>{items.map((item) => `${item.to}=${counts.get(item.to)}`).join(' ')}</p>
}

describe('sidebar counts', () => {
  it('puts each notification on the page it opens, so every page with news shows a number', async () => {
    api.get.mockImplementation(() =>
      ok({
        unread_count: 7,
        by_category: { coordination: 5, requirements: 1, general: 1 },
        by_destination: [
          { path: '/coordinator/requisitions', category: 'coordination', count: 2 },
          { path: '/coordinator/incidents', category: 'coordination', count: 1 },
          { path: '/coordinator/reports', category: 'coordination', count: 1 },
          // A page not in this sidebar, beneath one that is.
          { path: '/coordinator/employees', category: 'coordination', count: 1 },
          // Opens the applicant list, though it is about documents.
          { path: '/applicants', category: 'requirements', count: 1 },
          // No page at all, and no item for its category: the bell only.
          { path: null, category: 'general', count: 1 },
        ],
      })
    )

    render(
      <NotificationsProvider enabled>
        <Sidebar items={SIDEBAR} />
      </NotificationsProvider>
    )

    expect(
      await screen.findByText(
        '/coordinator=1 /coordinator/reports=1 /coordinator/incidents=1 /coordinator/requisitions=2 /applicants=1 /documents=0'
      )
    ).toBeInTheDocument()
  })

  it('clears an item’s number when its page is opened, and nothing else', async () => {
    let destinations = [
      { path: '/coordinator/requisitions', category: 'coordination', count: 2 },
      { path: '/coordinator/reports', category: 'coordination', count: 1 },
    ]
    api.get.mockImplementation(() =>
      ok({ unread_count: destinations.reduce((n, e) => n + e.count, 0), by_category: {}, by_destination: destinations })
    )
    api.post.mockImplementation((url, body) => {
      destinations = destinations.filter((e) => !body.destinations.some((d) => d.path === e.path))
      return ok({ marked: 2 })
    })

    function Page() {
      useMarkSeenOnVisit(SIDEBAR)
      return <Sidebar items={SIDEBAR} />
    }

    render(
      <MemoryRouter initialEntries={['/coordinator/requisitions/7']}>
        <NotificationsProvider enabled>
          <Routes>
            <Route path="*" element={<Page />} />
          </Routes>
        </NotificationsProvider>
      </MemoryRouter>
    )

    expect(await screen.findByText(/\/coordinator\/requisitions=0/)).toBeInTheDocument()
    expect(screen.getByText(/\/coordinator\/reports=1/)).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('/notifications/read-destinations', {
      destinations: [{ path: '/coordinator/requisitions', category: 'coordination' }],
    })
  })

  it('does not count a page beneath a home link as having seen the home page', async () => {
    api.get.mockImplementation(() =>
      ok({ unread_count: 1, by_category: {}, by_destination: [{ path: '/portal', category: 'application', count: 1 }] })
    )
    api.post.mockClear()
    const portal = [{ to: '/portal', end: true, categories: ['application'] }, { to: '/portal/documents', categories: [] }]

    function Page() {
      useMarkSeenOnVisit(portal)
      return <Sidebar items={portal} />
    }

    render(
      <MemoryRouter initialEntries={['/portal/settings']}>
        <NotificationsProvider enabled>
          <Routes>
            <Route path="*" element={<Page />} />
          </Routes>
        </NotificationsProvider>
      </MemoryRouter>
    )

    expect(await screen.findByText(/\/portal=1/)).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('falls back to the categories when the server does not say where notifications lead', async () => {
    api.get.mockImplementation(() => ok({ unread_count: 3, by_category: { coordination: 2, requirements: 1 } }))

    render(
      <NotificationsProvider enabled>
        <Sidebar items={SIDEBAR} />
      </NotificationsProvider>
    )

    expect(await screen.findByText(/\/coordinator\/reports=2 .* \/documents=1/)).toBeInTheDocument()
  })
})
