import axios from 'axios'

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

api.interceptors.request.use((config) => {
  const token = tokenStore.get()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

/**
 * Normalises every failure into a single shape the UI can render.
 *
 * The API always answers with { success, message, errors }, so the interceptor
 * unwraps that once here rather than leaving each screen to dig through
 * error.response.data itself.
 */
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const payload = error.response?.data ?? {}

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
    case 429:
      return 'Too many attempts. Please wait a moment and try again.'
    case 500:
      return 'Something went wrong on the server. Please try again.'
    default:
      return 'Unable to reach the server. Check your connection and try again.'
  }
}

/** Unwraps the response envelope so callers get the payload directly. */
export async function get(url, params) {
  const { data } = await api.get(url, { params })
  return data
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

/** Multipart helper for document uploads. */
export async function upload(url, formData) {
  const { data } = await api.post(url, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}
