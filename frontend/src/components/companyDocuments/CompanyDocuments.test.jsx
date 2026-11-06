import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, fail, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  upload: vi.fn(),
  patch: vi.fn(),
  del: vi.fn(),
  api: { get: vi.fn() },
}))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: CompanyDocuments } = await import('./CompanyDocuments')

const BIR = {
  id: 11,
  client_company_id: 5,
  title: 'BIR Registration Certificate',
  description: 'Form 2303',
  issued_on: '2024-01-15',
  expires_on: null,
  is_expired: false,
  file_name: 'scan_0012.pdf',
  file_type: 'PDF',
  file_size_bytes: 245760,
  uploaded_at: '2026-11-04T09:00:00+08:00',
  uploaded_by: 'Ana Reyes',
}
const PERMIT = {
  ...BIR,
  id: 12,
  title: "Mayor's Permit",
  description: null,
  issued_on: null,
  expires_on: '2025-12-31',
  is_expired: true,
  file_name: 'permit.jpg',
  file_type: 'Image',
}

const HR = ['clients.view', 'clients.update']
const ADMIN = [...HR, 'clients.delete']

function render(documents, permissions = HR) {
  auth.current = authWith(permissions)
  api.get.mockImplementation((url) => (url === '/clients/5/documents' ? ok(documents) : ok({ url: 'https://files.example/signed' })))
  return renderAt(<CompanyDocuments clientId="5" companyName="Best Tiwi Food Products" />)
}

function rowOf(title) {
  return screen.getByText(title).closest('li')
}

