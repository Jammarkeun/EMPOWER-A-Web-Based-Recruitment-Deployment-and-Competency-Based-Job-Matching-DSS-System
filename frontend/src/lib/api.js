import axios from 'axios'
import { BACKGROUND_HEADER, SESSION_END_CODES, sessionActivity, signOutNotice } from '@/lib/session'

const TOKEN_KEY = 'empower.token'

export const tokenStore = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  headers: { Accept: 'application/json' },
})

const TRANSIENT_STATUSES = new Set([408, 425, 429, 500, 502, 503, 504])
const stableGetCache = new Map()

function wait(ms, signal) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(resolve, ms)
    signal?.addEventListener('abort', () => {
      clearTimeout(timer)
      reject(new DOMException('The request was cancelled.', 'AbortError'))
    }, { once: true })
  })
}

api.interceptors.request.use((config) => {
  const token = tokenStore.get()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

function headerOf(config, name) {
  const headers = config?.headers
  if (!headers) return undefined
  return typeof headers.get === 'function' ? headers.get(name) : headers[name]
}

/**
 * Whether the server counted this request as the person being active: it was
 * signed in and not one the screen made on its own. Mirrors the server's rule,
 * so the idle warning appears when the server's clock says it should.
 */
function countsAsActivity(config) {
  return Boolean(headerOf(config, 'Authorization')) && !headerOf(config, BACKGROUND_HEADER)
}

/**
 * Normalises every failure into a single shape the UI can render.
 *
 * The API always answers with { success, message, errors }, so the interceptor
 * unwraps that once here rather than leaving each screen to dig through
 * error.response.data itself.
 */
api.interceptors.response.use(
  (response) => {
    if (countsAsActivity(response.config)) sessionActivity.touch()
    return response
  },
  async (error) => {
    const status = error.response?.status
    let payload = error.response?.data ?? {}

    // A download (responseType "blob") receives its error as a Blob too, so
    // the server's message - "Choose a report format", say - was lost behind a
    // generic one. Read it back when it is the usual JSON envelope.
    if (typeof Blob !== 'undefined' && payload instanceof Blob) {
      try {
        payload = payload.type?.includes('json') ? JSON.parse(await payload.text()) : {}
      } catch {
        payload = {}
      }
    }

    // Refused after signing in (a 422, a 403) still means the server accepted
    // the token and counted the request, so the idle clock restarts here too.
    if (error.response && status !== 401 && countsAsActivity(error.config)) {
      sessionActivity.touch()
    }

    // A sign-in that ran out says why; the sign-in page repeats it, so the
    // person is not left wondering where their screen went.
    if (status === 401 && SESSION_END_CODES.has(payload.code)) {
      signOutNotice.set(payload.message)
    }

    // An expired or revoked token should return the user to the login screen
    // rather than leaving them on a page that silently fails to load.
    // Deactivated accounts (403 from the `active` middleware) are treated the
    // same way, since retrying will not help.
    if (status === 401 || (status === 403 && payload.message?.includes('deactivated'))) {
      tokenStore.clear()
      if (!window.location.pathname.startsWith('/login')) {
        window.location.href = '/login'
      }
    }

    // Still on an office-issued password: the server answers nothing else
    // until it is replaced, so go to the one page that can do it.
    if (status === 403 && payload.code === 'password_change_required' && !window.location.pathname.startsWith('/change-password')) {
      window.location.href = '/change-password'
    }

    return Promise.reject({
      status,
      message: payload.message ?? fallbackMessage(status),
      errors: payload.errors ?? {},
      isValidation: status === 422,
      isConflict: status === 409,
      original: error,
    })
  }
)

function fallbackMessage(status) {
  switch (status) {
    case 403:
      return 'You do not have permission to perform this action.'
    case 404:
      return 'The requested record was not found.'
    // Rejected by the web server before Laravel sees it, so there is no
    // validation message to unwrap. Without this case it fell through to the
    // network message and told someone with a large photo to check their
    // connection, which is both wrong and unfixable advice.
    case 413:
      return 'That file is too large. The limit is 10 MB — try a smaller photo or a PDF.'
    case 429:
      return 'Too many attempts. Please wait a moment and try again.'
    case 500:
      return 'Something went wrong on the server. Please try again.'
    default:
      return 'Unable to reach the server. Check your connection and try again.'
  }
}

/**
 * Unwraps the response envelope so callers get the payload directly.
 *
 * `background: true` marks a request the screen makes on its own, such as a
 * poll, so the server does not count it as the person being active.
 */
export async function get(url, params, options = {}) {
  const { signal, retries = 2, background = false } = options
  const headers = background ? { [BACKGROUND_HEADER]: '1' } : undefined
  const query = queryParams(params)

  for (let attempt = 0; ; attempt += 1) {
    try {
      const { data } = await api.get(url, { params: query, signal, headers })
      return data
    } catch (error) {
      if (signal?.aborted || error?.original?.code === 'ERR_CANCELED') throw error

      const status = error.status ?? error.original?.response?.status
      const transient = !status || TRANSIENT_STATUSES.has(status)
      if (!transient || attempt >= retries) throw error

      await wait(250 * (2 ** attempt), signal)
    }
  }
}

/**
 * Booleans as 1 and 0. A query string carries `true` as the text "true",
 * which Laravel's `boolean` rule refuses - the manpower-request "needing
 * workers only" toggle failed with a validation error that way.
 */
export function queryParams(params) {
  if (!params) return params

  return Object.fromEntries(
    Object.entries(params).map(([key, value]) => [key, typeof value === 'boolean' ? Number(value) : value])
  )
}

export async function getCached(url, params, options = {}) {
  const { ttl = 300_000, signal } = options
  const key = `${url}:${JSON.stringify(params ?? {})}`
  const cached = stableGetCache.get(key)

  if (cached && Date.now() - cached.updatedAt < ttl) return cached.value

  const value = await get(url, params, { signal })
  stableGetCache.set(key, { value, updatedAt: Date.now() })
  return value
}

export async function post(url, body, config) {
  const { data } = await api.post(url, body, config)
  return data
}

export async function put(url, body) {
  const { data } = await api.put(url, body)
  return data
}

export async function patch(url, body) {
  const { data } = await api.patch(url, body)
  return data
}

export async function del(url) {
  const { data } = await api.delete(url)
  return data
}

/** Multipart helper for document uploads. */
export async function upload(url, formData) {
  const { data } = await api.post(url, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}
