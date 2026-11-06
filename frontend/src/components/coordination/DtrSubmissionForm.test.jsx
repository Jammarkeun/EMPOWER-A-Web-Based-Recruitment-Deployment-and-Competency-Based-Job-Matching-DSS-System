import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), upload: vi.fn(), api: { get: vi.fn() } }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: DtrSubmissionForm } = await import('./DtrSubmissionForm')

const OPTIONS = {
  clients: [{ id: 4, company_name: 'DTR Sample Client Co. (Synthetic Test Data)' }],
  max_period_days: 62,
  max_file_kb: 5120,
  max_rows: 10000,
  extensions: ['csv', 'txt', 'xlsx', 'xls'],
}

function csv(name = 'dtr-oct.csv', size = 1200) {
  const file = new File(['x'], name, { type: 'text/csv' })
  Object.defineProperty(file, 'size', { value: size })
  return file
}

function render(props = {}) {
  const onSubmitted = vi.fn()
  renderAt(<DtrSubmissionForm open onOpenChange={vi.fn()} onSubmitted={onSubmitted} {...props} />)
  return onSubmitted
}

describe('Coordinator DTR submission form', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    api.get.mockReset().mockImplementation(() => ok(OPTIONS))
    api.upload.mockReset()
  })

  it('offers only the assigned client and checks the period and file before review', async () => {
    render()

    const client = await screen.findByLabelText(/^Client company/)
    expect(client).toBeDisabled()
    expect(client).toHaveTextContent('DTR Sample Client Co. (Synthetic Test Data)')

    fireEvent.change(screen.getByLabelText(/^DTR covers from/), { target: { value: '2026-08-01' } })
    fireEvent.change(screen.getByLabelText(/^To/), { target: { value: '2026-10-25' } })
    fireEvent.click(screen.getByRole('button', { name: 'Review' }))

    expect(await screen.findByText('Choose the DTR file to send.')).toBeInTheDocument()
    expect(screen.getByText(/at most 62 days/i)).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText(/^DTR file/), { target: { files: [csv('dtr.pdf')] } })
    expect(await screen.findByText('Only CSV and Excel (.csv, .xlsx, .xls) files can be sent.')).toBeInTheDocument()
    expect(api.upload).not.toHaveBeenCalled()
  })

  it('shows what will be sent, then sends it to HR without importing anything', async () => {
    api.upload.mockResolvedValue({ data: { id: 31, status: 'submitted' }, message: 'DTR file sent to HR' })
    const onSubmitted = render()

    await screen.findByDisplayValue('DTR Sample Client Co. (Synthetic Test Data)')
    fireEvent.change(screen.getByLabelText(/^DTR covers from/), { target: { value: '2026-10-19' } })
    fireEvent.change(screen.getByLabelText(/^To/), { target: { value: '2026-10-25' } })
    fireEvent.change(screen.getByLabelText(/^Source \/ handover details/), { target: { value: 'From client HR by email' } })
    fireEvent.change(screen.getByLabelText(/^DTR file/), { target: { files: [csv()] } })
    expect(screen.getByText('dtr-oct.csv')).toBeInTheDocument()
    expect(screen.getByText('1 KB')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Review' }))
    expect(await screen.findByText('Check before sending')).toBeInTheDocument()
    expect(screen.getByText(/7 day\(s\)/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Send to HR' }))
    await waitFor(() => expect(api.upload).toHaveBeenCalled())

    const [url, body] = api.upload.mock.calls[0]
    expect(url).toBe('/coordinator/dtr-submissions')
    expect(body.get('period_from')).toBe('2026-10-19')
    expect(body.get('period_to')).toBe('2026-10-25')
    expect(body.get('source_note')).toBe('From client HR by email')
    expect(body.get('file').name).toBe('dtr-oct.csv')
    // The client is never sent: the server takes it from the assignment.
    expect(body.has('client_company_id')).toBe(false)
    await waitFor(() => expect(onSubmitted).toHaveBeenCalledWith({ id: 31, status: 'submitted' }))
  })

  it('sends a corrected file against the one HR returned, showing why it came back', async () => {
    api.upload.mockResolvedValue({ data: { id: 32 }, message: 'Corrected DTR file sent to HR' })
    render({
      correcting: { id: 30, submission_code: 'DTRS-2026-0030', period_from: '2026-10-19', period_to: '2026-10-25', review_note: 'Wrong week.' },
    })

    expect(await screen.findByText('Wrong week.')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/^DTR file/), { target: { files: [csv('dtr-fixed.csv')] } })
    fireEvent.click(screen.getByRole('button', { name: 'Review' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Send to HR' }))

    await waitFor(() => expect(api.upload).toHaveBeenCalled())
    expect(api.upload.mock.calls[0][0]).toBe('/coordinator/dtr-submissions/30/resubmit')
  })
})
