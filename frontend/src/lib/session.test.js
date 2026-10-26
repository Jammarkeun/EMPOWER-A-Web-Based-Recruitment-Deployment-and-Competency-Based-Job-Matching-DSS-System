import { afterEach, describe, expect, it } from 'vitest'
import { IDLE_WARNING_MS, LIFETIME_WARNING_MS, sessionActivity, sessionState, signOutNotice } from './session'

const NOW = Date.parse('2026-10-22T09:00:00+08:00')
const MIN = 60_000

function at({ idleMinutes = 60, expiresAt = null, idleFor = 0 } = {}) {
  return sessionState({ idleMinutes, expiresAt, lastActivity: NOW - idleFor, now: NOW })
}

describe('sessionState (audit V-02)', () => {
  it('says nothing while the sign-in is well within both limits', () => {
    expect(at({ idleFor: 10 * MIN, expiresAt: new Date(NOW + 3 * 60 * MIN).toISOString() })).toEqual({ phase: 'active' })
  })

  it('warns two minutes before an idle sign-out, with the moment it happens', () => {
    const state = at({ idleFor: 60 * MIN - IDLE_WARNING_MS })
    expect(state).toEqual({ phase: 'idle-warning', at: NOW + IDLE_WARNING_MS })
  })

  it('ends an idle sign-in once the limit has passed', () => {
    expect(at({ idleFor: 60 * MIN })).toEqual({ phase: 'ended', reason: 'session_idle' })
  })

  it('warns five minutes before the lifetime ends', () => {
    const expiresAt = new Date(NOW + LIFETIME_WARNING_MS).toISOString()
    expect(at({ expiresAt })).toEqual({ phase: 'ending', at: Date.parse(expiresAt) })
  })

  it('ends a sign-in past its lifetime even while active', () => {
    expect(at({ expiresAt: new Date(NOW - 1000).toISOString() })).toEqual({ phase: 'ended', reason: 'session_expired' })
  })

  it('shows whichever limit comes first', () => {
    // Idle in 90 seconds, lifetime in four minutes: the idle warning first.
    const soonEnd = new Date(NOW + 4 * MIN).toISOString()
    expect(at({ idleFor: 60 * MIN - 90_000, expiresAt: soonEnd }).phase).toBe('idle-warning')

    // Lifetime in one minute, idle in two: the lifetime, not the idle warning.
    const sooner = new Date(NOW + MIN).toISOString()
    expect(at({ idleFor: 58 * MIN, expiresAt: sooner }).phase).toBe('ending')
  })

  it('stays quiet when the server reports no limits', () => {
    expect(sessionState({ idleMinutes: null, expiresAt: null, lastActivity: NOW - 999 * MIN, now: NOW })).toEqual({ phase: 'active' })
  })
})

describe('session storage helpers', () => {
  afterEach(() => {
    localStorage.clear()
    sessionStorage.clear()
  })

  it('shares the time of the last counted request between tabs', () => {
    sessionActivity.touch(NOW)
    expect(sessionActivity.last()).toBe(NOW)
  })

  it('keeps the sign-out reason until the sign-in page clears it', () => {
    signOutNotice.set('You were signed out after 60 minutes without activity. Please sign in again.')
    expect(signOutNotice.peek()).toMatch(/60 minutes/)
    signOutNotice.clear()
    expect(signOutNotice.peek()).toBeNull()
  })
})
