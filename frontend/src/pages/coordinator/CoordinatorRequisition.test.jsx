import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, fail, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), upload: vi.fn(), api: { delete: vi.fn() } }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: CoordinatorRequisition, validateRequisition } = await import('./CoordinatorRequisition')

const REASONS = [
  { value: 'new_position', label: 'New Position' },
  { value: 'replacement', label: 'Replacement' },
  { value: 'additional_manpower', label: 'Additional Manpower' },
  { value: 'reliever_manpower', label: 'Reliever Manpower' },
  { value: 'others', label: 'Others' },
]

function options() {
  return {
    form: { provisional: false, version: 'cde-2026-10', title: 'Manpower Requisition Form', reasons: REASONS },
    departments: [{ id: 3, department_name: 'Loading Department' }],
    positions: [{ id: 9, position_title: 'Packer' }],
    statuses: [],
  }
}

/** A requisition as the server sends it back, written on CDE's form. */
function requisition(overrides = {}) {
  return {
    id: 7,
    requisition_code: 'MRF-2026-00007',
    form_version: 'cde-2026-10',
    provisional: false,
    status: 'submitted',
    status_label: 'Submitted to HR',
    company_name: 'Best Tiwi Food Products Corporation',
    department_name: null,
    date_requested: '2026-10-22',
    required_by: '2026-10-23',
    workers_needed: 1,
    male_count: 1,
    female_count: null,
    position_title: 'SC Sealer',
    qualifications: "At least 5'2\" in height.",
    other_remarks: 'Heavy lifting.',
    request_reason: 'replacement',
    request_reason_label: 'Replacement',
    request_reason_details: 'Marinduque D.',
    requested_by: { id: 1, name: 'Coordinator One' },
    acknowledged_by: null,
    has_attachment: false,
    history: [],
    can_edit: false,
    can_discard: false,
    ...overrides,
  }
}

function serve(record) {
  api.get.mockImplementation((url) =>
    ok(url === '/coordinator/requisitions/options' ? options() : record)
  )
}

function fillRequired() {
  fireEvent.change(screen.getByLabelText(/^Date Needed/), { target: { value: '2099-01-10' } })
  fireEvent.change(screen.getByLabelText(/^Position title/), { target: { value: 'SC Sealer' } })
}

