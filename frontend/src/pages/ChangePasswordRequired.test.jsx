import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, fail, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ post: vi.fn() }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: ChangePasswordRequired } = await import('./ChangePasswordRequired')

async function fill(current, password, confirmation) {
  await userEvent.type(screen.getByLabelText(/temporary password/i), current)
  await userEvent.type(screen.getByLabelText(/^new password/i), password)
  await userEvent.type(screen.getByLabelText(/confirm new password/i), confirmation)
  await userEvent.click(screen.getByRole('button', { name: /save my password/i }))
}

describe('Forced password change', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    auth.current = authWith([], { must_change_password: true, user_type: 'applicant' })
  })

  it('catches a mismatched confirmation before sending anything', async () => {
    renderAt(<ChangePasswordRequired />)
    await fill('Temp-Pass-123', 'Harvest-Moon-Lantern-42', 'Harvest-Moon-Lantern-43')

    expect(await screen.findByText('The two passwords do not match.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('saves the new password and leaves the page once the server agrees', async () => {
    api.post.mockImplementation(() => ok(null))
    auth.current.refreshUser = vi.fn(async () => ({ user_type: 'applicant', must_change_password: false }))
    renderAt(<ChangePasswordRequired />, { path: '/change-password' })

    await fill('Temp-Pass-123', 'Harvest-Moon-Lantern-42', 'Harvest-Moon-Lantern-42')

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/auth/change-password', {
        current_password: 'Temp-Pass-123',
        password: 'Harvest-Moon-Lantern-42',
        password_confirmation: 'Harvest-Moon-Lantern-42',
      })
    )
    expect(await screen.findByText('Navigated away')).toBeInTheDocument()
  })

  it('shows the server’s refusal at the field', async () => {
    api.post.mockImplementation(() =>
      fail({ status: 422, message: 'Invalid', errors: { password: ['Choose a password different from your current one.'] } })
    )
    renderAt(<ChangePasswordRequired />)
    await fill('Temp-Pass-123', 'Temp-Pass-123', 'Temp-Pass-123')

    expect(await screen.findByText('Choose a password different from your current one.')).toBeInTheDocument()
  })
})
