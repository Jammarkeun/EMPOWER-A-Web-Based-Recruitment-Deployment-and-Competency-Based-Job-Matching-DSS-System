import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { authWith, fail, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), upload: vi.fn() }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: DtrImportDialog, coverageProblems, mappingProblems, daysInRange } = await import('./DtrImportDialog')
const { default: DtrPeriodDialog } = await import('./DtrPeriodDialog')

const OPTIONS = {
  clients: [{ id: 3, company_name: 'Best Tiwi Food Products', status: 'active', placed_now: 4 }],
  max_period_days: 62,
  max_file_kb: 5120,
  max_rows: 10000,
  extensions: ['csv', 'txt', 'xlsx', 'xls'],
}

const MAPPING = {
  has_header: true,
  identifier_type: 'biometric_number',
  identifier_column: 0,
  date_column: 1,
  date_format: 'Y-m-d',
  time_in_column: 2,
  time_out_column: 3,
  name_column: null,
  remarks_column: null,
}

const DRAFT = {
  id: 7,
  import_code: 'DTR-2026-00007',
  status: 'draft',
  company: 'Best Tiwi Food Products',
  period_from: '2026-10-19',
  period_to: '2026-10-25',
  source_note: 'Received from client HR through the coordinator',
  original_filename: 'week.csv',
  file_size_bytes: 2048,
  total_rows: 4,
  dtr_period: { id: 11, period_from: '2026-10-19', period_to: '2026-10-25', status: 'received', status_label: 'Received' },
  columns: ['Bio', 'Date', 'In', 'Out'].map((header, index) => ({ index, letter: 'ABCD'[index], header })),
  sample: [{ line: 1, cells: ['Bio', 'Date', 'In', 'Out'] }],
  mapping: MAPPING,
  mapping_is_suggested: true,
  options: {
    identifier_types: [{ value: 'biometric_number', label: 'Biometrics number' }],
    date_formats: [{ value: 'Y-m-d', label: '2026-09-07' }],
  },
}

const PREVIEW = {
  counts: { new: 2, conflict: 0, unchanged: 0, blank_times: 0, duplicate_in_file: 1, unmatched_employee: 1, outside_period: 1 },
  ready: 2,
  errors: 2,
  summary: {
    total_rows: 5, valid_rows: 2, ready_rows: 2, invalid_rows: 2, skipped_rows: 1,
    unmatched_employees: 1, duplicates: 1, outside_period: 1,
    dates_in_file: { from: '2026-10-19', to: '2026-10-30' },
    punch_log_suspected: false,
    reasons: [
      { status: 'unmatched_employee', label: 'No such employee', rows: 1 },
      { status: 'outside_period', label: 'Outside the period of this file', rows: 1 },
    ],
  },
  rows: [
    { line: 3, status: 'unmatched_employee', label: 'No such employee', is_error: true, identifier: '555', raw: { date: '2026-10-20', time_in: '08:00', time_out: '17:00' } },
    { line: 4, status: 'outside_period', label: 'Outside the period of this file', is_error: true, identifier: '12', employee_name: 'Juan Reyes', work_date: '2026-10-30', raw: {} },
  ],
  rows_truncated: false,
  employees_in_file: 2,
  employees_without_rows: [],
}

describe('the checks the form makes before uploading', () => {
  it('holds the declared coverage to the same rules as the server', () => {
    const today = '2026-11-04'
    expect(coverageProblems({ period_from: '', period_to: '' }, 62, today)).toEqual({
      period_from: 'Enter the first day the DTR covers.',
      period_to: 'Enter the last day the DTR covers.',
    })
    expect(coverageProblems({ period_from: '2026-10-25', period_to: '2026-10-19' }, 62, today).period_to).toMatch(/cannot be before/)
    expect(coverageProblems({ period_from: '2026-09-01', period_to: '2026-11-02' }, 62, today).period_to).toMatch(/at most 62 days; this one says 63/)
    expect(coverageProblems({ period_from: '2026-09-02', period_to: '2026-11-02' }, 62, today)).toEqual({})
    expect(coverageProblems({ period_from: '2026-11-01', period_to: '2026-11-05' }, 62, today).period_to).toMatch(/already over/)
    expect(daysInRange('2026-10-19', '2026-10-25')).toBe(7)
  })

  it('refuses a column used twice, and one time column for both times as a punch log', () => {
    expect(mappingProblems(MAPPING)).toEqual([])
    expect(mappingProblems({ ...MAPPING, name_column: 0 })[0]).toMatch(/Employee identifier and Name \(to cross-check\) point at the same column/)
    expect(mappingProblems({ ...MAPPING, time_out_column: 2 })[0]).toMatch(/raw punch log/)
    expect(mappingProblems({ ...MAPPING, date_column: 'none' })[0]).toBe('Choose a column for: Date.')
  })
})

