/**
 * Plain wording for a matching requirement's two numbers (min_value and
 * max_value), following how CompetencyScoringService reads them.
 *
 * The two numbers do not mean the same thing everywhere, which is why a fixed
 * "Min" and "Max" confused people:
 *
 * - On a Must Have requirement (ticked as one, or a hard filter such as age)
 *   they are the eligible range, both ends included (measureGate).
 * - On a scored requirement with both set, they are a sliding scale: no points
 *   at one end, full points at the other. Which end is which follows the
 *   criterion's direction (applyScale).
 * - With only one set on a scored requirement, it is a cut-off. On a
 *   lower-is-better criterion a lone first number is a ceiling, not a floor.
 *
 * So the labels and the read-back sentence are chosen per case. What is stored
 * and how it is scored does not change.
 */

const UNITS = {
  experience: 'months',
  availability: 'days',
  distance: 'km',
  height: 'cm',
  age: 'years',
}

export function unitFor(code) {
  return UNITS[code] ?? ''
}

/*
 * How an eligible range reads for each criterion, so the sentence names the
 * thing being measured ("between 18 and 35 years old") rather than a bare
 * number. `missing` is what the applicant record lacks when the value cannot
 * be read, which a Must Have treats as not met; it is left out where the
 * engine reads a missing value as zero instead (experience).
 */
const PHRASES = {
  age: {
    between: (a, b) => `be between ${a} and ${b} years old`,
    atLeast: (a) => `be at least ${a} years old`,
    atMost: (b) => `be no older than ${b}`,
    missing: 'no date of birth recorded',
  },
  height: {
    between: (a, b) => `be between ${a} and ${b} cm tall`,
    atLeast: (a) => `be at least ${a} cm tall`,
    atMost: (b) => `be no taller than ${b} cm`,
    missing: 'no height recorded',
  },
  availability: {
    between: (a, b) => `be able to start in ${a} to ${b} days`,
    atLeast: (a) => `need at least ${a} days before they can start`,
    atMost: (b) => `be able to start within ${b} days`,
    missing: 'no start date recorded',
  },
  distance: {
    between: (a, b) => `live between ${a} and ${b} km from the worksite`,
    atLeast: (a) => `live at least ${a} km from the worksite`,
    atMost: (b) => `live within ${b} km of the worksite`,
    missing: 'no distance recorded',
  },
  experience: {
    between: (a, b) => `have between ${a} and ${b} months of work experience`,
    atLeast: (a) => `have at least ${a} months of work experience`,
    atMost: (b) => `have no more than ${b} months of work experience`,
    missing: null,
  },
}

const GENERIC_PHRASES = {
  between: (a, b) => `have a value between ${a} and ${b}`,
  atLeast: (a) => `have a value of at least ${a}`,
  atMost: (b) => `have a value of at most ${b}`,
  missing: 'nothing recorded',
}

/** A hard filter is checked as Must Have whether or not the box is ticked. */
export function isMustHave(mandatory, criteriaType) {
  return Boolean(mandatory) || criteriaType === 'hard_filter'
}

/**
 * The badge for a lower-is-better requirement. Only shown where points are
 * at stake: on a Must Have the direction changes nothing.
 */
export function directionBadge(code) {
  if (code === 'availability') return 'Fewer days = higher score'
  if (code === 'distance') return 'Shorter distance = higher score'
  return 'Lower value = higher score'
}

/** Labels, helper text, and placeholders for the two number fields. */
export function rangeLabels({ mustHave, lowerIsBetter }) {
  if (mustHave) {
    return {
      min: {
        label: 'Minimum Required',
        // On a lower-is-better requirement a floor shuts out the best
        // applicants - someone free to start today, say - so say so.
        hint: lowerIsBetter
          ? 'Usually left blank: anyone below this is not eligible, even though lower is better.'
          : 'Anyone below this is not eligible.',
        placeholder: 'No limit',
      },
      max: { label: 'Maximum Allowed', hint: 'Anyone above this is not eligible.', placeholder: 'No limit' },
    }
  }

  // Worded to stay true whether one number or both are set: with both, the
  // exact upper number already earns nothing; with only it, it earns all.
  if (lowerIsBetter) {
    return {
      min: { label: 'Full Points Up To', hint: "At or below this earns all of this requirement's points.", placeholder: 'Not set' },
      max: {
        label: 'No Points Beyond',
        hint: 'Above this earns none of its points. It does not affect eligibility.',
        placeholder: 'Not set',
      },
    }
  }

  return {
    min: {
      label: 'Minimum Required',
      hint: 'Below this earns none of its points. It does not affect eligibility.',
      placeholder: 'Not set',
    },
    max: { label: 'Full Points At', hint: "At or above this earns all of this requirement's points.", placeholder: 'Not set' },
  }
}

