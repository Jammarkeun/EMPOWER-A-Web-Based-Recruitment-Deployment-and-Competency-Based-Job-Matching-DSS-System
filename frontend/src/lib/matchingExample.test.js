import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { DEFAULT_BANDS, LANDING_EXAMPLE, bandFor, round2, scoreExample } from './matchingExample'

const here = path.dirname(fileURLToPath(import.meta.url))

describe('the landing page matching example (audit FN-06)', () => {
  const result = scoreExample(LANDING_EXAMPLE.criteria)

  it('adds its own rows up to its total and percentage', () => {
    const sum = result.rows.reduce((total, row) => total + row.awarded, 0)
    expect(round2(sum)).toBeCloseTo(result.raw, 1)
    expect(result.max).toBe(LANDING_EXAMPLE.criteria.reduce((total, c) => total + c.weight, 0))
    expect(result.percentage).toBeCloseTo((result.raw / result.max) * 100, 1)
  })

  it('shows the figures the engine produces for these inputs', () => {
    // Pinned on the server by tests/Unit/LandingMatchingExampleTest.php.
    expect(result.rows.map((row) => row.awarded)).toEqual([15, 10, 10, 4.58])
    expect(result.raw).toBe(39.58)
    expect(result.percentage).toBe(79.15)
    expect(result.band).toBe('recommended')
    expect(result.bandLabel).toBe('Recommended')
    expect(result.rows[3].note).toBe('3.4 km against a range of 0 to 40 km, scoring 92%.')
  })

  it('no longer claims 85 % "Highly recommended"', () => {
    expect(result.percentage).not.toBe(85)
    expect(result.band).not.toBe('highly_recommended')
  })

  it('states the bands the backend ships with', () => {
    const config = readFileSync(path.resolve(here, '../../../backend/laravel/config/empower.php'), 'utf8')
    const block = config.match(/'recommendation_bands'\s*=>\s*\[([\s\S]*?)\]/)[1]
    const value = (key) => Number(block.match(new RegExp(`'${key}'\\s*=>\\s*(\\d+)`))[1])

    expect(DEFAULT_BANDS).toEqual({
      highly_recommended: value('highly_recommended'),
      recommended: value('recommended'),
      reserve_pool: value('reserve_pool'),
    })
  })

  it('bands a score the way ApplicantScore does', () => {
    expect(bandFor(85)).toBe('highly_recommended')
    expect(bandFor(84.99)).toBe('recommended')
    expect(bandFor(70)).toBe('recommended')
    expect(bandFor(50)).toBe('reserve_pool')
    expect(bandFor(49.99)).toBe('not_recommended')
  })

  it('rounds halves away from zero, as PHP does', () => {
    expect(round2(4.575)).toBe(4.58)
    expect(round2(79.145)).toBe(79.15)
  })
})
