import * as React from 'react'
import { CornerUpLeft, Download, FileInput, Inbox, Loader2, Ban } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { api, post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Field } from '@/components/ui/form'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { EmptyState, ErrorState, LoadingState, SkeletonRows } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { FilterToolbar } from '@/components/FilterToolbar'
import { Pagination } from '@/components/Pagination'
import DtrImportDialog from '@/components/attendance/DtrImportDialog'
import { DATE_PATTERN, DTR_SUBMISSION_STATUS, formatFileSize } from '@/lib/attendance'
import { formatDate, formatDateTime } from '@/lib/utils'

const VIEWS = [
  ['', 'Waiting for HR'],
  ['submitted', 'Submitted to HR'],
  ['under_review', 'Under HR review'],
  ['returned', 'Needs correction'],
  ['rejected', 'Rejected'],
  ['imported', 'Imported'],
  ['all', 'All submissions'],
]

/**
 * DTR files coordinators have handed over, for HR to check and import.
 *
 * Part of the existing DTR workflow, not a second importer: "Check and import"
 * opens the stored file as an ordinary import draft and continues in the
 * existing import dialog - columns, preview, confirmation. The submission is
 * marked imported by the server only once that import has completed.
 * `?submission=` opens one directly, which is where HR's notification leads.
 */
