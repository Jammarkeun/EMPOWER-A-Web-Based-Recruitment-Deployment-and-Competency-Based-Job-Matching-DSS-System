import { afterEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { renderAt, toastDouble } from '@/test/doubles'
import { ThemeProvider } from '@/contexts/ThemeContext'
import { signOutNotice } from '@/lib/session'

const auth = vi.hoisted(() => ({ login: vi.fn() }))

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ login: auth.login, isAuthenticated: false, loading: false, user: null }),
}))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toastDouble() }))

const { default: Login } = await import('./Login')

describe('Login after a sign-out the person did not ask for (audit V-02)', () => {
  afterEach(() => sessionStorage.clear())

  it('says why they are back at the sign-in page, once', () => {
    signOutNotice.set('You were signed out after 60 minutes without activity. Please sign in again.')

    renderAt(<Login />, { path: '/login' })

    expect(screen.getByRole('status')).toHaveTextContent('You were signed out after 60 minutes without activity.')
    expect(signOutNotice.peek()).toBeNull()
  })

  it('shows no notice on an ordinary visit', () => {
    renderAt(<Login />, { path: '/login' })

    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })
})

describe('Where signing in lands after someone signed out on a page', () => {
  const coordinator = { id: 7, full_name: 'Test Coordinator', user_type: 'coordinator' }

  // The last person was on the user list, which a coordinator's role does not
  // cover; fromUser is who that was, as RequireAuth records it.
  function renderAfterSignOutBy(fromUser) {
    return render(
      <ThemeProvider>
        <MemoryRouter
          initialEntries={[{ pathname: '/login', state: { from: '/users', fromUser } }]}
          future={{ v7_startTransition: true, v7_relativeSplatPath: true }}
        >
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route path="/users" element={<p>User list</p>} />
            <Route path="/coordinator" element={<p>Coordinator home</p>} />
          </Routes>
        </MemoryRouter>
      </ThemeProvider>
    )
  }

  it('sends a different account to its own home, not the page the last one left', async () => {
    auth.login.mockResolvedValue(coordinator)
    renderAfterSignOutBy(1)

    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('Coordinator home')).toBeInTheDocument()
  })

  it('returns the same person to the page they were on', async () => {
    auth.login.mockResolvedValue(coordinator)
    renderAfterSignOutBy(7)

    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('User list')).toBeInTheDocument()
  })

  it('still honours a link opened before anyone had signed in', async () => {
    auth.login.mockResolvedValue(coordinator)
    renderAfterSignOutBy(null)

    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('User list')).toBeInTheDocument()
  })
})
