import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { fail, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), upload: vi.fn(), patch: vi.fn(), del: vi.fn(), api: { get: vi.fn() } }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => ({ can: () => true }) }))
// Leaflet cannot draw in jsdom; a stand-in map that can be "clicked".
vi.mock('@/components/map/MapCanvas', () => ({
  default: ({ onPick, interactive = true }) =>
    interactive ? (
      <button type="button" onClick={() => onPick({ lat: 14.2814571, lng: 121.4161847 })}>
        map click
      </button>
    ) : null,
}))

const { NewClientDialog } = await import('./Clients')

async function fillRequired() {
  await userEvent.type(screen.getByLabelText(/^Company name/), 'Best Tiwi Food Products')
  await userEvent.type(screen.getByLabelText(/^Business type/), 'Food Manufacturing')
  await userEvent.type(screen.getByLabelText(/^Office address/), 'Sta. Cruz, Laguna')
}

/** Details → Documents → Review → Add client, adding no documents. */
async function submitWithoutDocuments() {
  await userEvent.click(screen.getByRole('button', { name: /Next: documents/ }))
  await userEvent.click(await screen.findByRole('button', { name: /Next: review/ }))
  await userEvent.click(await screen.findByRole('button', { name: 'Add client' }))
}

function pdf(name) {
  return new File(['%PDF-1.4 test'], name, { type: 'application/pdf' })
}

/** Adds a document entry on the Documents step and fills it in. */
async function addDocument(title, file) {
  await userEvent.click(screen.getByRole('button', { name: /Add (a|another) document/ }))
  const titles = screen.getAllByLabelText(/^Document title/)
  const files = screen.getAllByLabelText(/^File/)
  if (title) await userEvent.type(titles[titles.length - 1], title)
  if (file) await userEvent.upload(files[files.length - 1], file)
}

