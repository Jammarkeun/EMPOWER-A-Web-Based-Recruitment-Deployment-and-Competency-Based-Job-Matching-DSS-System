import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { fail, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn() }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { CompetencyTab, criterionProblems } = await import('./Settings')

const DISTANCE = {
  id: 5,
  criteria_code: 'distance',
  criteria_name: 'Distance from Worksite',
  criteria_type: 'weighted_scale',
  value_type: 'number',
  score_direction: 'lower_better',
  description: 'Kilometres between home and the worksite.',
  is_active: true,
  request_criteria_count: 2,
  company_criteria_count: 1,
}

const TYPING = {
  id: 9,
  criteria_code: 'typing_speed',
  criteria_name: 'Typing Speed',
  criteria_type: 'weighted_scale',
  value_type: 'number',
  score_direction: 'higher_better',
  description: 'Words per minute.',
  is_active: false,
  request_criteria_count: 0,
  company_criteria_count: 0,
}

function renderTab({ canEdit = true } = {}) {
  const onChanged = vi.fn()
  renderAt(<CompetencyTab data={{ groups: {}, criteria: [DISTANCE, TYPING] }} canEdit={canEdit} onChanged={onChanged} />)
  return onChanged
}

describe('Settings › Job requirements', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    api.post.mockReset()
    api.patch.mockReset()
  })

  it('names what an empty form is missing', () => {
    const empty = { criteria_code: '', criteria_name: ' ', description: '' }
    expect(Object.keys(criterionProblems(empty, false))).toEqual(['criteria_name', 'criteria_code', 'description'])
    // The code is fixed once created, so editing never asks for it.
    expect(criterionProblems(empty, true)).not.toHaveProperty('criteria_code')
  })

  it('lists the saved requirements with where each is used', () => {
    renderTab()

    expect(screen.getByText('Distance from Worksite')).toBeInTheDocument()
    expect(screen.getByText('Used on: 2 requests · 1 client')).toBeInTheDocument()
    expect(screen.getByText('Used on: Not used yet')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Offer Typing Speed' })).not.toBeChecked()
  })

  it('adds a requirement, refusing an incomplete one first', async () => {
    api.post.mockImplementation(() => ok({ id: 10 }))
    const onChanged = renderTab()

    await userEvent.click(screen.getByRole('button', { name: 'Add job requirement' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Add job requirement' }))

    expect(within(dialog).getByText('Enter a name.')).toBeInTheDocument()
    expect(within(dialog).getByText('Enter a code.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()

    await userEvent.type(within(dialog).getByLabelText(/^Name/), 'Physical Fitness')
    await userEvent.type(within(dialog).getByLabelText(/^Code/), 'Physical Fitness')
    await userEvent.type(within(dialog).getByLabelText(/^What it measures/), 'Screening fitness test result.')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Add job requirement' }))

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/settings/criteria', {
        criteria_code: 'physical_fitness',
        criteria_name: 'Physical Fitness',
        criteria_type: 'weighted_scale',
        value_type: 'number',
        score_direction: 'higher_better',
        description: 'Screening fitness test result.',
      })
    )
    expect(onChanged).toHaveBeenCalled()
    expect(toast.current.success).toHaveBeenCalledWith('Job requirement added', expect.any(String))
  })

  it('edits only what changed, and leaves the code alone', async () => {
    api.patch.mockImplementation(() => ok(DISTANCE))
    const onChanged = renderTab()

    await userEvent.click(screen.getByRole('button', { name: 'Edit Distance from Worksite' }))
    const dialog = await screen.findByRole('dialog')

    expect(within(dialog).getByLabelText(/^Code/)).toBeDisabled()
    expect(within(dialog).getByRole('button', { name: 'Save changes' })).toBeDisabled()

    const name = within(dialog).getByLabelText(/^Name/)
    await userEvent.clear(name)
    await userEvent.type(name, 'Distance to Worksite')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }))

    await waitFor(() =>
      expect(api.patch).toHaveBeenCalledWith('/settings/criteria/5', { criteria_name: 'Distance to Worksite' })
    )
    expect(onChanged).toHaveBeenCalled()
  })

  it('discards a cancelled edit', async () => {
    renderTab()

    await userEvent.click(screen.getByRole('button', { name: 'Edit Distance from Worksite' }))
    let dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByLabelText(/^Name/), ' (draft)')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())

    await userEvent.click(screen.getByRole('button', { name: 'Edit Distance from Worksite' }))
    dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText(/^Name/)).toHaveValue('Distance from Worksite')
    expect(api.patch).not.toHaveBeenCalled()
  })

  it('keeps the form open and says why when the server refuses it', async () => {
    api.patch.mockImplementation(() =>
      fail({ status: 422, message: 'Invalid', errors: { criteria_name: ['Another requirement already has this name.'] } })
    )
    const onChanged = renderTab()

    await userEvent.click(screen.getByRole('button', { name: 'Edit Distance from Worksite' }))
    const dialog = await screen.findByRole('dialog')
    const name = within(dialog).getByLabelText(/^Name/)
    await userEvent.clear(name)
    await userEvent.type(name, 'Age Range')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save changes' }))

    expect(await within(dialog).findByText('Another requirement already has this name.')).toBeInTheDocument()
    expect(toast.current.success).not.toHaveBeenCalled()
    expect(onChanged).not.toHaveBeenCalled()
  })

  it('confirms before turning a requirement off, saying what stays', async () => {
    api.patch.mockImplementation(() => ok({ ...DISTANCE, is_active: false }, { message: 'Requirement turned off.' }))
    const onChanged = renderTab()

    await userEvent.click(screen.getByRole('checkbox', { name: 'Offer Distance from Worksite' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/stays on the 2 request\(s\)/)).toBeInTheDocument()
    expect(api.patch).not.toHaveBeenCalled()

    await userEvent.click(within(dialog).getByRole('button', { name: 'Turn off' }))

    await waitFor(() => expect(api.patch).toHaveBeenCalledWith('/settings/criteria/5', { is_active: false }))
    expect(onChanged).toHaveBeenCalled()
  })

  it('reports a failed switch without claiming success', async () => {
    api.patch.mockImplementation(() => fail({ status: 500, message: 'Server unavailable.' }))
    const onChanged = renderTab()

    await userEvent.click(screen.getByRole('checkbox', { name: 'Offer Typing Speed' }))

    await waitFor(() =>
      expect(toast.current.error).toHaveBeenCalledWith('Could not turn the requirement on', 'Server unavailable.')
    )
    expect(toast.current.success).not.toHaveBeenCalled()
    expect(onChanged).not.toHaveBeenCalled()
  })

  it('offers no editing controls to someone who can only view', () => {
    renderTab({ canEdit: false })

    expect(screen.queryByRole('button', { name: 'Add job requirement' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Edit / })).not.toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Offer Distance from Worksite' })).toBeDisabled()
  })
})
