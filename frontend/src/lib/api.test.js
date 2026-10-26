import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { AxiosError } from 'axios'
import { api, get, tokenStore } from './api'
import { BACKGROUND_HEADER, sessionActivity, signOutNotice } from './session'

/** Answers every request with the given status and body, as the server would. */
function serverAnswers(status, data, headers = {}) {
  api.defaults.adapter = async (config) => {
    const response = { status, statusText: String(status), data, headers, config, request: {} }
    throw new AxiosError('Request failed', AxiosError.ERR_BAD_RESPONSE, config, {}, response)
  }
}

const original = api.defaults.adapter

describe('API client errors', () => {
  afterEach(() => {
    api.defaults.adapter = original
  })

  it('reads the server’s message from a failed download (audit Module F)', async () => {
    serverAnswers(422, new Blob([JSON.stringify({ message: 'The format field is required.', errors: { format: ['Required'] } })], { type: 'application/json' }))

    const error = await api.post('/reports/export', {}, { responseType: 'blob' }).catch((e) => e)

    expect(error.status).toBe(422)
    expect(error.message).toBe('The format field is required.')
    expect(error.isValidation).toBe(true)
    expect(error.errors).toEqual({ format: ['Required'] })
  })

  it('falls back to a plain message when a download fails without JSON', async () => {
    serverAnswers(500, new Blob(['<html>'], { type: 'text/html' }))

    const error = await api.post('/reports/export', {}, { responseType: 'blob' }).catch((e) => e)

    expect(error.status).toBe(500)
    expect(error.message).toBeTruthy()
    expect(error.message).not.toContain('<html>')
  })
})

/** Answers every request successfully, keeping the headers each one was sent with. */
function serverAccepts(seen) {
  api.defaults.adapter = async (config) => {
    seen.push(config.headers)
    return { status: 200, statusText: 'OK', data: { data: {} }, headers: {}, config, request: {} }
  }
}

describe('sign-in activity and session end (audit V-02)', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    tokenStore.set('1|test-token')
    // Already on the sign-in page, so the client does not try to navigate jsdom.
    window.history.pushState({}, '', '/login')
  })

  afterEach(() => {
    api.defaults.adapter = original
    localStorage.clear()
    sessionStorage.clear()
  })

  it('counts a request of the person’s own as activity', async () => {
    serverAccepts([])

    await get('/applicants')

    expect(sessionActivity.last()).not.toBeNull()
  })

  it('marks a background request and does not count it', async () => {
    const seen = []
    serverAccepts(seen)

    await get('/notifications/unread-count', null, { background: true })

    expect(seen[0].get(BACKGROUND_HEADER)).toBe('1')
    expect(sessionActivity.last()).toBeNull()
  })

  it('keeps the server’s reason when a sign-in has run out, and drops the token', async () => {
    serverAnswers(401, {
      message: 'You were signed out after 60 minutes without activity. Please sign in again.',
      code: 'session_idle',
    })

    await api.get('/applicants').catch(() => {})

    expect(signOutNotice.peek()).toBe('You were signed out after 60 minutes without activity. Please sign in again.')
    expect(tokenStore.get()).toBeNull()
  })

  it('leaves no reason for an ordinary refusal', async () => {
    serverAnswers(401, { message: 'Authentication required.' })

    await api.get('/applicants').catch(() => {})

    expect(signOutNotice.peek()).toBeNull()
    expect(tokenStore.get()).toBeNull()
  })
})
