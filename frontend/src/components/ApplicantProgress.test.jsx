import { describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import ApplicantProgress, { splitTransitions } from './ApplicantProgress'
import { renderAt } from '@/test/doubles'

/** The stages as GET /applicants/{id} returns them (App\Support\ApplicantStatuses). */
const STAGES = [
  { key: 'application', label: 'Application', statuses: ['applied'] },
  { key: 'screening', label: 'Screening', statuses: ['initial_screening'] },
  { key: 'requirements', label: 'Requirements', statuses: ['incomplete_requirements', 'primary_requirements_complete', 'pending_final_requirements'] },
  { key: 'training', label: 'Training', statuses: ['ready_for_deployment', 'training_scheduled', 'training_completed'] },
  { key: 'client', label: 'Client evaluation', statuses: ['client_evaluation', 'approved'] },
  { key: 'deployed', label: 'Deployed', statuses: ['deployed', 'active'] },
]

function renderProgress(props) {
  return renderAt(
    <ApplicantProgress stages={STAGES} canChange onMove={vi.fn()} transitions={[]} {...props} />
  )
}

function stageState(label) {
  const path = screen.getByRole('list', { name: 'Recruitment stages' })
  const item = within(path).getByText(label, { exact: false }).closest('li')
  return within(item).getByText(/\((done|current|not reached)\)/).textContent
}

describe('ApplicantProgress', () => {
  it('does not show training or the client as done for someone only ready for deployment', () => {
    renderProgress({ applicant: { current_status: 'ready_for_deployment' }, transitions: ['client_evaluation', 'archived'] })

    expect(stageState('Requirements')).toBe(' (done)')
    expect(stageState('Training')).toBe(' (current)')
    expect(stageState('Client evaluation')).toBe(' (not reached)')
    expect(stageState('Deployed')).toBe(' (not reached)')
  })

  it('offers the endorsement as the way forward, not as a sideways move', () => {
    const onMove = vi.fn()
    renderProgress({ applicant: { current_status: 'ready_for_deployment' }, transitions: ['client_evaluation', 'archived'], onMove })

    expect(screen.getByRole('button', { name: /endorse to a client/i })).toBeEnabled()
    expect(screen.getByRole('button', { name: /archived/i })).toBeInTheDocument()
  })

  it('says why the endorsement is blocked instead of letting it be refused', async () => {
    const onMove = vi.fn()
    renderProgress({
      applicant: { current_status: 'ready_for_deployment' },
      transitions: ['client_evaluation'],
      onMove,
      blockedMoves: { client_evaluation: 'Training must be recorded as completed first.' },
    })

    const button = screen.getByRole('button', { name: /endorse to a client/i })
    expect(button).toBeDisabled()
    expect(screen.getByText('Training must be recorded as completed first.')).toBeInTheDocument()
    await userEvent.click(button)
    expect(onMove).not.toHaveBeenCalled()
  })

  it('shows a closed record as closed rather than at a stage', () => {
    renderProgress({ applicant: { current_status: 'archived' } })

    expect(screen.getByText('This record is closed.')).toBeInTheDocument()
    expect(screen.queryByText(/\(current\)/)).not.toBeInTheDocument()
  })

  it('treats a move back a stage as another move', () => {
    const { forward, other } = splitTransitions(STAGES, 'client_evaluation', ['approved', 'ready_for_deployment', 'archived'])
    expect(forward).toEqual(['approved'])
    expect(other).toEqual(['ready_for_deployment', 'archived'])
  })
})
