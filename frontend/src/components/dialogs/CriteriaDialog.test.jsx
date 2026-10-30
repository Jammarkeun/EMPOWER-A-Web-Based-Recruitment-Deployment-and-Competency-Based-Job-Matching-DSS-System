import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { fail, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: CriteriaDialog } = await import('./CriteriaDialog')

const CATALOG = [
  { id: 1, criteria_code: 'age', criteria_name: 'Age Range', criteria_type: 'hard_filter', score_direction: 'higher_better', accepts: 'none', description: 'Age bracket.' },
  { id: 2, criteria_code: 'distance', criteria_name: 'Distance from Worksite', criteria_type: 'weighted_scale', score_direction: 'lower_better', accepts: 'none', description: 'Kilometres.' },
  { id: 3, criteria_code: 'experience', criteria_name: 'Relevant Work Experience', criteria_type: 'weighted_scale', score_direction: 'higher_better', accepts: 'none', description: 'Months.' },
  { id: 4, criteria_code: 'skills', criteria_name: 'Skills Match', criteria_type: 'weighted_scale', score_direction: 'higher_better', accepts: 'list', list_match: 'graded', description: 'Skills.' },
]

const CONFIGURED = [
  { criteria_code: 'age', mandatory_flag: true, weight_score: 0, min_value: 18, max_value: 35, expected_value: null },
  { criteria_code: 'distance', mandatory_flag: false, weight_score: 20, min_value: 5, max_value: 30, expected_value: null },
  { criteria_code: 'experience', mandatory_flag: false, weight_score: 30, min_value: 0, max_value: 36, expected_value: null },
  { criteria_code: 'skills', mandatory_flag: false, weight_score: 10, min_value: null, max_value: null, expected_value: 'Teamwork, Machine Operation' },
]

function renderDialog(onSaved = vi.fn()) {
  api.get.mockImplementation(() => ok({ catalog: CATALOG, configured: CONFIGURED }))
  renderAt(<CriteriaDialog open onOpenChange={vi.fn()} jobRequestId={7} onSaved={onSaved} />)
  return onSaved
}

