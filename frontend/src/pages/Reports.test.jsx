import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ get: vi.fn(), getCached: vi.fn(), post: vi.fn(), api: { post: vi.fn(), get: vi.fn() } }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: Reports } = await import('./Reports')

function preview(overrides = {}) {
  return {
    title: 'Applicant Report',
    subtitle: 'Applicants on record with their current stage and document status',
    period: 'All records',
    columns: ['Code', 'Name'],
    rows: [['APP-2026-00021', 'Jeffrey Policarpio']],
    total_rows: 1,
    truncated: false,
    summary: [{ label: 'Total applicants', value: '1' }],
    generated_at: '29 October 2026, 9:15 pm',
    letterhead: { agency: 'CDE Manpower Services', address: 'Sitio Bigayan, Brgy. Calios, Santa Cruz, Laguna' },
    ...overrides,
  }
}

describe('Reports › printed preview', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    auth.current = authWith(['reports.view', 'reports.export'], { full_name: 'Maria Santos' })
    api.getCached.mockImplementation(() =>
      ok({ types: [{ key: 'applicants', label: 'Applicant Report', filters: [], options: {} }], positions: [] })
    )
    api.get.mockImplementation(() => ok([]))
    api.post.mockReset()
  })

  async function generate(data) {
    api.post.mockImplementation(() => ok(data))
    renderAt(<Reports />, { path: '/reports' })
    await userEvent.click(await screen.findByRole('button', { name: /generate report/i }))
    return screen.findByTestId('report-print-sheet')
  }

  it("prints in CDE's form layout: letterhead, labelled lines, summary and signature lines", async () => {
    const sheet = await generate(preview())

    // In the letterhead, and beneath each of the three signature lines.
    expect(within(sheet).getAllByText('CDE Manpower Services')).toHaveLength(4)
    expect(within(sheet).getByText('Sitio Bigayan, Brgy. Calios, Santa Cruz, Laguna')).toBeInTheDocument()
    expect(within(sheet).getByText('APPLICANT REPORT')).toBeInTheDocument()
    for (const label of ['Report:', 'Period:', 'Number of Records:', 'Date generated:', 'Prepared by:', 'Reviewed by:', 'Approved by:']) {
      expect(within(sheet).getAllByText(label).length).toBeGreaterThan(0)
    }
    expect(within(sheet).getAllByText('Maria Santos').length).toBeGreaterThan(0)
    expect(within(sheet).getByText('Total applicants')).toBeInTheDocument()
    expect(within(sheet).getByRole('columnheader', { name: 'Code' })).toBeInTheDocument()
    expect(within(sheet).getByText('Jeffrey Policarpio')).toBeInTheDocument()

    // The screen itself is kept off the paper.
    expect(screen.getByRole('button', { name: /generate report/i }).closest('.no-print')).not.toBeNull()
  })

  it('says when the printout holds only part of the report', async () => {
    const sheet = await generate(preview({ total_rows: 250, truncated: true }))

    expect(within(sheet).getByText(/first 1 of 250 records/)).toBeInTheDocument()
  })
})

describe('Reports › preview and export agree', () => {
  beforeEach(() => {
    toast.current = toastDouble()
    auth.current = authWith(['reports.view', 'reports.export'], { full_name: 'Maria Santos' })
    api.getCached.mockImplementation(() =>
      ok({
        types: [
          {
            key: 'applicants',
            label: 'Applicant Report',
            filters: ['current_status'],
            options: { current_status: ['deployed', 'resigned'] },
          },
        ],
        positions: [],
      })
    )
    api.get.mockImplementation(() => ok([]))
    api.post.mockReset()
    api.post.mockImplementation(() => ok(preview()))
  })

  // The shared Select is a Radix select: open it, then pick the option.
  async function choose(name) {
    await userEvent.click(await screen.findByRole('combobox', { name: 'Application status' }))
    await userEvent.click(await screen.findByRole('option', { name }))
  }

  it('says the preview is out of date once a filter changes, and will not print it', async () => {
    renderAt(<Reports />, { path: '/reports' })

    await choose('Deployed')
    await userEvent.click(screen.getByRole('button', { name: /generate report/i }))
    await screen.findByTestId('report-print-sheet')

    expect(api.post).toHaveBeenLastCalledWith('/reports/preview', {
      report_type: 'applicants',
      filters: { current_status: 'deployed' },
    })
    expect(screen.getByRole('button', { name: /print/i })).toBeInTheDocument()
    expect(screen.queryByText(/filters have changed/i)).not.toBeInTheDocument()

    await choose('Resigned')

    expect(screen.getByText(/filters have changed since this report was generated/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /print/i })).not.toBeInTheDocument()
    expect(screen.queryByTestId('report-print-sheet')).not.toBeInTheDocument()

    // Back to what the preview was built from: current again.
    await choose('Deployed')
    expect(screen.queryByText(/filters have changed/i)).not.toBeInTheDocument()
  })
})
