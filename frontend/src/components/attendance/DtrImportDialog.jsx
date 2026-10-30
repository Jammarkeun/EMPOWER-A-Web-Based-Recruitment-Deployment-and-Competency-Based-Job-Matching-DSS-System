import * as React from 'react'
import { AlertTriangle, FileSpreadsheet, Loader2, Upload } from 'lucide-react'
import { post, upload } from '@/lib/api'
import { useApi } from '@/hooks/useApi'
import { useToast } from '@/components/ui/toast'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Field, FormGrid } from '@/components/ui/form'
import { LoadingState } from '@/components/ui/states'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn, formatDate, todayISO } from '@/lib/utils'

const NOT_IN_FILE = 'none'

const ROLES = [
  ['identifier_column', 'Employee identifier', true],
  ['date_column', 'Date', true],
  ['time_in_column', 'Time in', true],
  ['time_out_column', 'Time out', true],
  ['name_column', 'Name (to cross-check)', false],
  ['remarks_column', 'Remarks', false],
]

const ROW_FILTERS = [
  ['problems', 'Problems'],
  ['conflict', 'Differs from file on record'],
  ['new', 'Ready'],
  ['all', 'All rows shown'],
]

/**
 * Brings in a DTR file the client sent: upload, say which column is which,
 * check every row, then import.
 *
 * PROVISIONAL. CDE has not supplied a sample of the client's biometric export,
 * so no layout is assumed: HR maps the columns of each file. Nothing is
 * written until the preview has been seen, and a day already on file is only
 * replaced when HR chooses to and says why.
 */