describe('Import DTR file', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    auth.current = authWith(['attendance.view', 'attendance.manage'])
    api.get.mockReset()
    api.post.mockReset()
    api.upload.mockReset()
  })

  it('lists the clients the server allows, with the source field and the file shown once chosen', async () => {
    api.get.mockImplementation(() => ok(OPTIONS))
    renderAt(<DtrImportDialog open onOpenChange={vi.fn()} />)

    expect(await screen.findByText(/Best Tiwi Food Products — 4 placed now/)).toBeInTheDocument()
    expect(api.get).toHaveBeenCalledWith('/attendance/imports/options', {}, expect.anything())
    expect(screen.getByText('Source / Handover Details')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Upload and choose columns/ })).toBeDisabled()
    expect(screen.getByText(/Still needed: a client, valid dates, a file/)).toBeInTheDocument()

    const input = document.querySelector('input[type="file"]')
    fireEvent.change(input, { target: { files: [new File(['Bio,Date\n'], 'week.csv', { type: 'text/csv' })] } })
    expect(screen.getByText('week.csv')).toBeInTheDocument()
    expect(screen.getByText('9 B')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Replace file' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Remove the chosen file' }))
    expect(screen.queryByText('week.csv')).not.toBeInTheDocument()
  })

  it('says so when no client can receive a DTR, and offers a retry when the list fails', async () => {
    api.get.mockImplementation(() => ok({ ...OPTIONS, clients: [] }))
    const { unmount } = renderAt(<DtrImportDialog open onOpenChange={vi.fn()} />)
    expect(await screen.findByText(/No client has workers placed with it yet/)).toBeInTheDocument()
    unmount()

    api.get.mockImplementation(() => fail({ status: 500, message: 'Server error' }))
    renderAt(<DtrImportDialog open onOpenChange={vi.fn()} />)
    expect(await screen.findByText(/The client list could not be loaded: Server error/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument()
  })

  it('reports a bad date range and a wrong file type before uploading', async () => {
    api.get.mockImplementation(() => ok(OPTIONS))
    renderAt(<DtrImportDialog open onOpenChange={vi.fn()} />)
    await screen.findByText(/4 placed now/)

    fireEvent.change(document.querySelectorAll('input[type="date"]')[0], { target: { value: '2026-10-25' } })
    fireEvent.change(document.querySelectorAll('input[type="date"]')[1], { target: { value: '2026-10-19' } })
    expect(screen.getByText('The last day cannot be before the first day.')).toBeInTheDocument()

    fireEvent.change(document.querySelector('input[type="file"]'), { target: { files: [new File(['%PDF'], 'dtr.pdf')] } })
    expect(screen.getByText('Only CSV and Excel (.csv, .xlsx, .xls) files can be imported.')).toBeInTheDocument()
    expect(api.upload).not.toHaveBeenCalled()
  })

  it('walks a draft through mapping, preview with its totals, confirmation and results', async () => {
    api.get.mockImplementation(() => ok(DRAFT))
    api.post.mockImplementation((url) =>
      url.endsWith('/preview')
        ? ok(PREVIEW)
        : ok(
            { ...DRAFT, status: 'completed', imported_rows: 2, replaced_rows: 0, unchanged_rows: 0, skipped_rows: 1, error_rows: 2,
              dtr_period: { ...DRAFT.dtr_period, status: 'processing', status_label: 'Processing' } },
            { message: '2 day(s) imported' }
          )
    )
    renderAt(<DtrImportDialog open draftId={7} onOpenChange={vi.fn()} onDone={vi.fn()} />)

    expect(await screen.findByText('week.csv')).toBeInTheDocument()
    expect(screen.getByText('Column mapping').closest('li')).toHaveAttribute('aria-current', 'step')
    fireEvent.click(screen.getByRole('button', { name: 'Check rows' }))

    // Preview: the totals, and a warning that the file's dates run past the declared days.
    const tiles = await screen.findByText('Unmatched employees')
    expect(tiles.parentElement).toHaveTextContent('1')
    expect(screen.getByText('Outside the period').parentElement).toHaveTextContent('1')
    expect(screen.getByText(/The file has dates from/)).toBeInTheDocument()
    expect(screen.getByText('No such employee')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Continue' }))
    expect(screen.getByText(/Importing does not say the client’s DTR/)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Import 2 day(s)' }))

    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/attendance/imports/7/confirm', { conflict_action: 'skip', reason: null }))
    const results = await screen.findByLabelText('Import results')
    expect(within(results).getByText('2 day(s) added')).toBeInTheDocument()
    expect(within(results).getByText('Processing')).toBeInTheDocument()
  })

  it('keeps Check rows disabled while two choices point at the same column', async () => {
    api.get.mockImplementation(() => ok({ ...DRAFT, mapping: { ...MAPPING, time_out_column: 2 } }))
    renderAt(<DtrImportDialog open draftId={7} onOpenChange={vi.fn()} />)

    expect(await screen.findByText(/raw punch log/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Check rows' })).toBeDisabled()
  })
})

describe('DTR period', () => {
  const PERIOD = {
    id: 11,
    company: 'Best Tiwi Food Products',
    period_from: '2026-10-19',
    period_to: '2026-10-25',
    status: 'processing',
    status_label: 'Processing',
    files: { imported: 1, drafts: 0, discarded: 0 },
    imports: [{ id: 7, import_code: 'DTR-2026-00007', status: 'completed', original_filename: 'week.csv', period_from: '2026-10-19', period_to: '2026-10-25', imported_rows: 15, uploaded_by: 'HR Officer', uploaded_at: '2026-11-04T09:00:00+08:00' }],
    events: [],
    reconciliation: {
      employees: [{ employee_id: 4, employee_number: 'EMP-4', full_name: 'Dario Villanueva', scheduled_days: 5, records: 0, days_awaiting_dtr: 5, days_no_record: 0, has_schedule: true }],
      totals: { employees_placed: 1, employees_with_records: 0, records: 0, scheduled_days: 5, days_awaiting_dtr: 5, days_no_record: 0 },
    },
  }

  beforeEach(() => {
    toast.current = toastDouble()
    auth.current = authWith(['attendance.manage'])
    api.get.mockReset()
    api.post.mockReset()
  })

  it('confirms a period complete only once the gaps are acknowledged', async () => {
    api.get.mockImplementation(() => ok(PERIOD))
    api.post.mockImplementation(() => ok({ ...PERIOD, status: 'complete', status_label: 'Complete for Period' }, { message: 'DTR confirmed complete for the period' }))
    renderAt(<DtrPeriodDialog open periodId={11} onOpenChange={vi.fn()} />)

    expect(await screen.findByText('Dario Villanueva')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Confirm complete for period' }))

    const submit = screen.getAllByRole('button', { name: 'Confirm complete for period' }).at(-1)
    expect(submit).toBeDisabled()
    fireEvent.click(screen.getByRole('checkbox'))
    expect(submit).toBeEnabled()
    fireEvent.click(submit)

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/attendance/dtr-periods/11/status', { action: 'complete', note: null, acknowledge_gaps: true })
    )
    expect(await screen.findByText('Complete for Period')).toBeInTheDocument()
  })

  it('marks a submission incomplete only with a note of what is missing', async () => {
    api.get.mockImplementation(() => ok(PERIOD))
    api.post.mockImplementation(() => ok({ ...PERIOD, status: 'incomplete', status_label: 'Incomplete Submission' }, { message: 'Marked' }))
    renderAt(<DtrPeriodDialog open periodId={11} onOpenChange={vi.fn()} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Mark as incomplete submission' }))
    const submit = screen.getAllByRole('button', { name: 'Mark as incomplete submission' }).at(-1)
    expect(submit).toBeDisabled()

    fireEvent.change(screen.getByLabelText(/What is missing/), { target: { value: 'Page 2 not received' } })
    fireEvent.click(submit)
    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith('/attendance/dtr-periods/11/status', { action: 'incomplete', note: 'Page 2 not received', acknowledge_gaps: undefined })
    )
  })
})
