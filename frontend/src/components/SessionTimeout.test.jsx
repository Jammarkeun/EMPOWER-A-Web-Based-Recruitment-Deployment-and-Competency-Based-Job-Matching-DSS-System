import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { ok } from '@/test/doubles'
import { sessionActivity, signOutNotice } from '@/lib/session'

const api = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/lib/api', () => api)

const auth = vi.hoisted(() => ({ current: null }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))

const { default: SessionTimeout } = await import('./SessionTimeout')

const NOW = Date.parse('2026-10-22T09:00:00+08:00')
const MIN = 60_000

function signedIn(session = { idle_timeout_minutes: 60, expires_at: null }) {
  auth.current = { user: { id: 1, session }, logout: vi.fn() }
}

/** Lets the once-a-second tick run, and any request it starts settle. */
async function tick(ms = 1000) {
  await act(async () => {
    vi.advanceTimersByTime(ms)
  })
}

describe('SessionTimeout (audit V-02)', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    localStorage.clear()
    sessionStorage.clear()
    api.get.mockImplementation(() => ok({ idle_timeout_minutes: 60, expires_at: null }))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows nothing while the sign-in is in use', () => {
    signedIn()
    sessionActivity.touch(NOW - 10 * MIN)

    render(<SessionTimeout />)

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('warns before an idle sign-out, and Stay signed in asks the server to keep it', async () => {
    signedIn()
    sessionActivity.touch(NOW - 59 * MIN)

    render(<SessionTimeout />)

    expect(screen.getByRole('dialog', { name: 'Are you still there?' })).toBeInTheDocument()
    expect(screen.getByText('1:00')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Stay signed in' }))
    await tick(0)

    expect(api.get).toHaveBeenCalledWith('/auth/session')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('signs out once the idle limit has passed and leaves the reason for the sign-in page', () => {
    signedIn()
    sessionActivity.touch(NOW - 61 * MIN)

    render(<SessionTimeout />)

    expect(auth.current.logout).toHaveBeenCalledTimes(1)
    expect(signOutNotice.peek()).toBe('You were signed out after 60 minutes without activity. Please sign in again.')
  })

  it('tells the server when the person is working without any request going out', async () => {
    signedIn()
    sessionActivity.touch(NOW - 5 * MIN)
    render(<SessionTimeout />)

    // Typing into a long form: no request, but the person is there.
    fireEvent.keyDown(window, { key: 'a' })
    await tick()

    expect(api.get).toHaveBeenCalledTimes(1)
    expect(api.get).toHaveBeenCalledWith('/auth/session')

    // At most once a minute, however much they type.
    fireEvent.keyDown(window, { key: 'b' })
    await tick()
    expect(api.get).toHaveBeenCalledTimes(1)
  })

  it('does not keep an untouched screen signed in', async () => {
    signedIn()
    sessionActivity.touch(NOW - 5 * MIN)
    render(<SessionTimeout />)

    await tick(30_000)

    expect(api.get).not.toHaveBeenCalled()
  })

  it('warns five minutes before the sign-in’s lifetime ends, and OK dismisses it', async () => {
    signedIn({ idle_timeout_minutes: 60, expires_at: new Date(NOW + 4 * MIN).toISOString() })
    sessionActivity.touch(NOW - MIN)

    render(<SessionTimeout />)

    expect(screen.getByRole('dialog', { name: 'Your session ends soon' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'OK' }))
    await tick(0)

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(auth.current.logout).not.toHaveBeenCalled()
  })

  it('asks for the limits when the profile did not carry them, as after registering', async () => {
    auth.current = { user: { id: 1 }, logout: vi.fn() }

    render(<SessionTimeout />)
    await tick(0)

    expect(api.get).toHaveBeenCalledWith('/auth/session')
  })
})
