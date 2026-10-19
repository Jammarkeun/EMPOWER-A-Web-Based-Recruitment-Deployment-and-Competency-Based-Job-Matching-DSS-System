import { describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { fail, ok, renderAt } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@/lib/api', () => api)

const { default: ForgotPassword } = await import('./ForgotPassword')

const OFFICE = { name: 'CDE Manpower Services', address: 'Brgy. Calios, Sta. Cruz, Laguna', contact: null }

function options(emailAvailable) {
  api.get.mockImplementation(() => ok({ email_reset_available: emailAvailable, link_expires_minutes: 60, office: OFFICE }))
}

describe('Forgot password', () => {
  it('sends people to the office when email reset is not available, with no form to fill', async () => {
    options(false)
    renderAt(<ForgotPassword />)

    expect(await screen.findByText('Ask the office to reset it')).toBeInTheDocument()
    expect(screen.getByText('Brgy. Calios, Sta. Cruz, Laguna')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /send reset link/i })).not.toBeInTheDocument()
  })

  it('shows the server’s answer, which never says whether an account exists', async () => {
    options(true)
    api.post.mockImplementation(() =>
      ok(null, { message: 'If an account uses that address, a reset link will be sent to it.' })
    )
    renderAt(<ForgotPassword />)

    await userEvent.type(await screen.findByRole('textbox', { name: /email address/i }), 'maria@example.test')
    await userEvent.click(screen.getByRole('button', { name: /send reset link/i }))

    expect(await screen.findByText('If an account uses that address, a reset link will be sent to it.')).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('/auth/forgot-password', { email: 'maria@example.test' })
    // The office route stays visible as the fallback.
    expect(screen.getByText('Or ask the office')).toBeInTheDocument()
  })

  it('cannot be submitted empty, and shows field errors from the server', async () => {
    options(true)
    api.post.mockImplementation(() => fail({ status: 422, message: 'Invalid', errors: { email: ['Enter a valid email address.'] } }))
    renderAt(<ForgotPassword />)

    const button = await screen.findByRole('button', { name: /send reset link/i })
    expect(button).toBeDisabled()

    await userEvent.type(screen.getByRole('textbox', { name: /email address/i }), 'not-an-email')
    await userEvent.click(button)

    await waitFor(() => expect(screen.getByText('Enter a valid email address.')).toBeInTheDocument())
  })
})
