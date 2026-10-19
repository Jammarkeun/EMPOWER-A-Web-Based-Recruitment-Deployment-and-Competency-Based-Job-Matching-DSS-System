import { afterEach, describe, expect, it } from 'vitest'
import { AxiosError } from 'axios'
import { api } from './api'

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