export default function DtrSubmissionInbox({ searchParams, update, onImported }) {
  const companies = useApi('/clients', { per_page: 100 }, { cacheKey: 'attendance-companies' })
  const status = searchParams.get('status') ?? ''
  const company = searchParams.get('client_company_id') ?? ''
  const coordinator = searchParams.get('coordinator_id') ?? ''
  const search = searchParams.get('search') ?? ''
  const date = (key) => (DATE_PATTERN.test(searchParams.get(key) ?? '') ? searchParams.get(key) : '')
  const coversFrom = date('covers_from')
  const coversTo = date('covers_to')
  const sentFrom = date('submitted_from')
  const sentTo = date('submitted_to')
  const page = Number(searchParams.get('page') ?? 1)
  const debouncedSearch = useDebounced(search)
  const linked = /^\d+$/.test(searchParams.get('submission') ?? '') ? Number(searchParams.get('submission')) : null

  const { data, meta, loading, error, refetch } = useApi('/attendance/dtr-submissions', {
    status: status || 'pending',
    client_company_id: company || undefined,
    coordinator_id: coordinator || undefined,
    covers_from: coversFrom || undefined,
    covers_to: coversTo || undefined,
    submitted_from: sentFrom || undefined,
    submitted_to: sentTo || undefined,
    search: debouncedSearch || undefined,
    page,
    per_page: 25,
  })

  const counts = meta?.status_counts ?? {}
  const waiting = (counts.submitted ?? 0) + (counts.under_review ?? 0)
  const countFor = (value) => (value === '' ? waiting : value === 'all' ? null : counts[value])

  return (
    <div className="mt-4 space-y-4">
      <p className="max-w-3xl text-sm text-muted-foreground">
        DTR files the coordinators received from their clients. Open one to check it in the DTR importer: you choose its
        columns, see every row checked, and import it as you would a file you uploaded yourself.
      </p>

      <FilterToolbar
        search={search}
        onSearchChange={(value) => update({ search: value })}
        searchPlaceholder="Search by DTRS code or file name…"
        filters={[
          {
            id: 'status',
            label: 'Waiting for HR',
            value: status,
            onChange: (value) => update({ status: value }),
            options: VIEWS.map(([value, label]) => ({ value, label: countFor(value) ? `${label} (${countFor(value)})` : label })),
          },
          {
            id: 'company',
            label: 'All client companies',
            value: company,
            onChange: (value) => update({ client_company_id: value }),
            options: [
              { value: '', label: 'All client companies' },
              ...(companies.data ?? []).map((c) => ({ value: String(c.id), label: c.company_name })),
            ],
          },
          {
            id: 'coordinator',
            label: 'Any coordinator',
            value: coordinator,
            onChange: (value) => update({ coordinator_id: value }),
            options: [{ value: '', label: 'Any coordinator' }, ...(meta?.coordinators ?? []).map((c) => ({ value: String(c.id), label: c.full_name }))],
          },
        ]}
        resultCount={meta?.total}
        resultLabel="files"
        onClearFilters={() =>
          update({ status: '', client_company_id: '', coordinator_id: '', search: '', covers_from: '', covers_to: '', submitted_from: '', submitted_to: '' })
        }
      />

      <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        <span className="flex flex-wrap items-center gap-2">
          <label htmlFor="covers_from" className="text-muted-foreground">DTR covers</label>
          <Input id="covers_from" type="date" value={coversFrom} onChange={(e) => update({ covers_from: e.target.value })} className="h-9 w-40" />
          <label htmlFor="covers_to" className="text-muted-foreground">to</label>
          <Input id="covers_to" type="date" value={coversTo} min={coversFrom || undefined} onChange={(e) => update({ covers_to: e.target.value })} className="h-9 w-40" />
        </span>
        <span className="flex flex-wrap items-center gap-2">
          <label htmlFor="submitted_from" className="text-muted-foreground">Sent</label>
          <Input id="submitted_from" type="date" value={sentFrom} onChange={(e) => update({ submitted_from: e.target.value })} className="h-9 w-40" />
          <label htmlFor="submitted_to" className="text-muted-foreground">to</label>
          <Input id="submitted_to" type="date" value={sentTo} min={sentFrom || undefined} onChange={(e) => update({ submitted_to: e.target.value })} className="h-9 w-40" />
        </span>
      </div>

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={5} columns={6} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={Inbox}
          title={status ? 'No files in this view' : 'Nothing waiting'}
          description="Files coordinators send from their workspace appear here until HR imports, returns or rejects them."
        />
      ) : (
        <div className="space-y-3">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>File</TableHead>
                  <TableHead>Client and coverage</TableHead>
                  <TableHead>Sent by</TableHead>
                  <TableHead>Source / handover</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-0" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((s) => (
                  <TableRow key={s.id}>
                    <TableCell>
                      <p className="font-mono text-sm font-semibold">{s.submission_code}</p>
                      <p className="max-w-[16rem] truncate text-xs text-muted-foreground" title={s.original_filename}>
                        {s.original_filename} · {formatFileSize(s.file_size_bytes)}
                      </p>
                    </TableCell>
                    <TableCell className="text-sm">
                      {s.company}
                      <span className="block text-xs text-muted-foreground">
                        {formatDate(s.period_from)} – {formatDate(s.period_to)}
                      </span>
                    </TableCell>
                    <TableCell className="text-sm">
                      {s.submitted_by}
                      <span className="block text-xs text-muted-foreground">{formatDateTime(s.submitted_at)}</span>
                    </TableCell>
                    <TableCell className="max-w-xs text-xs text-muted-foreground">
                      <span className="line-clamp-2" title={s.source_note ?? ''}>{s.source_note || '—'}</span>
                    </TableCell>
                    <TableCell>
                      <Badge tone={DTR_SUBMISSION_STATUS[s.status]?.tone}>{s.status_label}</Badge>
                      {s.import_code && <p className="mt-1 text-xs text-muted-foreground">as {s.import_code}</p>}
                    </TableCell>
                    <TableCell className="text-right">
                      <Button variant="ghost" size="sm" onClick={() => update({ submission: String(s.id), page: String(page) })}>
                        {['submitted', 'under_review'].includes(s.status) ? 'Review' : 'Open'}
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <Pagination meta={meta} onPage={(p) => update({ page: String(p) })} />
        </div>
      )}

      <DtrSubmissionDialog
        key={linked ?? 'none'}
        submissionId={linked}
        open={linked !== null}
        onOpenChange={(value) => !value && update({ submission: '', page: String(page) })}
        onChanged={() => {
          refetch()
          onImported?.()
        }}
      />
    </div>
  )
}

