import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), api: { get: vi.fn() } }))
vi.mock('@/lib/api', () => api)

const auth = vi.hoisted(() => ({ current: null }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))

const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: BioDataReviewDialog } = await import('./BioDataReviewDialog')

const OPTIONS = {
  sex: [{ value: 'male', label: 'Male' }, { value: 'female', label: 'Female' }],
  civil_status: [{ value: 'single', label: 'Single' }, { value: 'married', label: 'Married' }],
  education_level: [{ value: 'high_school', label: 'High School' }],
}

const VERSION = {
  id: 7,
  version_number: 2,
  submitted_at: '2026-11-02T10:00:00+08:00',
  submitted_by: 'Maria Santos',
  review_outcome: null,
  reviewed_at: null,
  reviewed_by: null,
  review_note: null,
  applied_changes: [],
  would_change: { civil_status: ['single', 'married'] },
  data: {
    profile: { first_name: 'Maria', last_name: 'Santos', birth_date: '2000-05-01', present_address: 'Sta. Cruz' },
    form: {
      sex: 'female', civil_status: 'married', nationality: 'Filipino', height_cm: 158, weight_kg: null,
      provincial_address: null, educations: [], experiences: [],
    },
  },
}

function staffView(overrides = {}) {
  return {
    state: 'under_review',
    can_review: true,
    has_draft: false,
    versions: [VERSION, { ...VERSION, id: 6, version_number: 1, review_outcome: 'needs_correction', review_note: 'Wrong civil status', would_change: null }],
    recorded_qualifications: { educations: [], experiences: [] },
    options: OPTIONS,
    ...overrides,
  }
}

function renderDialog(onReviewed = vi.fn()) {
  renderAt(
    <BioDataReviewDialog applicant={{ id: 42, full_name: 'Maria Santos' }} open onOpenChange={vi.fn()} onReviewed={onReviewed} />
  )
  return { onReviewed }
}

describe('BioDataReviewDialog', () => {
  beforeEach(() => {
    api.get.mockReset()
    api.post.mockReset()
    auth.current = authWith(['requirements.verify'])
    toast.current = toastDouble()
    api.get.mockReturnValue(ok(staffView()))
  })

  it('shows what verifying would change on the profile, and keeps earlier versions', async () => {
    renderDialog()

    expect(await screen.findByText(/Verifying will update the profile/)).toHaveTextContent('Civil status Single → Married')
    expect(screen.getByText(/on file: Single/)).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/applicants/42/bio-data')

    await userEvent.click(screen.getByRole('tab', { name: 'Version 1' }))
    expect(screen.getByText(/Wrong civil status/)).toBeInTheDocument()
    // An earlier version is read-only.
    expect(screen.queryByRole('button', { name: /verify bio-data/i })).not.toBeInTheDocument()
  })

  it('will not return it without saying what to correct', async () => {
    renderDialog()

    await userEvent.click(await screen.findByRole('button', { name: /return for correction/i }))
    await userEvent.click(screen.getByRole('button', { name: /return for correction/i }))

    expect(screen.getByText('Say what the applicant needs to correct.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it('verifies and hands the updated requirement back to the checklist', async () => {
    const result = {
      requirement: { requirement_type_id: 2, status: 'verified' },
      folder: { label: 'Folder 2' },
      applicant_status: 'initial_screening',
      bio_data: staffView({ state: 'verified', can_review: false }),
    }
    api.post.mockReturnValue(ok(result, { message: 'Bio-Data verified' }))
    const { onReviewed } = renderDialog()

    await userEvent.click(await screen.findByRole('button', { name: /verify bio-data/i }))

    expect(api.post).toHaveBeenCalledWith('/applicants/42/bio-data/review', { outcome: 'verified', note: null })
    expect(onReviewed).toHaveBeenCalledWith(result)
    expect(toast.current.success).toHaveBeenCalledWith('Bio-Data verified', 'Folder is now Folder 2.')
  })

  it('offers no decision to someone who cannot verify', async () => {
    auth.current = authWith([])
    renderDialog()

    await screen.findByText(/Verifying will update the profile/)
    expect(screen.queryByRole('button', { name: /verify bio-data/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /return for correction/i })).not.toBeInTheDocument()
  })
})