export default function DtrImportDialog({ open, onOpenChange, draftId, onDone }) {
  const toast = useToast()
  const [importId, setImportId] = React.useState(draftId ?? null)
  const [busy, setBusy] = React.useState(false)
  const [preview, setPreview] = React.useState(null)
  const [mapping, setMapping] = React.useState(null)
  const [rowFilter, setRowFilter] = React.useState('problems')
  const [conflictAction, setConflictAction] = React.useState('skip')
  const [reason, setReason] = React.useState('')
  const [result, setResult] = React.useState(null)

  const details = useApi(importId ? `/attendance/imports/${importId}` : null, undefined, { enabled: Boolean(open && importId) })

  React.useEffect(() => {
    if (open) {
      setImportId(draftId ?? null)
      setPreview(null)
      setMapping(null)
      setResult(null)
      setConflictAction('skip')
      setReason('')
      setRowFilter('problems')
    }
  }, [open, draftId])

  React.useEffect(() => {
    if (details.data?.mapping && !mapping) setMapping(details.data.mapping)
  }, [details.data, mapping])

  async function runPreview() {
    setBusy(true)
    try {
      const body = Object.fromEntries(
        Object.entries(mapping).map(([key, value]) => [key, value === NOT_IN_FILE || value === '' ? null : value])
      )
      const response = await post(`/attendance/imports/${importId}/preview`, body)
      setPreview(response.data)
      setRowFilter(response.data.errors > 0 ? 'problems' : response.data.counts.conflict > 0 ? 'conflict' : 'new')
    } catch (error) {
      toast.error('Could not check the file', error.message)
    } finally {
      setBusy(false)
    }
  }

  async function confirm() {
    setBusy(true)
    try {
      const response = await post(`/attendance/imports/${importId}/confirm`, {
        conflict_action: conflictAction,
        reason: conflictAction === 'replace' ? reason : null,
      })
      setResult(response.data)
      toast.success('DTR imported', response.message)
      onDone?.()
    } catch (error) {
      toast.error('Could not import the file', error.message)
    } finally {
      setBusy(false)
    }
  }

  async function discard() {
    setBusy(true)
    try {
      await post(`/attendance/imports/${importId}/discard`)
      toast.success('Import discarded', 'Nothing from this file was written.')
      onDone?.()
      onOpenChange(false)
    } catch (error) {
      toast.error('Could not discard the import', error.message)
    } finally {
      setBusy(false)
    }
  }

  const draft = details.data
  const step = result ? 'done' : !importId ? 'upload' : 'map'

  return (
    <Dialog open={open} onOpenChange={(value) => !busy && onOpenChange(value)}>
      <DialogContent className="max-h-[92vh] max-w-4xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Import DTR file</DialogTitle>
          <DialogDescription>
            A CSV or Excel file of the client’s DTR, one row per employee per day. Checked row by row before anything
            is saved.
          </DialogDescription>
        </DialogHeader>

        <div className="flex gap-2.5 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-xs">
          <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
          <p>
            <strong>Provisional import.</strong> CDE has not yet supplied a sample of the client’s biometric export, so no
            file layout is assumed — choose which column holds what for each file. Raw punch logs (one row per punch)
            are not supported. This system does not connect to the biometric device.
          </p>
        </div>

        {step === 'upload' && <UploadStep onUploaded={(id) => setImportId(id)} />}

        {step === 'map' && (details.loading || !draft || !mapping) && <LoadingState label="Reading the file…" />}

        {step === 'map' && draft && mapping && (
          <div className="space-y-5">
            <p className="text-sm text-muted-foreground">
              <FileSpreadsheet className="mr-1 inline h-4 w-4" />
              <span className="font-medium text-foreground">{draft.original_filename}</span> · {draft.company} ·{' '}
              {formatDate(draft.period_from)} – {formatDate(draft.period_to)} · {draft.total_rows} row(s) · {draft.import_code}
            </p>

            <MappingStep
              draft={draft}
              mapping={mapping}
              suggested={draft.mapping_is_suggested && !preview}
              onChange={(next) => {
                setMapping(next)
                setPreview(null)
              }}
            />

            {preview && (
              <PreviewStep
                preview={preview}
                rowFilter={rowFilter}
                onRowFilter={setRowFilter}
                conflictAction={conflictAction}
                onConflictAction={setConflictAction}
                reason={reason}
                onReason={setReason}
              />
            )}
          </div>
        )}

        {step === 'done' && (
          <div className="space-y-3 text-sm">
            <p className="font-medium">{result.import_code} imported.</p>
            <div className="flex flex-wrap gap-2">
              <Badge tone="success">{result.imported_rows} day(s) added</Badge>
              {result.replaced_rows > 0 && <Badge tone="warning">{result.replaced_rows} replaced</Badge>}
              <Badge tone="muted">{result.unchanged_rows} already on file</Badge>
              <Badge tone="muted">{result.skipped_rows} skipped</Badge>
              {result.error_rows > 0 && <Badge tone="destructive">{result.error_rows} with problems, not imported</Badge>}
            </div>
            <p className="text-muted-foreground">
              The days this file covers are now compared with each employee’s schedule. Discrepancies appear in the review
              queue; rows with problems were not imported and are listed on the import’s record.
            </p>
          </div>
        )}

        <DialogFooter className="gap-2">
          {step === 'map' && draft?.status === 'draft' && (
            <Button variant="ghost" onClick={discard} disabled={busy} className="mr-auto">
              Discard draft
            </Button>
          )}
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
            {step === 'done' ? 'Close' : 'Cancel'}
          </Button>
          {step === 'map' && mapping && !preview && (
            <Button onClick={runPreview} disabled={busy}>
              {busy && <Loader2 className="h-4 w-4 animate-spin" />}
              Check rows
            </Button>
          )}
          {step === 'map' && preview && (
            <Button
              onClick={confirm}
              disabled={
                busy ||
                (conflictAction === 'replace' && !reason.trim()) ||
                (preview.ready === 0 && !(conflictAction === 'replace' && preview.counts.conflict > 0) && preview.covered_employees === 0)
              }
            >
              {busy && <Loader2 className="h-4 w-4 animate-spin" />}
              Import {preview.ready + (conflictAction === 'replace' ? preview.counts.conflict : 0)} day(s)
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function UploadStep({ onUploaded }) {
  const toast = useToast()
  const companies = useApi('/clients', { per_page: 100 }, { cacheKey: 'attendance-companies' })
  const [form, setForm] = React.useState({ client_company_id: '', period_from: '', period_to: todayISO(), source_note: '' })
  const [file, setFile] = React.useState(null)
  const [errors, setErrors] = React.useState({})
  const [busy, setBusy] = React.useState(false)

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      const body = new FormData()
      Object.entries(form).forEach(([key, value]) => value && body.append(key, value))
      if (file) body.append('file', file)
      const response = await upload('/attendance/imports', body)
      onUploaded(response.data.id)
    } catch (error) {
      if (error.isValidation && Object.keys(error.errors).length) setErrors(error.errors)
      else toast.error('Could not upload the file', error.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      <Field label="Client company" htmlFor="import_client" required error={errors.client_company_id?.[0]} hint="Whose DTR this is. Rows for workers placed elsewhere are refused.">
        <Select id="import_client" value={form.client_company_id} onChange={(e) => setForm({ ...form, client_company_id: e.target.value })}>
          <option value="">Choose a client…</option>
          {(companies.data ?? []).map((c) => (
            <option key={c.id} value={String(c.id)}>
              {c.company_name}
            </option>
          ))}
        </Select>
      </Field>
      <FormGrid>
        <Field label="DTR covers from" htmlFor="import_from" required error={errors.period_from?.[0]}>
          <Input type="date" value={form.period_from} max={todayISO()} onChange={(e) => setForm({ ...form, period_from: e.target.value })} />
        </Field>
        <Field label="to" htmlFor="import_to" required error={errors.period_to?.[0]} hint="At most 62 days per file.">
          <Input type="date" value={form.period_to} max={todayISO()} min={form.period_from} onChange={(e) => setForm({ ...form, period_to: e.target.value })} />
        </Field>
      </FormGrid>
      <Field label="Where it came from" htmlFor="import_note" error={errors.source_note?.[0]}>
        <Input
          value={form.source_note}
          onChange={(e) => setForm({ ...form, source_note: e.target.value })}
          placeholder="e.g. Emailed by the client's HR on 1 October"
        />
      </Field>
      <Field label="File" htmlFor="import_file" required error={errors.file?.[0]} hint="CSV, .xlsx or .xls, up to 5 MB. Kept privately with the import.">
        <Input type="file" accept=".csv,.txt,.xlsx,.xls" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
      </Field>
      <div className="flex justify-end">
        <Button type="submit" disabled={busy || !file || !form.client_company_id || !form.period_from}>
          {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />}
          Upload and choose columns
        </Button>
      </div>
    </form>
  )
}

function MappingStep({ draft, mapping, suggested, onChange }) {
  const columnOptions = draft.columns.map((c) => ({
    value: String(c.index),
    label: `${c.letter}${c.header ? ` — ${c.header}` : ''}`,
  }))
  const set = (key, value) => onChange({ ...mapping, [key]: value })
  const value = (key) => (mapping[key] === null || mapping[key] === undefined ? NOT_IN_FILE : String(mapping[key]))

  return (
    <section className="space-y-4">
      <div className="overflow-x-auto rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-12">Row</TableHead>
              {draft.columns.map((c) => (
                <TableHead key={c.index}>{c.letter}</TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {draft.sample.map((row) => (
              <TableRow key={row.line} className={cn(row.line === draft.sample[0].line && mapping.has_header && 'font-semibold')}>
                <TableCell className="text-xs text-muted-foreground">{row.line}</TableCell>
                {draft.columns.map((c) => (
                  <TableCell key={c.index} className="whitespace-nowrap font-mono text-xs">
                    {row.cells[c.index] ?? ''}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      {suggested && (
        <p className="text-xs text-muted-foreground">
          A first guess from the column headings is filled in below. Check every choice against the rows above.
        </p>
      )}

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {ROLES.map(([key, label, required]) => (
          <Field key={key} label={label} htmlFor={key} required={required}>
            <Select id={key} value={value(key)} onChange={(e) => set(key, e.target.value)}>
              {!required && <option value={NOT_IN_FILE}>Not in this file</option>}
              {required && value(key) === NOT_IN_FILE && <option value={NOT_IN_FILE}>Choose a column…</option>}
              {columnOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </Field>
        ))}
        <Field label="The identifier is the" htmlFor="identifier_type" required>
          <Select id="identifier_type" value={mapping.identifier_type} onChange={(e) => set('identifier_type', e.target.value)}>
            {draft.options.identifier_types.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Dates are written as" htmlFor="date_format" required hint="Check day-first against month-first carefully.">
          <Select id="date_format" value={mapping.date_format} onChange={(e) => set('date_format', e.target.value)}>
            {draft.options.date_formats.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </Field>
        <label className="flex cursor-pointer items-center gap-2 self-end pb-2 text-sm">
          <input
            type="checkbox"
            checked={Boolean(mapping.has_header)}
            onChange={(e) => set('has_header', e.target.checked)}
            className="h-4 w-4 cursor-pointer rounded border-input accent-primary"
          />
          The first row holds column headings
        </label>
      </div>
    </section>
  )
}

function PreviewStep({ preview, rowFilter, onRowFilter, conflictAction, onConflictAction, reason, onReason }) {
  const c = preview.counts
  const skipped = c.unchanged + c.blank_times + c.duplicate_in_file
  const rows = preview.rows.filter((row) =>
    rowFilter === 'all' ? true : rowFilter === 'problems' ? row.is_error : row.status === rowFilter
  )

  return (
    <section className="space-y-4 border-t pt-4">
      <div className="flex flex-wrap gap-2 text-sm">
        <Badge tone="success">{preview.ready} ready to import</Badge>
        {c.conflict > 0 && <Badge tone="warning">{c.conflict} differ from the DTR on file</Badge>}
        <Badge tone="muted">{skipped} skipped (already on file, blank or repeated)</Badge>
        {preview.errors > 0 && <Badge tone="destructive">{preview.errors} with problems — will not be imported</Badge>}
      </div>

      {preview.employees_without_rows.length > 0 && (
        <p className="rounded-lg border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
          {preview.employees_without_rows.length} worker(s) placed at this client have no rows in the file and will stay
          “awaiting DTR”:{' '}
          {preview.employees_without_rows.slice(0, 8).map((e) => e.full_name ?? e.employee_number).join(', ')}
          {preview.employees_without_rows.length > 8 && '…'}
        </p>
      )}

      <div className="flex flex-wrap gap-1.5">
        {ROW_FILTERS.map(([value, label]) => (
          <Button key={value} size="sm" variant={rowFilter === value ? 'default' : 'outline'} onClick={() => onRowFilter(value)}>
            {label}
          </Button>
        ))}
      </div>

      <div className="max-h-72 overflow-auto rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-12">Row</TableHead>
              <TableHead>Employee</TableHead>
              <TableHead>Date</TableHead>
              <TableHead>In</TableHead>
              <TableHead>Out</TableHead>
              <TableHead>Result</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6} className="text-center text-sm text-muted-foreground">
                  No rows in this view.
                </TableCell>
              </TableRow>
            ) : (
              rows.map((row) => (
                <TableRow key={row.line}>
                  <TableCell className="text-xs text-muted-foreground">{row.line}</TableCell>
                  <TableCell className="text-sm">
                    {row.employee_name ?? <span className="font-mono text-xs">{row.identifier || '—'}</span>}
                    {row.name_in_file && row.employee_name && row.status === 'name_mismatch' && (
                      <span className="block text-xs text-muted-foreground">File says: {row.name_in_file}</span>
                    )}
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-sm">{row.work_date ? formatDate(row.work_date) : row.raw.date || '—'}</TableCell>
                  <TableCell className="font-mono text-xs">{row.time_in ?? row.raw.time_in ?? '—'}</TableCell>
                  <TableCell className="font-mono text-xs">
                    {row.time_out ?? row.raw.time_out ?? '—'}
                    {row.time_out_next_day && ' (+1)'}
                  </TableCell>
                  <TableCell>
                    <Badge tone={row.is_error ? 'destructive' : row.status === 'new' ? 'success' : row.status === 'conflict' ? 'warning' : 'muted'}>
                      {row.label}
                    </Badge>
                    {row.on_file && (
                      <span className="block text-xs text-muted-foreground">
                        On file: {row.on_file.time_in ?? '—'}–{row.on_file.time_out ?? '—'}
                      </span>
                    )}
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
      {preview.rows_truncated && <p className="text-xs text-muted-foreground">Only part of a long file is listed here.</p>}

      {c.conflict > 0 && (
        <fieldset className="space-y-2 rounded-lg border p-3 text-sm">
          <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Days that differ from the DTR on file
          </legend>
          <label className="flex cursor-pointer items-start gap-2">
            <input type="radio" name="conflict" checked={conflictAction === 'skip'} onChange={() => onConflictAction('skip')} className="mt-1 accent-primary" />
            <span>Leave the DTR on file as it is (recommended). Check these days with the client first.</span>
          </label>
          <label className="flex cursor-pointer items-start gap-2">
            <input type="radio" name="conflict" checked={conflictAction === 'replace'} onChange={() => onConflictAction('replace')} className="mt-1 accent-primary" />
            <span>Replace them with this file. The earlier times are kept in each day’s history.</span>
          </label>
          {conflictAction === 'replace' && (
            <Field label="Reason for replacing" htmlFor="replace_reason" required>
              <Input value={reason} onChange={(e) => onReason(e.target.value)} placeholder="e.g. The client's export supersedes the hand-copied sheet" />
            </Field>
          )}
        </fieldset>
      )}
    </section>
  )
}
