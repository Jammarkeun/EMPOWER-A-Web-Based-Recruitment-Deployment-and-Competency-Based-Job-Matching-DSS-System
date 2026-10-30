/**
 * Checks a form can make before sending, mirroring the server's rules
 * (App\Rules\PhoneNumber and App\Rules\PersonName) so a mistake is named
 * beside the field at once. The server still checks every request; these only
 * save a round trip.
 *
 * Each returns a message, or null when the value is acceptable. Blank is
 * acceptable here: whether a field is required is the form's decision.
 */

export function phoneProblem(value) {
  const text = String(value ?? '').trim()
  if (!text) return null

  if (!/^\+?[0-9 ().-]+$/.test(text)) {
    return 'Use digits, spaces, brackets, and dashes only, with + only at the start.'
  }

  const digits = text.replace(/\D/g, '').length
  if (digits < 7 || digits > 15) return 'A phone number has between 7 and 15 digits.'

  return null
}

/**
 * A person's own mobile number, the Philippine way: 11 digits starting 09.
 * "+63 917 123 4567" and "0917 123 4567" are read as 09171234567, the same
 * tidying App\Rules\PhilippineMobileNumber does on the server.
 */
export function normalizeMobile(value) {
  const compact = String(value ?? '').replace(/[\s().-]/g, '')
  if (compact.startsWith('+63')) return `0${compact.slice(3)}`
  if (compact.startsWith('63') && compact.length === 12) return `0${compact.slice(2)}`
  return compact
}

/** What the mobile field shows and keeps: digits only, never more than 11. */
export function toMobileDigits(value) {
  return normalizeMobile(value).replace(/\D/g, '').slice(0, 11)
}

export function mobileProblem(value) {
  if (!String(value ?? '').trim()) return null

  return /^09\d{9}$/.test(normalizeMobile(value))
    ? null
    : 'Enter an 11-digit mobile number starting with 09, such as 09171234567.'
}

export function nameProblem(value) {
  const text = String(value ?? '').trim()
  if (!text) return null

  return /^[\p{L}\p{M}][\p{L}\p{M} .'’-]*$/u.test(text)
    ? null
    : 'Use letters, spaces, periods, apostrophes, and hyphens only.'
}

/**
 * Runs the checks for the fields a form names and returns them in the
 * server's error shape ({ field: [message] }), so Field shows them the same
 * way it shows the server's.
 */
export function formProblems(form, { phones = [], mobiles = [], names = [] }) {
  const problems = {}

  for (const field of mobiles) {
    const message = mobileProblem(form[field])
    if (message) problems[field] = [message]
  }

  for (const field of phones) {
    const message = phoneProblem(form[field])
    if (message) problems[field] = [message]
  }

  for (const field of names) {
    const message = nameProblem(form[field])
    if (message) problems[field] = [message]
  }

  return problems
}

/** The latest birth date the server accepts: applicants are at least 15. */
export function latestBirthDate(today = new Date()) {
  const d = new Date(today)
  d.setFullYear(d.getFullYear() - 15)
  d.setDate(d.getDate() - 1)
  return toISODate(d)
}

export function toISODate(date) {
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}
