import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { fail, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => ({ can: () => true }) }))

const { NewClientDialog } = await import('./Clients')

async function fillRequired() {
  await userEvent.type(screen.getByLabelText(/^Company name/), 'Best Tiwi Food Products')
  await userEvent.type(screen.getByLabelText(/^Business type/), 'Food Manufacturing')
  await userEvent.type(screen.getByLabelText(/^Office address/), 'Sta. Cruz, Laguna')
}

describe('NewClientDialog', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    api.post.mockReset()
  })

  it('names a bad phone number before sending, and keeps what was typed', async () => {
    renderAt(<NewClientDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />)
    await fillRequired()
    await userEvent.type(screen.getByLabelText(/^Contact number/), '12345')
    await userEvent.click(screen.getByRole('button', { name: 'Add client' }))

    expect(await screen.findByText('A phone number has between 7 and 15 digits.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
    expect(screen.getByLabelText(/^Company name/)).toHaveValue('Best Tiwi Food Products')
  })

  it('sends a valid number exactly as written, leading zero included', async () => {
    api.post.mockImplementation(() => ok({ id: 1 }))
    const onCreated = vi.fn()
    renderAt(<NewClientDialog open onOpenChange={vi.fn()} onCreated={onCreated} />)
    await fillRequired()
    await userEvent.type(screen.getByLabelText(/^Contact number/), '(049) 501-2233')
    await userEvent.click(screen.getByRole('button', { name: 'Add client' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/clients', expect.objectContaining({ contact_number: '(049) 501-2233' }))
    )
    expect(onCreated).toHaveBeenCalled()
  })

  it('shows the server’s refusal beside the field without claiming success', async () => {
    api.post.mockImplementation(() =>
      fail({ status: 422, message: 'Invalid', errors: { email: ['The email field must be a valid email address.'] } })
    )
    renderAt(<NewClientDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />)
    await fillRequired()
    await userEvent.type(screen.getByLabelText(/^Email/), 'office@tiwi')
    await userEvent.click(screen.getByRole('button', { name: 'Add client' }))

    expect(await screen.findByText('The email field must be a valid email address.')).toBeInTheDocument()
    expect(toast.current.success).not.toHaveBeenCalled()
    expect(screen.getByLabelText(/^Email/)).toHaveValue('office@tiwi')
  })
})