describe('NewClientDialog', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    api.post.mockReset()
    api.upload.mockReset()
  })

  it('names a bad phone number before moving on, and keeps what was typed', async () => {
    renderAt(<NewClientDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />)
    await fillRequired()
    await userEvent.type(screen.getByLabelText(/^Contact number/), '12345')
    await userEvent.click(screen.getByRole('button', { name: /Next: documents/ }))

    expect(await screen.findByText('A phone number has between 7 and 15 digits.')).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
    expect(screen.getByLabelText(/^Company name/)).toHaveValue('Best Tiwi Food Products')
  })

  it('sends a valid number exactly as written, leading zero included', async () => {
    api.post.mockImplementation(() => ok({ id: 1 }))
    const onCreated = vi.fn()
    renderAt(<NewClientDialog open onOpenChange={vi.fn()} onCreated={onCreated} />)
    await fillRequired()
    await userEvent.type(screen.getByLabelText(/^Contact number/), '(049) 501-2233')
    await submitWithoutDocuments()

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/clients', expect.objectContaining({ contact_number: '(049) 501-2233' }))
    )
    expect(onCreated).toHaveBeenCalled()
    expect(api.upload).not.toHaveBeenCalled()
  })

  it('shows the server’s refusal beside the field without claiming success', async () => {
    api.post.mockImplementation(() =>
      fail({ status: 422, message: 'Invalid', errors: { email: ['The email field must be a valid email address.'] } })
    )
    renderAt(<NewClientDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />)
    await fillRequired()
    await userEvent.type(screen.getByLabelText(/^Email/), 'office@tiwi')
    await submitWithoutDocuments()

    // Back on the details step, beside the field, with everything kept.
    expect(await screen.findByText('The email field must be a valid email address.')).toBeInTheDocument()
    expect(toast.current.success).not.toHaveBeenCalled()
    expect(screen.getByLabelText(/^Email/)).toHaveValue('office@tiwi')
  })

  it('saves the confirmed pin with the new company, and sends none when nothing was pinned', async () => {
    api.post.mockImplementation(() => ok({ id: 1 }))
    const { unmount } = renderAt(<NewClientDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />)
    await fillRequired()

    await userEvent.click(screen.getByRole('button', { name: 'Pin company location' }))
    await userEvent.click(await screen.findByRole('button', { name: 'map click' }))
    await userEvent.click(screen.getByRole('button', { name: 'Confirm location' }))
    expect(await screen.findByText(/Location selected/)).toBeInTheDocument()
    // Picking a location kept what was typed.
    expect(screen.getByLabelText(/^Company name/)).toHaveValue('Best Tiwi Food Products')

    await submitWithoutDocuments()
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith(
        '/clients',
        expect.objectContaining({ company_name: 'Best Tiwi Food Products', latitude: 14.2814571, longitude: 121.4161847 })
      )
    )
    unmount()

    api.post.mockClear()
    renderAt(<NewClientDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />)
    await fillRequired()
    await submitWithoutDocuments()
    await waitFor(() => expect(api.post).toHaveBeenCalled())
    expect(api.post.mock.calls[0][1]).not.toHaveProperty('latitude')
    expect(api.post.mock.calls[0][1]).not.toHaveProperty('longitude')
  })

  it('uploads each document to the new company under the title typed for it', async () => {
    api.post.mockImplementation(() => ok({ id: 7, company_name: 'Best Tiwi Food Products' }))
    api.upload.mockImplementation((url, body) => ok({ id: 1, title: body.get('title') }))
    renderAt(<NewClientDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />)
    await fillRequired()
    await userEvent.click(screen.getByRole('button', { name: /Next: documents/ }))

    await addDocument('BIR Registration Certificate', pdf('2303.pdf'))
    await addDocument("Mayor's Permit 2026", pdf('permit.pdf'))
    await userEvent.click(screen.getByRole('button', { name: /Next: review/ }))

    // The review lists both before anything is sent.
    expect(screen.getByText('BIR Registration Certificate')).toBeInTheDocument()
    expect(screen.getByText("Mayor's Permit 2026")).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()

    await userEvent.click(screen.getByRole('button', { name: 'Add client' }))

    expect(await screen.findByText(/was added with 2 documents/)).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledTimes(1)
    expect(api.upload).toHaveBeenCalledTimes(2)
    expect(api.upload.mock.calls.map(([url]) => url)).toEqual(['/clients/7/documents', '/clients/7/documents'])
    expect(api.upload.mock.calls.map(([, body]) => body.get('title'))).toEqual([
      'BIR Registration Certificate',
      "Mayor's Permit 2026",
    ])
    expect(api.upload.mock.calls[0][1].get('file').name).toBe('2303.pdf')
    expect(screen.getByRole('button', { name: 'Open company documents' })).toBeInTheDocument()
  })

  it('keeps the saved company when an upload fails, and retries only that document', async () => {
    api.post.mockImplementation(() => ok({ id: 7, company_name: 'Best Tiwi Food Products' }))
    api.upload
      .mockImplementationOnce(() => ok({ id: 1 }))
      .mockImplementationOnce(() => fail({ status: 503, message: 'Storage is unavailable.' }))
    renderAt(<NewClientDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />)
    await fillRequired()
    await userEvent.click(screen.getByRole('button', { name: /Next: documents/ }))
    await addDocument('BIR Registration Certificate', pdf('2303.pdf'))
    await addDocument('Business Permit', pdf('permit.pdf'))
    await userEvent.click(screen.getByRole('button', { name: /Next: review/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Add client' }))

    expect(await screen.findByText('Not uploaded: Storage is unavailable.')).toBeInTheDocument()
    expect(screen.getByText(/1 of 2 documents were uploaded; 1 was not/)).toBeInTheDocument()
    // The failed entry is kept as typed.
    expect(screen.getByLabelText(/^Document title/)).toHaveValue('Business Permit')

    api.upload.mockReset()
    api.upload.mockImplementation(() => ok({ id: 2 }))
    await userEvent.click(screen.getByRole('button', { name: /Retry failed uploads/ }))

    expect(await screen.findByText(/was added with 2 documents/)).toBeInTheDocument()
    expect(api.upload).toHaveBeenCalledTimes(1)
    expect(api.upload.mock.calls[0][0]).toBe('/clients/7/documents')
    expect(api.upload.mock.calls[0][1].get('title')).toBe('Business Permit')
    // Never a second company.
    expect(api.post).toHaveBeenCalledTimes(1)
  })

  it('asks for a title and a usable file before anything is sent', async () => {
    renderAt(<NewClientDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />)
    await fillRequired()
    await userEvent.click(screen.getByRole('button', { name: /Next: documents/ }))
    await addDocument(null, pdf('permit.pdf'))
    await userEvent.click(screen.getByRole('button', { name: /Next: review/ }))

    expect(await screen.findByText(/Give the document a title/)).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText(/^Document title/), 'Program')
    const exe = new File(['MZ'], 'setup.exe', { type: 'application/x-msdownload' })
    await userEvent.upload(screen.getByLabelText(/^File/), exe, { applyAccept: false })
    await userEvent.click(screen.getByRole('button', { name: /Next: review/ }))

    expect(await screen.findByText(/This kind of file cannot be uploaded/)).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()
    expect(api.upload).not.toHaveBeenCalled()
  })

  it('drops an entry that was added but never filled in', async () => {
    api.post.mockImplementation(() => ok({ id: 7, company_name: 'Best Tiwi Food Products' }))
    const onOpenChange = vi.fn()
    renderAt(<NewClientDialog open onOpenChange={onOpenChange} onCreated={vi.fn()} />)
    await fillRequired()
    await userEvent.click(screen.getByRole('button', { name: /Next: documents/ }))
    await userEvent.click(screen.getByRole('button', { name: /Add a document/ }))
    await userEvent.click(screen.getByRole('button', { name: /Next: review/ }))
    await userEvent.click(await screen.findByRole('button', { name: 'Add client' }))

    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
    expect(api.upload).not.toHaveBeenCalled()
  })
})
