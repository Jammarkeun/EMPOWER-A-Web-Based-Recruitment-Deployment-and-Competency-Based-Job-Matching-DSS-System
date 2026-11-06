import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { ok } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/lib/api', () => api)

const { LiveUpdatesProvider } = await import('./LiveUpdatesContext')
const { useApi } = await import('@/hooks/useApi')

let markers

function Records() {
  const { data } = useApi('/records')
  return <p>{data ? 'records shown' : ''}</p>
}

function SignIns() {
  const { data } = useApi('/sign-ins', undefined, { live: 'activity' })
  return <p>{data ? 'sign-ins shown' : ''}</p>
}

const fetches = (url) => api.get.mock.calls.filter(([called]) => called === url).length
const liveChecks = () => fetches('/live')

/** The server's markers move; the open screen is told at its next check. */
async function serverMoves(next) {
  markers = { ...markers, ...next }
  const before = liveChecks()
  document.dispatchEvent(new Event('visibilitychange'))
  await waitFor(() => expect(liveChecks()).toBe(before + 1))
}

describe('Live updates', () => {
  beforeEach(() => {
    markers = { version: 'v1', activity: 'a1' }
    api.get.mockImplementation((url) => (url === '/live' ? ok({ ...markers }) : ok([{ id: 1 }])))
  })

  it('refreshes only the screens that show sign-ins when somebody signs in, and every screen on a change', async () => {
    render(
      <LiveUpdatesProvider>
        <Records />
        <SignIns />
      </LiveUpdatesProvider>
    )
    await screen.findByText('records shown')
    await screen.findByText('sign-ins shown')
    await waitFor(() => expect(liveChecks()).toBe(1))
    expect(fetches('/records')).toBe(1)
    expect(fetches('/sign-ins')).toBe(1)

    // A sign-in elsewhere: only the activity marker moves.
    await serverMoves({ activity: 'a2' })
    await waitFor(() => expect(fetches('/sign-ins')).toBe(2))
    expect(fetches('/records')).toBe(1)

    // A record changed: both markers move, and both screens refresh once.
    await serverMoves({ version: 'v2', activity: 'a3' })
    await waitFor(() => expect(fetches('/records')).toBe(2))
    await waitFor(() => expect(fetches('/sign-ins')).toBe(3))

    // Nothing moved: nothing is fetched.
    await serverMoves({})
    expect(fetches('/records')).toBe(2)
    expect(fetches('/sign-ins')).toBe(3)
  })

  it('treats an older server that sends one marker as covering both', async () => {
    api.get.mockImplementation((url) => (url === '/live' ? ok({ version: markers.version }) : ok([{ id: 1 }])))
    render(
      <LiveUpdatesProvider>
        <Records />
        <SignIns />
      </LiveUpdatesProvider>
    )
    await screen.findByText('sign-ins shown')
    await waitFor(() => expect(liveChecks()).toBe(1))

    await serverMoves({ version: 'v2' })
    await waitFor(() => expect(fetches('/records')).toBe(2))
    await waitFor(() => expect(fetches('/sign-ins')).toBe(2))
  })
})
