import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { fail, ok, renderAt } from '@/test/doubles'

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  patch: vi.fn(),
  del: vi.fn(),
  api: { get: vi.fn() },
}))
vi.mock('@/lib/api', () => api)

const toast = vi.hoisted(() => ({ current: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() } }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: PortalBioData } = await import('./PortalBioData')

const OPTIONS = {
  sex: [{ value: 'male', label: 'Male' }, { value: 'female', label: 'Female' }],
  civil_status: [{ value: 'single', label: 'Single' }, { value: 'married', label: 'Married' }],
  education_level: [{ value: 'high_school', label: 'High School' }, { value: 'college_graduate', label: 'College Graduate' }],
}

const FORM = {
  sex: 'female',
  civil_status: 'single',
  nationality: 'Filipino',
  height_cm: 158,
  weight_kg: null,
  provincial_address: null,
  educations: [{ education_level: 'high_school', school_name: 'Sta. Cruz NHS', course_program: null, graduation_year: 2018, honors: null }],
  experiences: [],
}

function bioData(overrides = {}) {
  return {
    state: 'not_started',
    state_label: 'Not started',
    can_edit: true,
    lock_reason: null,
    profile: {
      applicant_code: 'APP-2026-00042',
      first_name: 'Maria',
      middle_name: null,
      last_name: 'Santos',
      suffix: null,
      birth_date: '2000-05-01',
      age: 26,
      present_address: 'Brgy. Calios, Sta. Cruz',
      contact_number: '09171234567',
      email: 'maria@example.test',
    },
    form: FORM,
    draft_saved_at: null,
    missing: {},
    options: OPTIONS,
    versions: [],
    submitted: null,
    earlier_verification: null,
    signed_copy_required: false,
    ...overrides,
  }
}

function renderPage(data) {
  api.get.mockImplementation((url) => (url === '/portal/bio-data' ? ok(data) : ok({})))
  return renderAt(<PortalBioData />, { path: '/portal/bio-data' })
}

describe('My Bio-Data', () => {
  beforeEach(() => {
    Object.values(api).forEach((fn) => typeof fn === 'function' && fn.mockReset())
    window.scrollTo = vi.fn()
    Element.prototype.scrollIntoView = vi.fn()
  })

  it('opens with what the office has on record, the checked details read-only', async () => {
    renderPage(bioData())

    await screen.findByText('Step 1 of 5:')
    expect(screen.getByText('Maria Santos')).toBeInTheDocument()
    expect(screen.getByText(/checked against your documents at the office/i)).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'Female' })).toBeChecked()
    expect(screen.getByLabelText(/citizenship/i)).toHaveValue('Filipino')
    // The name is shown, never an input.
    expect(screen.queryByDisplayValue('Maria')).not.toBeInTheDocument()
  })

  it('saves a draft before moving on, and only the form fields are sent', async () => {
    const saved = bioData({ state: 'draft', draft_saved_at: '2026-11-02T10:00:00+08:00', form: { ...FORM, sex: 'male' } })
    api.put.mockReturnValue(ok(saved, { message: 'Draft saved.' }))
    renderPage(bioData())

    await userEvent.click(await screen.findByRole('radio', { name: 'Male' }))
    await userEvent.click(screen.getByRole('button', { name: /save and continue/i }))

    await waitFor(() => expect(api.put).toHaveBeenCalledTimes(1))
    const [url, body] = api.put.mock.calls[0]
    expect(url).toBe('/portal/bio-data/draft')
    expect(body.sex).toBe('male')
    expect(body.weight_kg).toBeNull()
    expect(body).not.toHaveProperty('applicant_id')
    expect(await screen.findByText(/Step 2 of 5/)).toBeInTheDocument()
  })

  it('cannot be submitted while something required is missing', async () => {
    renderPage(bioData({ missing: { nationality: 'Enter your citizenship.' } }))

    await screen.findByText('Step 1 of 5:')
    for (let i = 0; i < 4; i += 1) {
      api.put.mockReturnValueOnce(ok(bioData({ missing: { nationality: 'Enter your citizenship.' } })))
      await userEvent.click(screen.getByRole('button', { name: /save and (continue|review)/i }))
    }

    expect(await screen.findByText('Still to complete before you can submit')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enter your citizenship.' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /submit bio-data/i })).toBeDisabled()
  })

  it('asks for confirmation, then sends and shows what was sent', async () => {
    const sent = bioData({
      state: 'submitted',
      can_edit: false,
      lock_reason: 'Your Bio-Data has been sent and is waiting for the office.',
      submitted: FORM,
      versions: [{ version_number: 1, submitted_at: '2026-11-02T10:05:00+08:00', review_outcome: null, reviewed_at: null, review_note: null }],
    })
    api.post.mockReturnValue(ok(sent, { message: 'Your Bio-Data has been sent to the office.' }))
    renderPage(bioData())

    await screen.findByText('Step 1 of 5:')
    for (let i = 0; i < 4; i += 1) {
      api.put.mockReturnValueOnce(ok(bioData()))
      await userEvent.click(screen.getByRole('button', { name: /save and (continue|review)/i }))
    }

    await userEvent.click(await screen.findByRole('button', { name: /submit bio-data/i }))
    const dialog = await screen.findByRole('dialog')
    const send = within(dialog).getByRole('button', { name: /submit bio-data/i })
    expect(send).toBeDisabled()

    await userEvent.click(within(dialog).getByRole('checkbox'))
    await userEvent.click(send)

    expect(api.post).toHaveBeenCalledWith('/portal/bio-data/submit', { confirm: true })
    expect(await screen.findByText('What you sent')).toBeInTheDocument()
    expect(screen.getByText('Sent to the office')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /save draft/i })).not.toBeInTheDocument()
  })

  it('shows why it was returned and lets the applicant correct it', async () => {
    renderPage(bioData({
      state: 'needs_correction',
      versions: [{ version_number: 1, submitted_at: '2026-11-01T09:00:00+08:00', review_outcome: 'needs_correction', reviewed_at: '2026-11-02T09:00:00+08:00', review_note: 'Civil status does not match your marriage certificate.' }],
    }))

    expect(await screen.findByText('The office asked for a correction')).toBeInTheDocument()
    expect(screen.getAllByText('Civil status does not match your marriage certificate.').length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: /save draft/i })).toBeInTheDocument()
    expect(screen.getByText('Returned for correction')).toBeInTheDocument()
  })

  it('puts a refused value back beside its field, on its step', async () => {
    api.put.mockImplementation(() => fail({ status: 422, message: 'Invalid', errors: { height_cm: ['Enter your height in centimetres, between 100 and 250.'] } }))
    renderPage(bioData())

    await screen.findByText('Step 1 of 5:')
    await userEvent.click(screen.getByRole('button', { name: /save draft/i }))

    expect(await screen.findByText('Enter your height in centimetres, between 100 and 250.')).toBeInTheDocument()
  })
})
