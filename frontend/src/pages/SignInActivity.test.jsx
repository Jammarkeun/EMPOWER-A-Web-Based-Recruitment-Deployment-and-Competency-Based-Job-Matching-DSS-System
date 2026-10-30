import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), api: { delete: vi.fn() } }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: SignInActivity } = await import('./SignInActivity')

const SESSIONS = [
  {
    id: 11,
    user: { id: 2, full_name: 'Hazel HR', email: 'hazel@cde.test', user_type: 'hr', roles: ['hr'] },
    device: 'Safari on iPhone',
    is_mobile: true,
    ip_address: '203.0.113.7',
    signed_in_at: '2026-10-28T08:00:00+08:00',
    last_active_at: '2026-10-28T08:30:00+08:00',
    is_current: false,
  },
  {
    id: 12,
    user: { id: 1, full_name: 'Ada Admin', email: 'ada@cde.test', user_type: 'admin', roles: ['admin'] },
    device: 'Chrome on Windows',
    is_mobile: false,
    ip_address: '127.0.0.1',
    signed_in_at: '2026-10-28T08:10:00+08:00',
    last_active_at: '2026-10-28T08:40:00+08:00',
    is_current: true,
  },
]

describe('Sign-in Activity', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    auth.current = authWith(['sessions.view', 'sessions.revoke'])
    api.get.mockReset()
    api.get.mockImplementation(() => ok(SESSIONS))
    api.api.delete.mockReset()
    api.api.delete.mockResolvedValue({ data: { message: 'Hazel HR has been signed out of that device.' } })
  })

  it('lists every signed-in device and never offers to end your own session', async () => {
    renderAt(<SignInActivity />)

    expect(await screen.findByText('Hazel HR')).toBeInTheDocument()
    expect(screen.getByText('Safari on iPhone')).toBeInTheDocument()
    expect(screen.getByText('203.0.113.7')).toBeInTheDocument()
    expect(screen.getByText('This device')).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /sign out/i })).toHaveLength(1)
  })

  it('signs a device out only after confirmation', async () => {
    renderAt(<SignInActivity />)

    await userEvent.click(await screen.findByRole('button', { name: /sign out/i }))
    expect(api.api.delete).not.toHaveBeenCalled()

    await userEvent.click(screen.getByRole('button', { name: /sign out device/i }))
    await waitFor(() => expect(api.api.delete).toHaveBeenCalledWith('/security/sessions/11'))
    expect(toast.current.success).toHaveBeenCalled()
  })

  it('offers no sign-out to someone who may only look', async () => {
    auth.current = authWith(['sessions.view'])
    renderAt(<SignInActivity />)

    await screen.findByText('Hazel HR')
    expect(screen.queryByRole('button', { name: /sign out/i })).not.toBeInTheDocument()
  })
})
