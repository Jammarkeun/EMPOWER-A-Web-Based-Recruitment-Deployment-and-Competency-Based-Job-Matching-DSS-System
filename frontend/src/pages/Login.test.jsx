import { afterEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderAt, toastDouble } from '@/test/doubles'
import { signOutNotice } from '@/lib/session'

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ login: vi.fn(), isAuthenticated: false, loading: false, user: null }),
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