describe('CriteriaDialog', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    api.post.mockReset()
  })

  it('names each number for what it does on that requirement', async () => {
    renderDialog()

    // Must Have: an eligible range.
    expect(await screen.findByLabelText('Minimum Required (years)')).toHaveValue(18)
    expect(screen.getByLabelText('Maximum Allowed (years)')).toHaveValue(35)
    expect(screen.getByText(/^Applicants must be between 18 and 35 years old\./)).toBeInTheDocument()

    // Scored, lower is better: the first number is where full points stop.
    expect(screen.getByLabelText('Full Points Up To (km)')).toHaveValue(5)
    expect(screen.getByLabelText('No Points Beyond (km)')).toHaveValue(30)
    expect(screen.getByText('Full points at 5 km or less, dropping to no points at 30 km or more.')).toBeInTheDocument()
    expect(screen.getByText('Shorter distance = higher score')).toBeInTheDocument()

    // Scored, higher is better: the second number is where full points start.
    expect(screen.getByLabelText('Full Points At (months)')).toHaveValue(36)

    expect(screen.queryByText(/^Min$|^Max$|Weight/)).not.toBeInTheDocument()
    // Age is Must Have, so it adds nothing: 20 + 30 + 10.
    expect(screen.getByText(/Total importance:/)).toHaveTextContent('Total importance: 60 points')
  })

  it('saves exactly what it saved before the wording changed', async () => {
    const onSaved = renderDialog()
    api.post.mockImplementation(() => ok([]))

    await userEvent.click(await screen.findByRole('button', { name: 'Save requirements' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/job-requests/7/criteria', {
        criteria: [
          { criteria_code: 'age', mandatory_flag: true, weight_score: 0, min_value: 18, max_value: 35, expected_value: null },
          { criteria_code: 'distance', mandatory_flag: false, weight_score: 20, min_value: 5, max_value: 30, expected_value: null },
          { criteria_code: 'experience', mandatory_flag: false, weight_score: 30, min_value: 0, max_value: 36, expected_value: null },
          { criteria_code: 'skills', mandatory_flag: false, weight_score: 10, min_value: null, max_value: null, expected_value: 'Teamwork, Machine Operation' },
        ],
      })
    )
    expect(onSaved).toHaveBeenCalled()
  })

  it('stops a range that runs backwards before it reaches the server', async () => {
    renderDialog()

    const highest = await screen.findByLabelText('Maximum Allowed (years)')
    await userEvent.clear(highest)
    await userEvent.type(highest, '10')

    expect(
      screen.getByText('Maximum Allowed must be the same as or higher than Minimum Required.')
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save requirements' })).toBeDisabled()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('stops importance outside what the server accepts', async () => {
    renderDialog()

    const importance = (await screen.findAllByLabelText('Importance (points)'))[0]
    await userEvent.clear(importance)
    await userEvent.type(importance, '5000')

    expect(screen.getByText('Enter a number from 0 to 1000.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save requirements' })).toBeDisabled()
  })

  it('explains availability as it is edited, in days and in points', async () => {
    api.get.mockImplementation(() =>
      ok({
        catalog: [
          { id: 6, criteria_code: 'availability', criteria_name: 'Availability to Start', criteria_type: 'weighted_scale', score_direction: 'lower_better', accepts: 'none', description: 'Days until start.' },
          CATALOG[0],
        ],
        configured: [
          { criteria_code: 'availability', mandatory_flag: false, weight_score: 20, min_value: 0, max_value: 30, expected_value: null },
          CONFIGURED[0],
        ],
      })
    )
    renderAt(<CriteriaDialog open onOpenChange={vi.fn()} jobRequestId={7} />)

    expect(await screen.findByText('Fewer days = higher score')).toBeInTheDocument()
    expect(screen.getByLabelText('Full Points Up To (days)')).toHaveValue(0)
    expect(
      screen.getByText('With importance 20: 0 days: 20 points · 15 days: 10 points · 30 days or more: 0 points.')
    ).toBeInTheDocument()

    // The age sentence follows whatever is typed.
    const oldest = screen.getByLabelText('Maximum Allowed (years)')
    await userEvent.clear(oldest)
    await userEvent.type(oldest, '40')
    expect(screen.getByText(/^Applicants must be between 18 and 40 years old\./)).toBeInTheDocument()
    await userEvent.clear(oldest)
    expect(screen.getByText(/^Applicants must be at least 18 years old\./)).toBeInTheDocument()

    // Required for eligibility turns the same numbers into an eligible window,
    // and the direction badge goes: no points are at stake.
    await userEvent.click(screen.getAllByRole('checkbox', { name: /Required for eligibility/ })[0])
    expect(screen.getByLabelText('Maximum Allowed (days)')).toHaveValue(30)
    expect(screen.getByText(/^Applicants must be able to start in 0 to 30 days\./)).toBeInTheDocument()
    expect(screen.queryByText('Fewer days = higher score')).not.toBeInTheDocument()

    // Days cannot be negative, so the minus key does nothing in that field.
    const soonest = screen.getByLabelText('Minimum Required (days)')
    await userEvent.clear(soonest)
    await userEvent.type(soonest, '-2')
    expect(soonest).toHaveValue(2)
    expect(screen.queryByText('Enter 0 or more days.')).not.toBeInTheDocument()
  })

  it('shows a failed load as a failure, not as an empty request that could be saved', async () => {
    api.get.mockImplementation(() => fail({ status: 500, message: 'Server unavailable.' }))
    renderAt(<CriteriaDialog open onOpenChange={vi.fn()} jobRequestId={7} />)

    expect(await screen.findByText('Server unavailable.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Save requirements' })).not.toBeInTheDocument()
  })

  it('marks a requirement turned off in Settings that this request still uses', async () => {
    api.get.mockImplementation(() =>
      ok({ catalog: CATALOG.map((c) => (c.criteria_code === 'distance' ? { ...c, is_active: false } : c)), configured: CONFIGURED })
    )
    renderAt(<CriteriaDialog open onOpenChange={vi.fn()} jobRequestId={7} />)

    expect(await screen.findByText('Turned off')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Use Distance from Worksite' })).toBeChecked()
  })

  it('reports a refused save without claiming success', async () => {
    api.post.mockImplementation(() =>
      fail({ status: 422, message: 'Distance from Worksite has been turned off in Settings and can no longer be added.' })
    )
    const onSaved = renderDialog()

    await userEvent.click(await screen.findByRole('button', { name: 'Save requirements' }))

    await waitFor(() =>
      expect(toast.current.error).toHaveBeenCalledWith(
        'Some values need correcting',
        'Distance from Worksite has been turned off in Settings and can no longer be added.'
      )
    )
    expect(toast.current.success).not.toHaveBeenCalled()
    expect(onSaved).not.toHaveBeenCalled()
  })
})