describe('Coordinator requisition form (CDE Manpower Requisition Form)', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    auth.current = authWith(['coordination.access'], {
      user_type: 'coordinator',
      full_name: 'Coordinator One',
      coordinator: { company_name: 'Best Tiwi Food Products Corporation' },
    })
    api.get.mockReset()
    api.post.mockReset()
    api.upload.mockReset()
  })

  it("shows CDE's fields in the form's order and locks the client company", async () => {
    serve(null)
    renderAt(<CoordinatorRequisition />, { path: '/coordinator/requisitions/new' })

    expect(await screen.findByRole('heading', { name: 'Manpower Requisition Form' })).toBeInTheDocument()
    expect(screen.queryByText(/Temporary Default Form/)).not.toBeInTheDocument()
    expect(screen.getByText('Best Tiwi Food Products Corporation')).toBeInTheDocument()

    const fields = [/^Date requested/, /^Date Needed/, /^Total Manpower Needed/, /^Required Qualification/, /^Other Remarks/].map(
      (label) => screen.getByLabelText(label)
    )
    fields.slice(1).forEach((field, i) => {
      expect(fields[i].compareDocumentPosition(field) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    })

    const reasonGroup = screen.getByRole('group', { name: /Reason of Request/ })
    expect(fields[4].compareDocumentPosition(reasonGroup) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    const reasons = within(reasonGroup).getAllByRole('radio')
    expect(reasons.map((radio) => radio.closest('label').textContent)).toEqual(REASONS.map((r) => r.label))
    expect(screen.getByText('Requested by')).toBeInTheDocument()
    expect(screen.getByText('Coordinator One')).toBeInTheDocument()
    expect(screen.getByText('Acknowledged by')).toBeInTheDocument()

    // Fields the temporary form had are gone.
    for (const retired of [/Employment type/, /Work location/, /Work schedule/, /Salary range/, /Supporting attachment/]) {
      expect(screen.queryByLabelText(retired)).not.toBeInTheDocument()
    }
  })

  it('points out missing fields in the server’s words before sending anything', async () => {
    serve(null)
    renderAt(<CoordinatorRequisition />, { path: '/coordinator/requisitions/new' })

    await userEvent.click(await screen.findByRole('button', { name: /save draft/i }))

    expect(await screen.findByText('Enter the Date Needed.')).toBeInTheDocument()
    expect(screen.getByText('Say which position the manpower is for.')).toBeInTheDocument()
    expect(screen.getByText('Tick the Reason of Request.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()

    // "Others" has a blank to fill.
    await userEvent.click(screen.getByRole('radio', { name: 'Others' }))
    await userEvent.click(screen.getByRole('button', { name: /submit to hr/i }))
    expect(await screen.findByText('Write the reason beside "Others".')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
  })

  it("sends CDE's fields only, and shows the server's validation messages beside them", async () => {
    serve(null)
    api.post.mockImplementation(() =>
      fail({ status: 422, message: 'Invalid', errors: { required_by: ['The Date Needed cannot be before the Date requested.'] } })
    )
    renderAt(<CoordinatorRequisition />, { path: '/coordinator/requisitions/new' })

    await screen.findByRole('heading', { name: 'Manpower Requisition Form' })
    fillRequired()
    fireEvent.change(screen.getByLabelText(/^Of whom male/), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText(/^Required Qualification/), { target: { value: 'At least 5ft 2in' } })
    await userEvent.click(screen.getByRole('radio', { name: 'Replacement' }))
    fireEvent.change(screen.getByLabelText(/^Replacement of/), { target: { value: 'Marinduque D.' } })
    await userEvent.click(screen.getByRole('button', { name: /save draft/i }))

    expect(await screen.findByText(/cannot be before the Date requested/)).toBeInTheDocument()
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1))
    const [url, body] = api.post.mock.calls[0]
    expect(url).toBe('/coordinator/requisitions')
    expect(body).toMatchObject({
      required_by: '2099-01-10',
      workers_needed: 1,
      male_count: 1,
      female_count: null,
      job_position_id: null,
      position_title: 'SC Sealer',
      qualifications: 'At least 5ft 2in',
      other_remarks: null,
      request_reason: 'replacement',
      request_reason_details: 'Marinduque D.',
      client_department_id: null,
      submit: false,
    })
    for (const key of ['client_company_id', 'reason', 'employment_type', 'work_schedule', 'attachment']) {
      expect(body).not.toHaveProperty(key)
    }
  })

  it('shows a submitted requisition on the form’s fields, with a print copy', async () => {
    serve(requisition({ acknowledged_by: { name: 'HR Officer', at: '2026-10-23T09:00:00+08:00' } }))
    renderAt(<CoordinatorRequisition />, { path: '/coordinator/requisitions/:id', route: '/coordinator/requisitions/7' })

    expect(await screen.findByRole('button', { name: /print form/i })).toBeInTheDocument()
    expect(screen.getAllByText('1 (1 male) for SC Sealer').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Replacement of Marinduque D.').length).toBeGreaterThan(0)
    expect(screen.getByText('MANPOWER REQUISITION FORM')).toBeInTheDocument()
    expect(screen.getByText('Sitio Bigayan, Brgy. Calios, Santa Cruz, Laguna')).toBeInTheDocument()
    expect(screen.getAllByText('HR Officer').length).toBeGreaterThan(0)
  })

  it('shows a requisition filed on the temporary form as it was, without the CDE print copy', async () => {
    serve(
      requisition({
        form_version: 'provisional-2026-10',
        provisional: true,
        request_reason: null,
        request_reason_label: null,
        request_reason_details: null,
        other_remarks: null,
        reason: 'Additional line for the holiday orders.',
        work_schedule: 'Night shift',
      })
    )
    renderAt(<CoordinatorRequisition />, { path: '/coordinator/requisitions/:id', route: '/coordinator/requisitions/7' })

    expect(await screen.findByText('Filed on the temporary default form')).toBeInTheDocument()
    expect(screen.getByText('Additional line for the holiday orders.')).toBeInTheDocument()
    expect(screen.getByText('Night shift')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /print form/i })).not.toBeInTheDocument()
    expect(screen.queryByText('MANPOWER REQUISITION FORM')).not.toBeInTheDocument()
  })
})

describe('validateRequisition', () => {
  const valid = {
    date_requested: '2026-10-22',
    required_by: '2026-10-23',
    workers_needed: '3',
    male_count: '',
    female_count: '',
    job_position_id: '9',
    position_title: '',
    request_reason: 'additional_manpower',
    request_reason_details: '',
  }

  it('accepts a complete form', () => {
    expect(validateRequisition(valid)).toEqual({})
  })

  it('applies the same cross-field rules as the server', () => {
    expect(validateRequisition({ ...valid, required_by: '2026-10-21' }).required_by).toEqual([
      'The Date Needed cannot be before the Date requested.',
    ])
    expect(validateRequisition({ ...valid, male_count: '2', female_count: '2' }).male_count).toEqual([
      'The numbers of men and women add up to more than the Total Manpower Needed.',
    ])
    expect(validateRequisition({ ...valid, workers_needed: '0' }).workers_needed).toEqual([
      'The Total Manpower Needed must be at least 1.',
    ])
  })
})
