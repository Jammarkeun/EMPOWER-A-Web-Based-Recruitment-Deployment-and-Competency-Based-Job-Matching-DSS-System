import { vi } from 'vitest'
import { render } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { ThemeProvider } from '@/contexts/ThemeContext'

/**
 * Test doubles shared by the component tests.
 *
 * Each test file mocks the modules it needs with vi.mock() and builds them
 * from these, so a screen can be rendered against a chosen API response and a
 * chosen set of permissions.
 */

/** An API response in the shape the client unwraps: { data, meta, message }. */
export function ok(data, extra = {}) {
  return Promise.resolve({ data, ...extra })
}

/** A rejected request in the shape lib/api.js normalises failures into. */
export function fail({ status = 400, message = 'Something went wrong.', errors = {} } = {}) {
  return Promise.reject({
    status,
    message,
    errors,
    isValidation: status === 422,
    isConflict: status === 409,
  })
}

/** A signed-in account holding exactly these permissions. */
export function authWith(permissions = [], user = {}) {
  return {
    user: { id: 1, full_name: 'Test Officer', email: 'officer@example.test', user_type: 'hr', ...user },
    can: (permission) => permissions.includes(permission),
    hasRole: () => false,
    isAuthenticated: true,
    loading: false,
    refreshUser: vi.fn(async () => ({ ...user })),
    logout: vi.fn(),
  }
}

export function toastDouble() {
  return { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }
}

/**
 * Renders a screen at a route, so useParams and links work as in the app,
 * inside the real theme provider (the sign-in layouts carry a theme toggle).
 */
export function renderAt(element, { path = '/', route = path } = {}) {
  return render(
    <ThemeProvider>
      <MemoryRouter initialEntries={[route]} future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
        <Routes>
          <Route path={path} element={element} />
          <Route path="*" element={<p>Navigated away</p>} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>
  )
}
