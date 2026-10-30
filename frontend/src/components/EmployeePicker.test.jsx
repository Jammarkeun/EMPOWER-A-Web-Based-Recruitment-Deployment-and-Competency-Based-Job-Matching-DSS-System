import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as React from 'react'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ok } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/lib/api', () => api)

const { default: EmployeePicker } = await import('./EmployeePicker')

const JUAN = { id: 1, full_name: 'Juan Dela Cruz', employee_number: 'EMP-2026-00001', department: 'Loading' }
const JOHN = { id: 2, full_name: 'John Mark Reyes', employee_number: 'EMP-2026-00002', department: 'Chocolate' }

function Harness({ onPick }) {
  const [value, setValue] = React.useState('')
  const [selected, setSelected] = React.useState(null)
  return (
    <>
      <label htmlFor="employee_id">Employee</label>
      <EmployeePicker
        value={value}
        selected={selected}
        params={{ status: 'active' }}
        onChange={(employee) => {
          setSelected(employee)
          setValue(employee ? String(employee.id) : '')
          onPick(employee)
        }}
      />
    </>
  )
}

describe('EmployeePicker', () => {
  beforeEach(() => {
    api.get.mockReset()
    api.get.mockImplementation((url, params) =>
      ok([JUAN, JOHN].filter((e) => !params.search || e.full_name.toLowerCase().includes(params.search.toLowerCase())))
    )
  })

  it('searches the server as the coordinator types, inside their own client', async () => {
    render(<Harness onPick={vi.fn()} />)
    const box = screen.getByRole('combobox', { name: 'Employee' })

    await userEvent.type(box, 'john')

    await waitFor(() =>
      expect(api.get).toHaveBeenLastCalledWith(
        '/coordinator/employees',
        expect.objectContaining({ search: 'john', status: 'active', per_page: 20 }),
        expect.anything()
      )
    )
    expect(await screen.findByRole('option', { name: /John Mark Reyes/ })).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('option', { name: /Juan Dela Cruz/ })).not.toBeInTheDocument())
  })

  it('chooses with the keyboard and shows who was chosen', async () => {
    const onPick = vi.fn()
    render(<Harness onPick={onPick} />)
    const box = screen.getByRole('combobox', { name: 'Employee' })

    await userEvent.click(box)
    await screen.findByRole('option', { name: /Juan Dela Cruz/ })
    await userEvent.keyboard('{ArrowDown}{Enter}')

    expect(onPick).toHaveBeenCalledWith(JOHN)
    expect(screen.getByText(/Chosen:/)).toHaveTextContent('John Mark Reyes · EMP-2026-00002')

    await userEvent.click(screen.getByRole('button', { name: /clear the chosen employee/i }))
    expect(onPick).toHaveBeenLastCalledWith(null)
  })

  it('says so when nobody matches', async () => {
    render(<Harness onPick={vi.fn()} />)
    await userEvent.type(screen.getByRole('combobox', { name: 'Employee' }), 'zzz')
    expect(await screen.findByText(/No active employee matches "zzz"/)).toBeInTheDocument()
  })
})
