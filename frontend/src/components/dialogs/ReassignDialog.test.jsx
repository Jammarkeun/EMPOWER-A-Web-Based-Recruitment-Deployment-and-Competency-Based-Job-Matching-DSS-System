import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { fail, ok, renderAt, toastDouble } from '@/test/doubles'
import { todayISO } from '@/lib/utils'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: ReassignDialog, reassignmentProblems } = await import('./ReassignDialog')

const DEPLOYMENT = {
  id: 31,
  client_company_id: 3,
  company_name: 'Best Tiwi Food Products Corporation',
  client_department_id: 5,
  department_name: 'Production',
  position_title: 'Production Helper',
  supervisor_name: 'Ms. Reyes',
  deployment_date: '2026-09-01',
  history: [],
}

function renderDialog(onSaved = vi.fn()) {
  api.get.mockImplementation((url) => {
    if (url === '/clients/3/departments') {
      return ok([
        { id: 5, department_name: 'Production', status: 'active' },
        { id: 6, department_name: 'Packaging', status: 'active' },
        { id: 9, department_name: 'Closed Line', status: 'inactive' },
      ])
    }
    return ok({ positions: [{ id: 1, position_title: 'Packer', status: 'active' }] })
  })
  renderAt(<ReassignDialog open onOpenChange={vi.fn()} employeeName="Juan Dela Cruz" deployment={DEPLOYMENT} onSaved={onSaved} />)
  return onSaved
}

async function chooseDepartment(name) {
  // The shared Select is a Radix select: open it, then pick the option.
  await userEvent.click(screen.getByRole('combobox', { name: /department/i }))
  await userEvent.click(await screen.findByRole('option', { name }))
}

describe('ReassignDialog', () => {
  beforeEach(() => {
    toast.current = toastDouble()
  })

  it('checks the move the way the server will before review', () => {
    const base = { client_department_id: '6', position_title: 'Packer', supervisor_name: '', effective_date: todayISO(), remarks: 'Line short of staff.' }

    expect(reassignmentProblems(base, DEPLOYMENT)).toEqual({})
    expect(reassignmentProblems({ ...base, remarks: '  ' }, DEPLOYMENT)).toHaveProperty('remarks')
    expect(reassignmentProblems({ ...base, effective_date: '2999-01-01' }, DEPLOYMENT)).toHaveProperty('effective_date')
    expect(reassignmentProblems({ ...base, effective_date: '2026-08-01' }, DEPLOYMENT)).toHaveProperty('effective_date')
    expect(
      reassignmentProblems({ ...base, client_department_id: '5', position_title: 'Production Helper', supervisor_name: 'Ms. Reyes' }, DEPLOYMENT)
    ).toHaveProperty('client_department_id')
  })

  it('offers only this client’s active departments', async () => {
    renderDialog()
    await userEvent.click(await screen.findByRole('combobox', { name: /department/i }))

    expect(await screen.findByRole('option', { name: 'Packaging' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Closed Line' })).not.toBeInTheDocument()
  })

  it('asks for a reason before the review', async () => {
    renderDialog()
    await screen.findByRole('combobox', { name: /department/i })
    await chooseDepartment('Packaging')
    await userEvent.click(screen.getByRole('button', { name: 'Review' }))

    expect(await screen.findByText('Give the reason for the reassignment.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('shows the change side by side, then records it within the same client', async () => {
    api.post.mockImplementation(() => ok({ department_name: 'Packaging' }))
    const onSaved = renderDialog()
    await screen.findByRole('combobox', { name: /department/i })

    await chooseDepartment('Packaging')
    await userEvent.type(screen.getByRole('textbox', { name: /reason/i }), 'Packaging line short of staff.')
    await userEvent.click(screen.getByRole('button', { name: 'Review' }))

    const table = await screen.findByRole('table', { name: /current and new assignment/i })
    expect(table).toHaveTextContent('Production')
    expect(table).toHaveTextContent('Packaging')

    await userEvent.click(screen.getByRole('button', { name: /confirm reassignment/i }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/deployments/31/reassign', {
        client_department_id: 6,
        position_title: 'Production Helper',
        supervisor_name: 'Ms. Reyes',
        effective_date: todayISO(),
        remarks: 'Packaging line short of staff.',
      })
    )
    expect(api.post.mock.calls[0][1]).not.toHaveProperty('client_company_id')
    expect(onSaved).toHaveBeenCalled()
  })

  it('goes back to the form, input intact, when the server refuses a field', async () => {
    api.post.mockImplementation(() =>
      fail({ status: 422, message: 'Invalid', errors: { effective_date: ['The date cannot be earlier than 2026-09-15.'] } })
    )
    renderDialog()
    await screen.findByRole('combobox', { name: /department/i })

    await chooseDepartment('Packaging')
    await userEvent.type(screen.getByRole('textbox', { name: /reason/i }), 'Moved.')
    await userEvent.click(screen.getByRole('button', { name: 'Review' }))
    await userEvent.click(await screen.findByRole('button', { name: /confirm reassignment/i }))

    expect(await screen.findByText('The date cannot be earlier than 2026-09-15.')).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: /reason/i })).toHaveValue('Moved.')
  })
})
