import { describe, expect, it } from 'vitest'
import {
  describeRange,
  directionBadge,
  importanceProblem,
  isMustHave,
  pointsExample,
  rangeLabels,
  rangeProblem,
  shareAt,
  unitFor,
} from './requirementRange'

/*
 * Each sentence is checked against the branch of CompetencyScoringService it
 * describes, so the wording cannot drift from what the ranking does. The
 * availability figures match backend/laravel/tests/Feature/AvailabilityScoringTest.php,
 * which runs the real engine on the same numbers.
 */
describe('requirementRange', () => {
  it('treats a hard filter as Must Have whether or not it is ticked', () => {
    expect(isMustHave(false, 'hard_filter')).toBe(true)
    expect(isMustHave(true, 'weighted_scale')).toBe(true)
    expect(isMustHave(false, 'weighted_scale')).toBe(false)
  })

  it('labels a Must Have range as the eligible bounds', () => {
    const labels = rangeLabels({ mustHave: true, lowerIsBetter: false })
    expect(labels.min).toMatchObject({ label: 'Minimum Required', hint: 'Anyone below this is not eligible.' })
    expect(labels.max).toMatchObject({ label: 'Maximum Allowed', hint: 'Anyone above this is not eligible.' })
  })

  it('warns that a floor on a lower-is-better Must Have shuts out the best applicants', () => {
    expect(rangeLabels({ mustHave: true, lowerIsBetter: true }).min.hint).toMatch(/^Usually left blank/)
  })

  it('labels a scored range by which end earns the points, and says it is not eligibility', () => {
    expect(rangeLabels({ mustHave: false, lowerIsBetter: true })).toMatchObject({
      min: { label: 'Full Points Up To' },
      max: { label: 'No Points Beyond', hint: expect.stringMatching(/does not affect eligibility/) },
    })
    expect(rangeLabels({ mustHave: false, lowerIsBetter: false })).toMatchObject({
      min: { label: 'Minimum Required', hint: expect.stringMatching(/does not affect eligibility/) },
      max: { label: 'Full Points At' },
    })
  })

  it('names the direction in the criterion’s own terms', () => {
    expect(directionBadge('availability')).toBe('Fewer days = higher score')
    expect(directionBadge('distance')).toBe('Shorter distance = higher score')
    expect(directionBadge('anything_else')).toBe('Lower value = higher score')
  })

  it('reads an age range as a person’s age, from whatever was entered (measureGate)', () => {
    const age = { mustHave: true, lowerIsBetter: false, unit: 'years', code: 'age' }
    expect(describeRange({ ...age, min: 18, max: 35 })).toBe(
      'Applicants must be between 18 and 35 years old. Anyone else, including anyone with no date of birth recorded, is not eligible.'
    )
    expect(describeRange({ ...age, min: 21, max: 40 })).toMatch(/^Applicants must be between 21 and 40 years old\./)
    expect(describeRange({ ...age, min: '18.00', max: '' })).toMatch(/^Applicants must be at least 18 years old\./)
    expect(describeRange({ ...age, min: null, max: 35 })).toMatch(/^Applicants must be no older than 35\./)
    expect(describeRange({ ...age, min: '', max: '' })).toMatch(/^No limit set/)
  })

  it('reads an availability Must Have as a start window', () => {
    const availability = { mustHave: true, lowerIsBetter: true, unit: 'days', code: 'availability' }
    expect(describeRange({ ...availability, min: '', max: 14 })).toBe(
      'Applicants must be able to start within 14 days. Anyone else, including anyone with no start date recorded, is not eligible.'
    )
  })

  it('leaves out the missing-record clause where the engine reads nothing as zero', () => {
    expect(describeRange({ min: 12, max: '', mustHave: true, code: 'experience' })).toBe(
      'Applicants must have at least 12 months of work experience. Anyone else is not eligible.'
    )
  })

  it('reads a scored range in the direction the scale runs (applyScale)', () => {
    expect(describeRange({ min: 0, max: 36, mustHave: false, lowerIsBetter: false, unit: 'months' })).toBe(
      'No points at 0 months or less, rising to full points at 36 months or more.'
    )
    expect(describeRange({ min: 0, max: 30, mustHave: false, lowerIsBetter: true, unit: 'days' })).toBe(
      'Full points at 0 days or less, dropping to no points at 30 days or more.'
    )
  })

  it('reads a lone first number on a lower-is-better requirement as a ceiling', () => {
    expect(describeRange({ min: 7, max: '', mustHave: false, lowerIsBetter: true, unit: 'days' })).toBe(
      'Full points at 7 days or less. No points above that.'
    )
  })

  it('reads equal numbers as a single cut-off, as the engine does', () => {
    expect(describeRange({ min: 12, max: 12, mustHave: false, lowerIsBetter: false, unit: 'months' })).toBe(
      'Full points at 12 months or more. No points below that.'
    )
  })

  it('reads a lone upper number as a limit in either direction', () => {
    expect(describeRange({ min: '', max: 30, mustHave: false, lowerIsBetter: true, unit: 'days' })).toBe(
      'Full points at 30 days or less. No points above that.'
    )
  })

  it('earns the same share of points the engine awards for availability', () => {
    const scale = { min: 0, max: 30, lowerIsBetter: true }
    expect(shareAt(0, scale)).toBe(1) // starts immediately
    expect(shareAt(15, scale)).toBe(0.5) // halfway
    expect(shareAt(30, scale)).toBe(0) // exactly the upper number
    expect(shareAt(45, scale)).toBe(0) // beyond it, never negative

    expect(shareAt(7, { min: 7, max: '', lowerIsBetter: true })).toBe(1)
    expect(shareAt(8, { min: 7, max: '', lowerIsBetter: true })).toBe(0)
    expect(shareAt(30, { min: '', max: 30, lowerIsBetter: true })).toBe(1)
    expect(shareAt(31, { min: '', max: 30, lowerIsBetter: true })).toBe(0)
  })

  it('works the figures out in the officer’s own importance points', () => {
    const availability = { min: 0, max: 30, mustHave: false, lowerIsBetter: true, unit: 'days' }
    expect(pointsExample({ ...availability, weight: 20 })).toBe(
      'With importance 20: 0 days: 20 points · 15 days: 10 points · 30 days or more: 0 points.'
    )
    expect(pointsExample({ ...availability, min: 3, weight: '10' })).toBe(
      'With importance 10: 3 days or less: 10 points · 16.5 days: 5 points · 30 days or more: 0 points.'
    )
  })

  it('gives no worked figures where they would not help or would be wrong', () => {
    const base = { min: 0, max: 30, lowerIsBetter: true, unit: 'days', weight: 20 }
    expect(pointsExample({ ...base, mustHave: true })).toBeNull()
    expect(pointsExample({ ...base, mustHave: false, weight: 0 })).toBeNull()
    expect(pointsExample({ ...base, mustHave: false, max: '' })).toBeNull()
    expect(pointsExample({ ...base, mustHave: false, min: 30 })).toBeNull()
  })

  it('names the field at fault when the numbers cannot work', () => {
    const labels = rangeLabels({ mustHave: true, lowerIsBetter: false })
    expect(rangeProblem(35, 18, labels, 'years')).toEqual({
      max: 'Maximum Allowed must be the same as or higher than Minimum Required.',
    })
    expect(rangeProblem(-1, 30, labels, 'days')).toEqual({ min: 'Enter 0 or more days.' })
    expect(rangeProblem('', -3, labels, 'days')).toEqual({ max: 'Enter 0 or more days.' })
    expect(rangeProblem(18, 18, labels, 'years')).toBeNull()
    // A number with no known unit, such as a rating, is not limited here.
    expect(rangeProblem(-1, 5, labels, '')).toBeNull()
  })

  it('checks importance against the limit the server applies', () => {
    expect(importanceProblem('20', 100)).toBeNull()
    expect(importanceProblem('', 100)).toBeNull()
    expect(importanceProblem('-1', 100)).toBe('Enter a number from 0 to 100.')
    expect(importanceProblem('1001', 1000)).toBe('Enter a number from 0 to 1000.')
  })

  it('knows the unit each numeric requirement is measured in', () => {
    expect(unitFor('availability')).toBe('days')
    expect(unitFor('age')).toBe('years')
    expect(unitFor('communication')).toBe('')
  })
})
