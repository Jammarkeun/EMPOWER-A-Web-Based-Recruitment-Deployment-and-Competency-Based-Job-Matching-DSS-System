import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, ok, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
const toast = vi.hoisted(() => ({ current: null }))
const auth = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))

const { default: SeparationPanel } = await import('./SeparationPanel')

const EMPLOYEE = { id: 12, full_name: 'Maria Clara Santos' }

const resignation = (overrides = {}) => ({
  id: 4,
  status: 'filed',
  is_open: true,
  allowed_statuses: ['info_requested', 'accepted'],
  source: 'portal',
  reason: 'Moving to the province.',
  filing_date: '2026-10-20',
  requested_exit_date: '2026-11-20',
  rendering_days: 30,
  exit_date: null,
  clearance_status: 'pending',
  employee_message: '',
  history: [],
  can_be_completed: false,
  ...overrides,
})

describe('SeparationPanel resignation form', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    toast.current = toastDouble()
    auth.current = authWith(['separation.create', 'separation.approve'])
  })

  it('keeps an unsaved message when the employee page refreshes in the background', async () => {
    const { rerender } = render(<SeparationPanel employee={EMPLOYEE} resignation={resignation()} onChanged={vi.fn()} />)

    const message = screen.getByLabelText(/^Message to the employee/)
    await userEvent.type(message, 'Please bring your ID for clearance.')

    // What a background refetch hands the card: the same resignation, as a
    // new object. This used to reset the form.
    rerender(<SeparationPanel employee={EMPLOYEE} resignation={resignation()} onChanged={vi.fn()} />)
    expect(screen.getByLabelText(/^Message to the employee/)).toHaveValue('Please bring your ID for clearance.')
    expect(screen.queryByRole('status')).not.toBeInTheDocument()

    // Somebody else really changed it: the typing stays, and the officer is told.
    rerender(
      <SeparationPanel employee={EMPLOYEE} resignation={resignation({ clearance_status: 'in_progress' })} onChanged={vi.fn()} />
    )
    expect(screen.getByLabelText(/^Message to the employee/)).toHaveValue('Please bring your ID for clearance.')
    expect(screen.getByRole('status')).toHaveTextContent('updated elsewhere while you were editing')
  })

  it('follows the saved record after a successful save', async () => {
    api.put.mockImplementation(() => ok(null))
    const onChanged = vi.fn()
    const { rerender } = render(<SeparationPanel employee={EMPLOYEE} resignation={resignation()} onChanged={onChanged} />)

    await userEvent.type(screen.getByLabelText(/^Message to the employee/), 'Clearance starts Monday.')
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() => expect(onChanged).toHaveBeenCalled())
    expect(api.put).toHaveBeenCalledWith('/employees/12/resignations/4', expect.objectContaining({ employee_message: 'Clearance starts Monday.' }))

    // The refetch after saving: the server's copy now leads again.
    rerender(
      <SeparationPanel
        employee={EMPLOYEE}
        resignation={resignation({ employee_message: 'Clearance starts Monday.', clearance_status: 'in_progress' })}
        onChanged={onChanged}
      />
    )
    expect(screen.getByRole('combobox', { name: /^Clearance/ })).toHaveTextContent('In progress')
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled()
  })
})
