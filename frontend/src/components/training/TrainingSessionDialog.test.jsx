import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ok, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: TrainingSessionDialog } = await import('./TrainingSessionDialog')
const { LiveUpdatesProvider } = await import('@/contexts/LiveUpdatesContext')
const { useApi } = await import('@/hooks/useApi')

/** What the server holds; a test changes it to play somebody else's edit. */
let server

function serve() {
  api.get.mockImplementation((url) => {
    if (url === '/live') return ok({ version: server.version })
    // A fresh object on every call, as a real refetch delivers it.
    return ok({ ...server.session })
  })
}

/** The session page: the record arrives through useApi, which live updates refetch. */
function SessionPage({ onOpenChange }) {
  const { data } = useApi('/trainings/7')
  if (!data) return null
  return <TrainingSessionDialog open onOpenChange={onOpenChange} session={data} />
}

function renderPage(onOpenChange = vi.fn()) {
  render(
    <LiveUpdatesProvider>
      <SessionPage onOpenChange={onOpenChange} />
    </LiveUpdatesProvider>
  )
  return onOpenChange
}

const sessionFetches = () => api.get.mock.calls.filter(([url]) => url === '/trainings/7').length

/** Somebody else saves something: the live marker moves and the page is told. */
async function somebodyElseSaves(change) {
  server.session = { ...server.session, ...change }
  server.version = 'v2'
  const before = sessionFetches()
  document.dispatchEvent(new Event('visibilitychange'))
  await waitFor(() => expect(sessionFetches()).toBe(before + 1))
}

describe('TrainingSessionDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    toast.current = toastDouble()
    server = {
      version: 'v1',
      session: {
        id: 7,
        training_title: 'Food Safety Orientation',
        training_date: '2026-10-20',
        location: 'Training Room',
        trainer_name: 'Ms. Santos',
        remarks: '',
      },
    }
    serve()
  })

  it('keeps what was typed when a background refresh delivers the record again', async () => {
    renderPage()
    const title = await screen.findByLabelText(/^Title/)
    // The first live check is only the baseline.
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/live', null, expect.anything()))

    await userEvent.clear(title)
    await userEvent.type(title, 'Food Safety Orientation (Batch 2)')

    await somebodyElseSaves({ location: 'Plant 2' })

    expect(screen.getByLabelText(/^Title/)).toHaveValue('Food Safety Orientation (Batch 2)')
    expect(screen.getByLabelText(/^Location/)).toHaveValue('Training Room')

    // Only the title was edited, so only the title is sent: the location the
    // other person saved in the meantime is not overwritten with the old one.
    api.patch.mockImplementation(() => ok({ ...server.session, training_title: 'Food Safety Orientation (Batch 2)' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() =>
      expect(api.patch).toHaveBeenCalledWith('/trainings/7', { training_title: 'Food Safety Orientation (Batch 2)' })
    )
  })

  it('shows the refreshed record when nothing has been typed', async () => {
    renderPage()
    await screen.findByLabelText(/^Title/)
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('/live', null, expect.anything()))

    await somebodyElseSaves({ location: 'Plant 2' })

    await waitFor(() => expect(screen.getByLabelText(/^Location/)).toHaveValue('Plant 2'))
  })

  it('asks before Escape or Cancel throws typed changes away', async () => {
    const onOpenChange = renderPage()
    const title = await screen.findByLabelText(/^Title/)
    await userEvent.type(title, ' - draft')

    await userEvent.keyboard('{Escape}')
    expect(await screen.findByText('Discard your changes?')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Keep editing' }))

    await waitFor(() => expect(screen.queryByText('Discard your changes?')).not.toBeInTheDocument())
    expect(screen.getByLabelText(/^Title/)).toHaveValue('Food Safety Orientation - draft')
    expect(onOpenChange).not.toHaveBeenCalled()

    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Discard changes' }))
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('closes without asking when nothing was changed, or after a successful save', async () => {
    const onOpenChange = renderPage()
    await screen.findByLabelText(/^Title/)

    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(onOpenChange).toHaveBeenCalledWith(false)
    expect(screen.queryByText('Discard your changes?')).not.toBeInTheDocument()

    onOpenChange.mockClear()
    await userEvent.type(screen.getByLabelText(/^Title/), ' II')
    api.patch.mockImplementation(() => ok({ ...server.session, training_title: 'Food Safety Orientation II' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
    expect(screen.queryByText('Discard your changes?')).not.toBeInTheDocument()
    expect(toast.current.success).toHaveBeenCalledWith('Session updated')
  })

  it('keeps what was typed when the server refuses the save', async () => {
    const onOpenChange = renderPage()
    const title = await screen.findByLabelText(/^Title/)
    await userEvent.clear(title)
    await userEvent.type(title, 'x')

    api.patch.mockImplementation(() =>
      Promise.reject({ status: 422, isValidation: true, message: 'Invalid', errors: { training_title: ['The title is too short.'] } })
    )
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    expect(await screen.findByText('The title is too short.')).toBeInTheDocument()
    expect(screen.getByLabelText(/^Title/)).toHaveValue('x')
    expect(onOpenChange).not.toHaveBeenCalled()
    // The field to fix is brought into focus, not left behind the Save button.
    await waitFor(() => expect(screen.getByLabelText(/^Title/)).toHaveFocus())
    expect(screen.getByLabelText(/^Title/)).toHaveAccessibleDescription('The title is too short.')
  })

  it('brings the first of several refused fields into focus, in the order they appear', async () => {
    renderPage()
    await screen.findByLabelText(/^Title/)
    api.patch.mockImplementation(() =>
      Promise.reject({
        status: 422,
        isValidation: true,
        message: 'Invalid',
        errors: { location: ['Say where the session is held.'], trainer_name: ['Name the trainer.'] },
      })
    )

    await userEvent.type(screen.getByLabelText(/^Remarks/), ' Bring a pen.')
    await userEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    // Trainer comes before Location on the form.
    await waitFor(() => expect(screen.getByLabelText(/^Trainer/)).toHaveFocus())
  })
})