describe('CompanyDocuments', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    Object.values(api).forEach((fn) => fn.mockReset?.())
  })

  it('lists each document under its own title, apart from the file name', async () => {
    render([BIR, PERMIT])

    const bir = await screen.findByText('BIR Registration Certificate')
    const row = bir.closest('li')
    expect(within(row).getByText(/scan_0012\.pdf/)).toBeInTheDocument()
    expect(within(row).getByText('PDF')).toBeInTheDocument()
    expect(within(row).getByText(/by Ana Reyes/)).toBeInTheDocument()
    expect(within(row).getByText('Form 2303')).toBeInTheDocument()
    // An expiry is only shown where one was recorded.
    expect(within(row).queryByText(/Expire/)).not.toBeInTheDocument()
    expect(within(rowOf("Mayor's Permit")).getByText(/Expired/)).toBeInTheDocument()
  })

  it('says plainly when nothing has been uploaded, without calling it a problem', async () => {
    render([])

    expect(await screen.findByText('No company documents yet')).toBeInTheDocument()
    expect(screen.queryByText(/missing|required|non-?compliant/i)).not.toBeInTheDocument()
  })

  it('offers each action only to those allowed it', async () => {
    const { unmount } = render([BIR], ['clients.view'])
    await screen.findByText('BIR Registration Certificate')
    expect(screen.getByRole('button', { name: 'Open BIR Registration Certificate' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Download BIR Registration Certificate' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Add document/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Edit/ })).not.toBeInTheDocument()
    unmount()

    const hr = render([BIR], HR)
    await screen.findByText('BIR Registration Certificate')
    expect(screen.getByRole('button', { name: /Add document/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Replace the file of BIR Registration Certificate' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Remove BIR Registration Certificate' })).not.toBeInTheDocument()
    hr.unmount()

    render([BIR], ADMIN)
    expect(await screen.findByRole('button', { name: 'Remove BIR Registration Certificate' })).toBeInTheDocument()
  })

  it('adds a document with a custom title and shows it without reloading the list', async () => {
    render([BIR])
    api.upload.mockImplementation(() =>
      ok({ ...BIR, id: 13, title: 'FDA License to Operate', file_name: 'lto.pdf' }, { message: 'Saved' })
    )
    await screen.findByText('BIR Registration Certificate')

    await userEvent.click(screen.getByRole('button', { name: /Add document/ }))
    await userEvent.type(screen.getByLabelText(/^Document title/), 'FDA License to Operate')
    await userEvent.upload(screen.getByLabelText(/^File/), new File(['%PDF'], 'lto.pdf', { type: 'application/pdf' }))
    await userEvent.click(screen.getByRole('button', { name: 'Upload document' }))

    expect(await screen.findByText('FDA License to Operate')).toBeInTheDocument()
    const [url, body] = api.upload.mock.calls[0]
    expect(url).toBe('/clients/5/documents')
    expect(body.get('title')).toBe('FDA License to Operate')
    expect(body.get('file').name).toBe('lto.pdf')
    expect(api.get).toHaveBeenCalledTimes(1)
  })

  it('keeps what was entered when an upload fails, so it can be tried again', async () => {
    render([])
    api.upload.mockImplementationOnce(() => fail({ status: 503, message: 'Storage is unavailable.' }))
    await screen.findByText('No company documents yet')

    await userEvent.click(screen.getAllByRole('button', { name: /Add document/ })[0])
    await userEvent.type(screen.getByLabelText(/^Document title/), 'Business Permit')
    await userEvent.upload(screen.getByLabelText(/^File/), new File(['%PDF'], 'bp.pdf', { type: 'application/pdf' }))
    await userEvent.click(screen.getByRole('button', { name: 'Upload document' }))

    expect(await screen.findByText(/Upload failed: Storage is unavailable/)).toBeInTheDocument()
    expect(screen.getByLabelText(/^Document title/)).toHaveValue('Business Permit')

    api.upload.mockImplementationOnce(() => ok({ ...BIR, id: 14, title: 'Business Permit' }))
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByText('Business Permit')).toBeInTheDocument()
    expect(api.upload).toHaveBeenCalledTimes(2)
  })

  it('edits the title of one document only, leaving its file alone', async () => {
    render([BIR, PERMIT])
    api.patch.mockImplementation(() => ok({ ...BIR, title: 'BIR Certificate of Registration' }))
    await screen.findByText('BIR Registration Certificate')

    await userEvent.click(screen.getByRole('button', { name: 'Edit BIR Registration Certificate' }))
    const title = screen.getByLabelText(/^Document title/)
    await userEvent.clear(title)
    await userEvent.type(title, 'BIR Certificate of Registration')
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(await screen.findByText('BIR Certificate of Registration')).toBeInTheDocument()
    expect(api.patch).toHaveBeenCalledWith('/clients/5/documents/11', expect.objectContaining({ title: 'BIR Certificate of Registration' }))
    expect(api.patch.mock.calls[0][1]).not.toHaveProperty('file')
    expect(screen.getByText("Mayor's Permit")).toBeInTheDocument()
  })

  it('replaces the file of the chosen document through its own address', async () => {
    render([BIR, PERMIT])
    api.upload.mockImplementation(() => ok({ ...PERMIT, file_name: 'permit-2026.pdf', file_type: 'PDF' }))
    await screen.findByText("Mayor's Permit")

    await userEvent.click(screen.getByRole('button', { name: "Replace the file of Mayor's Permit" }))
    await userEvent.upload(screen.getByLabelText(/^New file/), new File(['%PDF'], 'permit-2026.pdf', { type: 'application/pdf' }))
    await userEvent.click(screen.getByRole('button', { name: 'Replace file' }))

    await waitFor(() => expect(api.upload).toHaveBeenCalled())
    expect(api.upload.mock.calls[0][0]).toBe('/clients/5/documents/12/file')
    expect(await screen.findByText(/permit-2026\.pdf/)).toBeInTheDocument()
    expect(within(rowOf('BIR Registration Certificate')).getByText(/scan_0012\.pdf/)).toBeInTheDocument()
  })

  it('opens a document through a short-lived link fetched on demand', async () => {
    render([BIR])
    const open = vi.spyOn(window, 'open').mockReturnValue({ opener: {} })
    await screen.findByText('BIR Registration Certificate')

    await userEvent.click(screen.getByRole('button', { name: 'Open BIR Registration Certificate' }))

    await waitFor(() => expect(open).toHaveBeenCalledWith('https://files.example/signed', '_blank'))
    expect(api.get).toHaveBeenCalledWith('/clients/5/documents/11/link')
    expect(toast.current.warning).not.toHaveBeenCalled()
    open.mockRestore()
  })
})
