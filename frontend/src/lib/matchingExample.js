/**
 * The worked matching example on the landing page (audit FN-06).
 *
 * The example used to be typed in by hand: its rows added up to 39.6 of 50
 * points, 79.2 per cent - "Recommended" - while the header said 85 per cent,
 * "Highly recommended", which undercut the very point it was making. It is
 * now computed from its own rows with the engine's own rules
 * (CompetencyScoringService and ApplicantScore), so the total cannot drift
 * from the lines again. The test beside this file checks the arithmetic and
 * that the bands below still match config/empower.php.
 */

/** config/empower.php → recommendation_bands, as shipped. Settings can change them. */
export const DEFAULT_BANDS = {
  highly_recommended: 85,
  recommended: 70,
  reserve_pool: 50,
}

export const BAND_LABELS = {
  highly_recommended: 'Highly recommended',
  recommended: 'Recommended',
  reserve_pool: 'Reserve pool',
  not_recommended: 'Not recommended',
}

/** Half away from zero to two places, as PHP's round() does. */
export function round2(value) {
  const sign = value < 0 ? -1 : 1
  return (sign * Math.round((Math.abs(value) + Number.EPSILON) * 100)) / 100
}

/** The engine's multiplier for one criterion: 1 for a met threshold, the position in a range otherwise. */
export function multiplierFor(criterion) {
  if (criterion.kind === 'threshold') return criterion.met ? 1 : 0

  const { value, min, max, direction } = criterion
  const ratio = Math.max(0, Math.min(1, (value - min) / (max - min)))
  return direction === 'lower_better' ? 1 - ratio : ratio
}

/** The explanation the engine writes for a range criterion. */
function rangeNote(criterion, multiplier) {
  const unit = criterion.unit ? ` ${criterion.unit}` : ''
  return `${criterion.value}${unit} against a range of ${criterion.min} to ${criterion.max}${unit}, scoring ${Math.round(multiplier * 100)}%.`
}

export function bandFor(percentage, bands = DEFAULT_BANDS) {
  if (percentage >= bands.highly_recommended) return 'highly_recommended'
  if (percentage >= bands.recommended) return 'recommended'
  if (percentage >= bands.reserve_pool) return 'reserve_pool'
  return 'not_recommended'
}

/**
 * Scores an example the way ApplicantScore does: points are weight times
 * multiplier, the percentage is points over the weights of the scored
 * criteria, and each figure is rounded to two places for display.
 */
export function scoreExample(criteria, bands = DEFAULT_BANDS) {
  let raw = 0
  let max = 0

  const rows = criteria.map((criterion) => {
    const multiplier = multiplierFor(criterion)
    const awarded = criterion.weight * multiplier
    raw += awarded
    max += criterion.weight

    return {
      label: criterion.label,
      weight: criterion.weight,
      awarded: round2(awarded),
      note: criterion.kind === 'range' ? rangeNote(criterion, multiplier) : criterion.note,
    }
  })

  const percentage = max > 0 ? round2((raw / max) * 100) : 0
  const band = bandFor(percentage, bands)

  return { rows, raw: round2(raw), max: round2(max), percentage, band, bandLabel: BAND_LABELS[band] }
}

/** A production helper posting, scored on four of its criteria. */
export const LANDING_EXAMPLE = {
  name: 'Maria Cristina Santos',
  position: 'Production Helper',
  criteria: [
    { label: 'Educational attainment', weight: 15, kind: 'threshold', met: true, note: 'College graduate against a required high school.' },
    { label: 'Relevant experience', weight: 20, kind: 'range', value: 18, min: 0, max: 36, direction: 'higher_better', unit: 'months' },
    { label: 'Certifications', weight: 10, kind: 'threshold', met: true, note: 'Holds Food Safety NC II.' },
    { label: 'Distance from worksite', weight: 5, kind: 'range', value: 3.4, min: 0, max: 40, direction: 'lower_better', unit: 'km' },
  ],
}
