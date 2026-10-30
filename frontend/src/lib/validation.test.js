import { describe, expect, it } from 'vitest'
import { formProblems, latestBirthDate, mobileProblem, nameProblem, phoneProblem, toMobileDigits } from './validation'

/*
 * The same cases as backend/laravel/tests/Feature/InputValidationTest.php, so
 * the form and the server agree on what a phone number and a name are.
 */
describe('validation', () => {
  it('accepts phone numbers as people write them, leading zero and plus included', () => {
    for (const phone of ['09171234567', '0917 123 4567', '+63 917 123 4567', '(049) 501-2233', '']) {
      expect(phoneProblem(phone)).toBeNull()
    }
  })

  it('refuses text, misplaced plus signs, and too few or too many digits', () => {
    expect(phoneProblem('call me')).toMatch(/digits/)
    expect(phoneProblem('0917-ABC-4567')).toMatch(/digits/)
    expect(phoneProblem('0917+1234567')).toMatch(/\+ only at the start/)
    expect(phoneProblem('12345')).toBe('A phone number has between 7 and 15 digits.')
    expect(phoneProblem('1234567890123456')).toBe('A phone number has between 7 and 15 digits.')
  })

  it('accepts a Philippine mobile number however it is written, as the server does', () => {
    for (const mobile of ['09171234567', '0917 123 4567', '+63 917 123 4567', '639171234567', '']) {
      expect(mobileProblem(mobile)).toBeNull()
    }
    expect(toMobileDigits('+63 917 123 4567')).toBe('09171234567')
  })

  it('refuses anything but 11 digits starting 09', () => {
    for (const mobile of ['0917123456', '091712345678', '08171234567', '(049) 501-2233', 'call me']) {
      expect(mobileProblem(mobile)).toBe('Enter an 11-digit mobile number starting with 09, such as 09171234567.')
    }
  })

  it('accepts real names and refuses digits and symbols', () => {
    for (const name of ['Ma. Teresa', "O'Neil", 'Santos-Reyes', 'Ñoño', 'Dela Cruz', 'José', '']) {
      expect(nameProblem(name)).toBeNull()
    }
    for (const name of ['Juan2', '=HYPERLINK("x")', '<b>Ana</b>', '-Ana']) {
      expect(nameProblem(name)).toMatch(/letters/)
    }
  })

  it('reports problems in the server’s error shape, only for the fields named', () => {
    const form = { contact_number: 'n/a', first_name: 'Ana3', last_name: 'Cruz', email: 'whatever' }
    expect(formProblems(form, { phones: ['contact_number'], names: ['first_name', 'last_name'] })).toEqual({
      contact_number: ['Use digits, spaces, brackets, and dashes only, with + only at the start.'],
      first_name: ['Use letters, spaces, periods, apostrophes, and hyphens only.'],
    })
    expect(formProblems({ contact_number: '' }, { phones: ['contact_number'] })).toEqual({})
  })

  it('stops birth dates at the day before the 15th birthday, as the server does', () => {
    expect(latestBirthDate(new Date(2026, 9, 27))).toBe('2011-10-26')
  })
})