function toNumber(value) {
  if (value === null || value === undefined || value === '') return null
  const number = Number(value)
  return Number.isFinite(number) ? number : null
}

const round1 = (n) => Math.round(n * 10) / 10

/**
 * The share of a scored requirement's points a value earns, 0 to 1. A copy of
 * applyScale in CompetencyScoringService, kept only to illustrate the rule on
 * screen; the server's result is the one that counts.
 */
export function shareAt(value, { min, max, lowerIsBetter }) {
  const low = toNumber(min)
  const high = toNumber(max)

  if (low !== null && high !== null && low !== high) {
    const ratio = Math.max(0, Math.min(1, (value - low) / (high - low)))
    return lowerIsBetter ? 1 - ratio : ratio
  }

  if (low !== null) return (lowerIsBetter ? value <= low : value >= low) ? 1 : 0
  if (high !== null) return value <= high ? 1 : 0

  return 1
}

/** The rule the two numbers make, as one sentence an officer can check. */
export function describeRange({ min, max, mustHave, lowerIsBetter, unit = '', code = '' }) {
  const low = toNumber(min)
  const high = toNumber(max)
  const show = (n) => (unit ? `${n} ${unit}` : `${n}`)

  if (mustHave) {
    const phrases = PHRASES[code] ?? GENERIC_PHRASES
    const rule =
      low !== null && high !== null
        ? phrases.between(low, high)
        : low !== null
          ? phrases.atLeast(low)
          : high !== null
            ? phrases.atMost(high)
            : null

    if (!rule) return 'No limit set. Applicants only need this recorded on their profile.'

    const others = phrases.missing
      ? `Anyone else, including anyone with ${phrases.missing}, is not eligible.`
      : 'Anyone else is not eligible.'

    return `Applicants must ${rule}. ${others}`
  }

  if (low !== null && high !== null && low !== high) {
    return lowerIsBetter
      ? `Full points at ${show(low)} or less, dropping to no points at ${show(high)} or more.`
      : `No points at ${show(low)} or less, rising to full points at ${show(high)} or more.`
  }

  if (low !== null) {
    return lowerIsBetter
      ? `Full points at ${show(low)} or less. No points above that.`
      : `Full points at ${show(low)} or more. No points below that.`
  }

  if (high !== null) return `Full points at ${show(high)} or less. No points above that.`

  return 'No range set. Any applicant with this recorded gets full points.'
}

/**
 * Worked figures for a sliding scale, in the officer's own importance
 * points: each end, and the point halfway along. Only for a scored
 * requirement with both numbers set, which is the one case where the points
 * change gradually and a sentence alone is hard to picture.
 */
export function pointsExample({ min, max, mustHave, lowerIsBetter, unit = '', weight }) {
  const low = toNumber(min)
  const high = toNumber(max)
  const points = toNumber(weight)

  if (mustHave || low === null || high === null || low >= high || !points || points <= 0) return null

  const show = (n) => (unit ? `${n} ${unit}` : `${n}`)
  const mid = round1((low + high) / 2)
  const at = (value) => round1(points * shareAt(value, { min: low, max: high, lowerIsBetter }))
  const pts = (n) => `${n} point${n === 1 ? '' : 's'}`

  const lowEnd = low <= 0 ? show(low) : `${show(low)} or less`
  const parts = [
    `${lowEnd}: ${pts(at(low))}`,
    `${show(mid)}: ${pts(at(mid))}`,
    `${show(high)} or more: ${pts(at(high))}`,
  ]

  return `With importance ${points}: ${parts.join(' · ')}.`
}

/**
 * Checks a pair of numbers before saving: the server refuses a second number
 * below the first, and a measure that cannot be negative (days, years, cm,
 * km, months) is refused here so the read-back never describes nonsense.
 *
 * @returns {{min?: string, max?: string} | null} the message for each field at fault
 */
export function rangeProblem(min, max, labels, unit = '') {
  const low = toNumber(min)
  const high = toNumber(max)
  const problems = {}

  if (unit && low !== null && low < 0) problems.min = `Enter 0 or more ${unit}.`
  if (unit && high !== null && high < 0) problems.max = `Enter 0 or more ${unit}.`

  if (!problems.min && !problems.max && low !== null && high !== null && high < low) {
    problems.max = `${labels.max.label} must be the same as or higher than ${labels.min.label}.`
  }

  return Object.keys(problems).length ? problems : null
}

/** Importance is checked against the same limits the server applies. */
export function importanceProblem(value, limit) {
  if (value === '' || value === null || value === undefined) return null

  const number = Number(value)
  if (!Number.isFinite(number) || number < 0 || number > limit) {
    return `Enter a number from 0 to ${limit}.`
  }

  return null
}
