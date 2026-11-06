import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { authWith, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), api: { get: vi.fn() } }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))
// The existing importer is its own component with its own tests; here it is
// enough to see which draft it is opened on.
vi.mock('@/components/attendance/DtrImportDialog', () => ({
  default: ({ open, draftId }) => (open ? <div role="dialog" aria-label="Import DTR file">importer on draft {draftId}</div> : null),
}))

const { DtrSubmissionDialog } = await import('./DtrSubmissionInbox')

function submission(overrides = {}) {
  return {
    id: 9,
    submission_code: 'DTRS-2026-0009',
    status: 'submitted',
    status_label: 'Submitted to HR',
    company: 'DTR Sample Client Co. (Synthetic Test Data)',
    period_from: '2026-10-19',
    period_to: '2026-10-25',
    original_filename: 'EMPOWER_DTR_Valid_Test_Data.csv',
    file_size_bytes: 1350,
    total_rows: 15,
    submitted_by: 'Synthetic Coordinator',
    submitted_at: '2026-10-26T09:00:00+08:00',
    source_note: 'Received from the client HR on 26 Oct',
    review_note: null,
    import: null,
    draft_import_id: null,
    resubmission_of: null,
    resubmission: null,
    events: [{ id: 1, label: 'Submitted to HR', by: 'Synthetic Coordinator', at: '2026-10-26T09:00:00+08:00', note: null }],
    ...overrides,
  }
}

function open(record) {
  api.get.mockImplementation(() => ok(record))
  renderAt(<DtrSubmissionDialog submissionId={9} open onOpenChange={vi.fn()} onChanged={vi.fn()} />)
}

describe('HR DTR submission inbox', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    auth.current = authWith(['attendance.view', 'attendance.manage'])
    api.get.mockReset()
    api.post.mockReset()
  })

  it('shows what the coordinator sent, with the declared client and coverage', async () => {
    open(submission())

    expect(await screen.findByText('EMPOWER_DTR_Valid_Test_Data.csv · 1 KB · 15 row(s)')).toBeInTheDocument()
    expect(screen.getByText('DTR Sample Client Co. (Synthetic Test Data)')).toBeInTheDocument()
    expect(screen.getByText('Received from the client HR on 26 Oct')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Original file' })).toBeInTheDocument()
  })

  it('opens the stored file in the existing importer, without uploading it again', async () => {
    api.post.mockResolvedValue({ data: { import_id: 44, import_code: 'DTR-2026-0044' } })
    open(submission())

    fireEvent.click(await screen.findByRole('button', { name: 'Check and import' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/attendance/dtr-submissions/9/import'))
    expect(await screen.findByRole('dialog', { name: 'Import DTR file' })).toHaveTextContent('importer on draft 44')
  })

  it('returns a file only with a reason the coordinator can act on', async () => {
    api.post.mockResolvedValue({ data: submission({ status: 'returned' }), message: 'Returned to the coordinator for correction' })
    open(submission({ status: 'under_review', status_label: 'Under HR review', draft_import_id: 44 }))

    expect(await screen.findByRole('button', { name: 'Continue import' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Return' }))
    fireEvent.click(screen.getByRole('button', { name: 'Return to coordinator' }))
    expect(await screen.findByText(/Say what needs correcting/)).toBeInTheDocument()
    expect(api.post).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText(/^What needs correcting/), { target: { value: 'Wrong week; ask for 19-25 Oct.' } })
    fireEvent.click(screen.getByRole('button', { name: 'Return to coordinator' }))
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/attendance/dtr-submissions/9/decision', { action: 'return', note: 'Wrong week; ask for 19-25 Oct.' })
    )
  })

  it('offers no actions once the file has been imported', async () => {
    open(
      submission({
        status: 'imported',
        status_label: 'Imported',
        import: { import_code: 'DTR-2026-0044', completed_by: 'HR Officer', completed_at: '2026-10-27T10:00:00+08:00', imported_rows: 15, error_rows: 0 },
      })
    )

    expect(await screen.findByText(/Imported as/)).toHaveTextContent('15 day(s) added')
    expect(screen.queryByRole('button', { name: 'Check and import' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Return' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument()
  })
})
