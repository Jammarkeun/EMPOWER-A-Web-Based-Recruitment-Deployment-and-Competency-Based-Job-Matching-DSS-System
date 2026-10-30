import * as React from 'react'
import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MobileInput, NameInput, NumberInput, PhoneInput } from './input'

function Controlled({ as: Component, initial = '', ...props }) {
  const [value, setValue] = React.useState(initial)
  return <Component aria-label="field" value={value} onChange={(e) => setValue(e.target.value)} {...props} />
}

describe('NumberInput', () => {
  it('refuses letters and exponents, which a number box would otherwise send as blank', async () => {
    render(<Controlled as={NumberInput} min="0" step="1" />)
    const field = screen.getByLabelText('field')

    await userEvent.type(field, '1e2+abc')
    expect(field).toHaveValue(12)
  })

  it('refuses a minus sign where the minimum is 0, and a decimal point where only whole numbers fit', async () => {
    render(<Controlled as={NumberInput} min="0" step="1" />)
    const field = screen.getByLabelText('field')

    await userEvent.type(field, '-3.5')
    expect(field).toHaveValue(35)
  })

  it('takes decimals and negatives where the field allows them', async () => {
    render(<Controlled as={NumberInput} step="0.01" />)
    const field = screen.getByLabelText('field')

    await userEvent.type(field, '-2.75')
    expect(field).toHaveValue(-2.75)
  })

  it('leaves an empty field empty instead of turning it into 0', async () => {
    render(<Controlled as={NumberInput} min="0" initial="5" />)
    const field = screen.getByLabelText('field')

    await userEvent.clear(field)
    expect(field).toHaveValue(null)
  })
})

describe('NameInput', () => {
  it('refuses digits, so "1223333" cannot be typed as a name', async () => {
    render(<Controlled as={NameInput} />)
    const field = screen.getByLabelText('field')

    await userEvent.type(field, '1223333')
    expect(field).toHaveValue('')

    await userEvent.type(field, 'Juan2 =Dela Cruz!')
    expect(field).toHaveValue('Juan Dela Cruz')
  })

  it('keeps the letters and marks real names use', async () => {
    render(<Controlled as={NameInput} />)
    const field = screen.getByLabelText('field')

    await userEvent.type(field, "Ma. Ñoño O'Neil-Santos")
    expect(field).toHaveValue("Ma. Ñoño O'Neil-Santos")
  })
})

describe('MobileInput', () => {
  it('takes digits only and stops at 11', async () => {
    render(<Controlled as={MobileInput} />)
    const field = screen.getByLabelText('field')

    await userEvent.type(field, '0917-abc 123 45678999')
    expect(field).toHaveValue('09171234567')
    expect(field).toHaveAttribute('placeholder', '09XXXXXXXXX')
  })

  it('turns a pasted +63 number into the 11-digit form', async () => {
    render(<Controlled as={MobileInput} />)
    const field = screen.getByLabelText('field')

    field.focus()
    await userEvent.paste('+63 917 123 4567')
    expect(field).toHaveValue('09171234567')
  })

  it('shows a number saved the old way in the 11-digit form', () => {
    render(<Controlled as={MobileInput} initial="0917 555 0101" />)
    expect(screen.getByLabelText('field')).toHaveValue('09175550101')
  })
})

describe('PhoneInput', () => {
  it('keeps the leading zero, spaces, brackets, and dashes', async () => {
    render(<Controlled as={PhoneInput} />)
    const field = screen.getByLabelText('field')

    await userEvent.type(field, '(049) 501-2233')
    expect(field).toHaveValue('(049) 501-2233')
    expect(field).toHaveAttribute('type', 'tel')
  })

  it('drops letters, and any plus sign after the first character', async () => {
    render(<Controlled as={PhoneInput} />)
    const field = screen.getByLabelText('field')

    await userEvent.type(field, '+63 917 abc+123')
    expect(field).toHaveValue('+63 917 123')
  })
})