export function DtrSubmissionDialog({ submissionId, open, onOpenChange, onChanged }) {
  const toast = useToast()
  const { data, loading, error, refetch } = useApi(submissionId ? `/attendance/dtr-submissions/${submissionId}` : null, undefined, {
    enabled: Boolean(open && submissionId),
  })
  const [deciding, setDeciding] = React.useState(null)
  const [note, setNote] = React.useState('')
  const [noteError, setNoteError] = React.useState(null)
  const [busy, setBusy] = React.useState(null)
  const [importId, setImportId] = React.useState(null)

  React.useEffect(() => {
    if (open) {
      setDeciding(null)
      setNote('')
      setNoteError(null)
    }
  }, [open, submissionId])

  const pending = ['submitted', 'under_review'].includes(data?.status)

  async function openImporter() {
    setBusy('import')
    try {
      const response = await post(`/attendance/dtr-submissions/${submissionId}/import`)
      setImportId(response.data.import_id)
      refetch()
      onChanged?.()
    } catch (err) {
      toast.error('Could not open the file in the importer', err.message)
    } finally {
      setBusy(null)
    }
  }

  async function decide(event) {
    event.preventDefault()
    if (note.trim().length < 5) {
      setNoteError(deciding === 'return' ? 'Say what needs correcting, so the coordinator knows what to send instead.' : 'Say why the file is rejected.')
      return
    }
    setBusy('decide')
    try {
      const response = await post(`/attendance/dtr-submissions/${submissionId}/decision`, { action: deciding, note: note.trim() })
      toast.success(response.message, 'The coordinator has been told.')
      setDeciding(null)
      refetch()
      onChanged?.()
    } catch (err) {
      if (err.isValidation && err.errors?.note) setNoteError(err.errors.note[0])
      else toast.error('Could not save the decision', err.message)
    } finally {
      setBusy(null)
    }
  }

  async function download() {
    setBusy('download')
    try {
      const response = await api.get(`/attendance/dtr-submissions/${submissionId}/file`, { responseType: 'blob' })
      const href = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = href
      link.download = data.original_filename
      link.click()
      URL.revokeObjectURL(href)
    } catch (err) {
      toast.error('Could not download the file', err.message)
    } finally {
      setBusy(null)
    }
  }

  return (
    <>
      <Dialog open={open && importId === null} onOpenChange={(next) => !busy && onOpenChange(next)}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>DTR submission {data?.submission_code ?? ''}</DialogTitle>
            <DialogDescription>Sent by the coordinator as the client gave it. Nothing in it is recorded until you import it.</DialogDescription>
          </DialogHeader>

          {loading ? (
            <LoadingState label="Loading submission…" />
          ) : error ? (
            <ErrorState message={error.message} onRetry={refetch} />
          ) : data ? (
            <div className="space-y-4">
              <div className="flex flex-wrap items-center gap-2">
                <Badge tone={DTR_SUBMISSION_STATUS[data.status]?.tone}>{data.status_label}</Badge>
                {data.import && (
                  <span className="text-sm text-muted-foreground">
                    Imported as <span className="font-mono">{data.import.import_code}</span> by {data.import.completed_by},{' '}
                    {formatDateTime(data.import.completed_at)}: {data.import.imported_rows} day(s) added, {data.import.error_rows} problem row(s).
                  </span>
                )}
              </div>

              <dl className="grid gap-x-6 gap-y-3 rounded-md border px-3 py-2.5 text-sm sm:grid-cols-2">
                {[
                  ['Client company', data.company],
                  ['DTR covers', `${formatDate(data.period_from)} – ${formatDate(data.period_to)}`],
                  ['File', `${data.original_filename} · ${formatFileSize(data.file_size_bytes)} · ${data.total_rows} row(s)`],
                  ['Sent', `${formatDateTime(data.submitted_at)} by ${data.submitted_by}`],
                  ['Source / handover', data.source_note || '—'],
                  ['Corrects', data.resubmission_of ? `${data.resubmission_of.submission_code} (returned: ${data.resubmission_of.review_note})` : null],
                  ['Corrected by', data.resubmission ? `${data.resubmission.submission_code} (${data.resubmission.status_label.toLowerCase()})` : null],
                ]
                  .filter(([, value]) => value)
                  .map(([label, value]) => (
                    <div key={label}>
                      <dt className="text-xs text-muted-foreground">{label}</dt>
                      <dd>{value}</dd>
                    </div>
                  ))}
              </dl>

              {data.review_note && ['returned', 'rejected'].includes(data.status) && (
                <p className="rounded-md border px-3 py-2 text-sm">
                  <span className="text-muted-foreground">
                    {data.status === 'returned' ? 'Returned' : 'Rejected'} by {data.reviewed_by}, {formatDateTime(data.reviewed_at)}:
                  </span>{' '}
                  “{data.review_note}”
                </p>
              )}

              <section className="space-y-2">
                <h3 className="text-sm font-semibold">History</h3>
                <ol className="space-y-2 border-l pl-4">
                  {data.events.map((e) => (
                    <li key={e.id} className="text-sm">
                      <span className="font-medium">{e.label}</span>
                      <span className="text-xs text-muted-foreground"> · {e.by} · {formatDateTime(e.at)}</span>
                      {e.note && <p className="text-xs">{e.note}</p>}
                    </li>
                  ))}
                </ol>
              </section>

              {deciding && (
                <form onSubmit={decide} className="space-y-3 rounded-md border border-warning/40 bg-warning/[0.04] p-3">
                  <Field
                    label={deciding === 'return' ? 'What needs correcting' : 'Why it is rejected'}
                    htmlFor="dtr_submission_note"
                    required
                    error={noteError}
                  >
                    <Textarea
                      rows={3}
                      maxLength={1000}
                      value={note}
                      onChange={(e) => {
                        setNote(e.target.value)
                        setNoteError(null)
                      }}
                      placeholder={deciding === 'return' ? 'e.g. The file covers the wrong week; ask the client for 19–25 October.' : "e.g. This is another client's DTR."}
                    />
                  </Field>
                  <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={() => setDeciding(null)} disabled={busy === 'decide'}>
                      Cancel
                    </Button>
                    <Button type="submit" variant={deciding === 'reject' ? 'destructive' : 'default'} disabled={busy === 'decide'}>
                      {busy === 'decide' && <Loader2 className="h-4 w-4 animate-spin" />}
                      {deciding === 'return' ? 'Return to coordinator' : 'Reject file'}
                    </Button>
                  </div>
                </form>
              )}
            </div>
          ) : null}

          <DialogFooter className="flex-wrap gap-2">
            {data && (
              <Button variant="outline" onClick={download} disabled={Boolean(busy)} className="mr-auto">
                {busy === 'download' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
                Original file
              </Button>
            )}
            {pending && !deciding && (
              <>
                <Button variant="outline" onClick={() => setDeciding('reject')} disabled={Boolean(busy)}>
                  <Ban className="h-4 w-4" />
                  Reject
                </Button>
                <Button variant="outline" onClick={() => setDeciding('return')} disabled={Boolean(busy)}>
                  <CornerUpLeft className="h-4 w-4" />
                  Return
                </Button>
                <Button onClick={openImporter} disabled={Boolean(busy)}>
                  {busy === 'import' ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileInput className="h-4 w-4" />}
                  {data?.draft_import_id ? 'Continue import' : 'Check and import'}
                </Button>
              </>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* The existing importer, continuing from the draft made from this file. */}
      <DtrImportDialog
        open={importId !== null}
        draftId={importId}
        onOpenChange={(value) => {
          if (!value) {
            setImportId(null)
            refetch()
            onChanged?.()
          }
        }}
        onDone={() => {
          refetch()
          onChanged?.()
        }}
      />
    </>
  )
}
