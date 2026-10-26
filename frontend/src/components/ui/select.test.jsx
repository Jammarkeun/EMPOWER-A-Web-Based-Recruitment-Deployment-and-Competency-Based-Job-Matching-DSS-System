import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Select } from './select'

/**
 * The sign-up form groups positions by company with <optgroup>. The shared
 * Select used to read only the <option> elements directly inside it, so every
 * grouped position vanished and the list held nothing but "Select a position".
 */
function PositionSelect({ onChange = () => {}, value = '' }) {
  return (
    <>
      <label htmlFor="position">Position you are applying for</label>
      <Select id="position" value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">Select a position</option>
        <optgroup label="General — matched to work as it arrives">
          <option value="8">General Worker</option>
        </optgroup>
        <optgroup label="Best Tiwi Food Products Corporation">
          <option value="1">Production Helper — hiring now</option>
          <option value="5">Machine Operator</option>
        </optgroup>
      </Select>
    </>
  )
}

describe('Select with grouped options', () => {
  it('lists the options inside each group, under the group’s name', async () => {
    render(<PositionSelect />)

    await userEvent.click(screen.getByRole('combobox', { name: /position you are applying for/i }))

    expect(await screen.findByRole('option', { name: 'General Worker' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Production Helper — hiring now' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Machine Operator' })).toBeInTheDocument()
    expect(screen.getByText('Best Tiwi Food Products Corporation')).toBeInTheDocument()
  })

  it('reports the chosen option’s value', async () => {
    const onChange = vi.fn()
    render(<PositionSelect onChange={onChange} />)

    await userEvent.click(screen.getByRole('combobox', { name: /position you are applying for/i }))
    await userEvent.click(await screen.findByRole('option', { name: 'Machine Operator' }))

    expect(onChange).toHaveBeenCalledWith('5')
  })

  it('says what the empty option says, not a generic placeholder', () => {
    render(<PositionSelect />)

    const box = screen.getByRole('combobox', { name: /position you are applying for/i })
    expect(box).toHaveTextContent('Select a position')
    expect(box).not.toHaveTextContent('Select option...')
  })
})
