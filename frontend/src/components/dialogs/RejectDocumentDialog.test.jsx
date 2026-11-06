import { describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderAt } from '@/test/doubles'
import RejectDocumentDialog from './RejectDocumentDialog'

const REQUIREMENT = { requirement_type_id: 4, requirement_name: 'NBI Clearance' }

function renderDialog(onConfirm = vi.fn().mockResolvedValue(true), onOpenChange = vi.fn()) {
  renderAt(
    <RejectDocumentDialog
      open
      onOpenChange={onOpenChange}
      requirement={REQUIREMENT}
      applicantName="Juan Dela Cruz"
      onConfirm={onConfirm}
    />
  )
  return { onConfirm, onOpenChange }
}

describe('RejectDocumentDialog', () => {
  it('refuses to reject without a reason', async () => {
    const { onConfirm } = renderDialog()

    expect(screen.getByRole('heading', { name: 'Reject NBI Clearance' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /reject document/i }))

    expect(screen.getByText(/give the applicant a reason/i)).toBeInTheDocument()
    expect(onConfirm).not.toHaveBeenCalled()
  })

  it('fills the reason from a common one, which can still be edited, then closes once saved', async () => {
    const { onConfirm, onOpenChange } = renderDialog()

    await userEvent.click(screen.getByRole('button', { name: 'Expired' }))
    const box = screen.getByLabelText(/reason for rejection/i)
    expect(box).toHaveValue('This document has expired. Please submit a current copy.')

    await userEvent.type(box, ' Issued 2024.')
    await userEvent.click(screen.getByRole('button', { name: /reject document/i }))

    expect(onConfirm).toHaveBeenCalledWith('This document has expired. Please submit a current copy. Issued 2024.')
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('stays open when the rejection could not be saved', async () => {
    const { onOpenChange } = renderDialog(vi.fn().mockResolvedValue(false))

    await userEvent.type(screen.getByLabelText(/reason for rejection/i), 'Wrong file')
    await userEvent.click(screen.getByRole('button', { name: /reject document/i }))

    expect(onOpenChange).not.toHaveBeenCalledWith(false)
  })
})
