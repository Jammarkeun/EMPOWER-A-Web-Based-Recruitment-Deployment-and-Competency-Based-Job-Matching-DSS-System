import * as React from 'react'
import { AlertTriangle, ArrowLeft, Check, FileSpreadsheet, Info, Loader2, RefreshCw, Upload, X } from 'lucide-react'
import { post, upload } from '@/lib/api'
import { useApi } from '@/hooks/useApi'
import { formatBytes } from '@/lib/uploads'
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
import { DtrPeriodStatusBadge } from '@/components/attendance/DtrPeriodDialog'
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

const STEPS = ['File details', 'Column mapping', 'Preview and validation', 'Import confirmation', 'Import results']

/** Days in an inclusive YYYY-MM-DD range, or null while either end is missing. */
export function daysInRange(from, to) {
  if (!from || !to) return null
  const ms = Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)
  return Number.isNaN(ms) ? null : Math.round(ms / 86_400_000) + 1
}

/**
 * The same checks the server makes on the declared coverage, so HR sees the
 * problem before uploading. The server still decides.
 */
export function coverageProblems({ period_from: from, period_to: to }, maxDays = 62, today = todayISO()) {
  const problems = {}
  if (!from) problems.period_from = 'Enter the first day the DTR covers.'
  else if (from > today) problems.period_from = 'A DTR can only cover days that are already over.'
  if (!to) problems.period_to = 'Enter the last day the DTR covers.'
  else if (to > today) problems.period_to = 'A DTR can only cover days that are already over.'
  else if (from && to < from) problems.period_to = 'The last day cannot be before the first day.'
  else if (from && daysInRange(from, to) > maxDays) {
    problems.period_to = `A DTR file can cover at most ${maxDays} days; this one says ${daysInRange(from, to)}. Split a longer DTR into parts.`
  }
  return problems
}

/** Column choices that cannot work: missing, the same column twice, or one time column for both. */
export function mappingProblems(mapping) {
  const problems = []
  const chosen = (key) => mapping[key] !== null && mapping[key] !== undefined && mapping[key] !== '' && mapping[key] !== NOT_IN_FILE

  const missing = ROLES.filter(([key, , required]) => required && !chosen(key)).map(([, label]) => label)
  if (missing.length) problems.push(`Choose a column for: ${missing.join(', ')}.`)

  if (chosen('time_in_column') && String(mapping.time_in_column) === String(mapping.time_out_column)) {
    problems.push(
      'Time in and time out are the same column. A file with one time per row is a raw punch log (one row per punch), which this import does not support — ask the client for the DTR summary with a time-in and a time-out per day.'
    )
  } else {
    const byColumn = {}
    ROLES.filter(([key]) => chosen(key)).forEach(([key, label]) => {
      byColumn[String(mapping[key])] = [...(byColumn[String(mapping[key])] ?? []), label]
    })
    Object.values(byColumn)
      .filter((labels) => labels.length > 1)
      .forEach((labels) => problems.push(`${labels.join(' and ')} point at the same column. Each column can be used for only one thing.`))
  }

  return problems
}

/**
 * Brings in a DTR file the client sent: file details, say which column is
 * which, check every row, confirm, and see what happened.
 *
 * PROVISIONAL. CDE has not supplied a sample of the client's biometric export,
 * so no layout is assumed: HR maps the columns of each file. Nothing is
 * written until the preview has been seen, and a day already on file is only
 * replaced when HR chooses to and says why. Importing does not say the
 * client's DTR for the period is complete; that is HR's call on the DTR
 * period, afterwards.
 */
export default function DtrImportDialog({ open, onOpenChange, draftId, onDone }) {
  const toast = useToast()
  const [importId, setImportId] = React.useState(draftId ?? null)
  const [busy, setBusy] = React.useState(false)
  const [preview, setPreview] = React.useState(null)
  const [confirming, setConfirming] = React.useState(false)
  const [mapping, setMapping] = React.useState(null)
  const [rowFilter, setRowFilter] = React.useState('problems')
  const [conflictAction, setConflictAction] = React.useState('skip')
  const [reason, setReason] = React.useState('')
  const [result, setResult] = React.useState(null)
  const [actionError, setActionError] = React.useState(null)

  const details = useApi(importId ? `/attendance/imports/${importId}` : null, undefined, { enabled: Boolean(open && importId), live: false })

  React.useEffect(() => {
    if (open) {
      setImportId(draftId ?? null)
      setPreview(null)
      setConfirming(false)
      setMapping(null)
      setResult(null)
      setConflictAction('skip')
      setReason('')
      setRowFilter('problems')
      setActionError(null)
    }
  }, [open, draftId])

  React.useEffect(() => {
    if (details.data?.mapping && !mapping) setMapping(details.data.mapping)
  }, [details.data, mapping])

  async function runPreview() {
    setBusy(true)
    setActionError(null)
    try {
      const body = Object.fromEntries(
        Object.entries(mapping).map(([key, value]) => [key, value === NOT_IN_FILE || value === '' ? null : value])
      )
      const response = await post(`/attendance/imports/${importId}/preview`, body)
      setPreview(response.data)
      setRowFilter(response.data.errors > 0 ? 'problems' : response.data.counts.conflict > 0 ? 'conflict' : 'new')
    } catch (error) {
      setActionError(error.message)
      toast.error('Could not check the file', error.message)
    } finally {
      setBusy(false)
    }
  }

  async function confirm() {
    setBusy(true)
    setActionError(null)
    try {
      const response = await post(`/attendance/imports/${importId}/confirm`, {
        conflict_action: conflictAction,
        reason: conflictAction === 'replace' ? reason : null,
      })
      setResult(response.data)
      toast.success('DTR imported', response.message)
      onDone?.()
    } catch (error) {
      setActionError(error.message)
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
  const step = result ? 4 : !importId ? 0 : !preview ? 1 : confirming ? 3 : 2
  const mapProblems = mapping ? mappingProblems(mapping) : []
  const toImport = preview ? preview.ready + (conflictAction === 'replace' ? preview.counts.conflict : 0) : 0
  const importable =
    preview && (preview.ready > 0 || (conflictAction === 'replace' && preview.counts.conflict > 0) || preview.employees_in_file > 0)

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

        <Stepper current={step} />

        <div className="flex gap-2.5 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-xs">
          <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
          <p>
            <strong>Provisional import.</strong> CDE has not yet supplied a sample of the client’s biometric export, so no
            file layout is assumed — choose which column holds what for each file. Raw punch logs (one row per punch)
            are not supported. This system does not connect to the biometric device.
          </p>
        </div>

        {step === 0 && <UploadStep onUploaded={(id) => setImportId(id)} />}

        {step > 0 && step < 4 && (details.loading || !draft || !mapping) && !details.error && <LoadingState label="Reading the file…" />}
        {step > 0 && step < 4 && details.error && <InlineError message={details.error.message} onRetry={details.refetch} />}

        {step > 0 && step < 4 && draft && mapping && (
          <div className="space-y-5">
            <FileSummary draft={draft} />

            {step === 1 && (
              <MappingStep
                draft={draft}
                mapping={mapping}
                problems={mapProblems}
                suggested={draft.mapping_is_suggested}
                onChange={(next) => {
                  setMapping(next)
                  setPreview(null)
                  setActionError(null)
                }}
              />
            )}

            {step === 2 && (
              <PreviewStep draft={draft} preview={preview} rowFilter={rowFilter} onRowFilter={setRowFilter} />
            )}

            {step === 3 && (
              <ConfirmStep
                draft={draft}
                preview={preview}
                conflictAction={conflictAction}
                onConflictAction={setConflictAction}
                reason={reason}
                onReason={setReason}
                toImport={toImport}
              />
            )}

            {actionError && <InlineError message={actionError} />}
          </div>
        )}

        {step === 4 && <ResultStep result={result} />}

        <DialogFooter className="sticky bottom-0 -mx-6 -mb-6 gap-2 bg-card px-6 pb-6">
          {step > 0 && step < 4 && draft?.status === 'draft' && (
            <Button variant="ghost" onClick={discard} disabled={busy} className="mr-auto">
              Discard draft
            </Button>
          )}
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
            {step === 4 ? 'Close' : 'Cancel'}
          </Button>
          {step === 1 && mapping && (
            <Button onClick={runPreview} disabled={busy || mapProblems.length > 0} title={mapProblems[0]}>
              {busy && <Loader2 className="h-4 w-4 animate-spin" />}
              Check rows
            </Button>
          )}
          {step === 2 && (
            <>
              <Button variant="outline" onClick={() => setPreview(null)} disabled={busy}>
                <ArrowLeft className="h-4 w-4" />
                Change columns
              </Button>
              <Button onClick={() => setConfirming(true)} disabled={busy || !importable}>
                Continue
              </Button>
            </>
          )}
          {step === 3 && (
            <>
              <Button variant="outline" onClick={() => setConfirming(false)} disabled={busy}>
                <ArrowLeft className="h-4 w-4" />
                Back to preview
              </Button>
              <Button onClick={confirm} disabled={busy || !importable || (conflictAction === 'replace' && !reason.trim())}>
                {busy && <Loader2 className="h-4 w-4 animate-spin" />}
                Import {toImport} day(s)
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function Stepper({ current }) {
  return (
    <ol className="flex flex-wrap gap-x-4 gap-y-1.5 text-xs" aria-label="Import steps">
      {STEPS.map((label, i) => (
        <li
          key={label}
          aria-current={i === current ? 'step' : undefined}
          className={cn('flex items-center gap-1.5', i === current ? 'font-semibold text-foreground' : 'text-muted-foreground')}
        >
          <span
            className={cn(
              'flex h-5 w-5 items-center justify-center rounded-full border text-[11px]',
              i < current && 'border-primary bg-primary text-primary-foreground',
              i === current && 'border-primary text-primary'
            )}
          >
            {i < current ? <Check className="h-3 w-3" /> : i + 1}
          </span>
          {label}
        </li>
      ))}
    </ol>
  )
}

function InlineError({ message, onRetry }) {
  return (
    <div role="alert" className="flex items-start gap-2.5 rounded-lg border border-destructive/40 bg-destructive/[0.06] px-3 py-2.5 text-xs">
      <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-destructive" />
      <p className="flex-1">{message}</p>
      {onRetry && (
        <Button type="button" size="sm" variant="outline" onClick={onRetry}>
          <RefreshCw className="h-3.5 w-3.5" />
          Retry
        </Button>
      )}
    </div>
  )
}

function FileSummary({ draft }) {
  return (
    <div className="space-y-1 text-sm text-muted-foreground">
      <p>
        <FileSpreadsheet className="mr-1 inline h-4 w-4" />
        <span className="font-medium text-foreground">{draft.original_filename}</span>
        {draft.file_size_bytes ? ` (${formatBytes(draft.file_size_bytes)})` : ''} · {draft.company} · {formatDate(draft.period_from)} –{' '}
        {formatDate(draft.period_to)} · {draft.total_rows} row(s) · {draft.import_code}
      </p>
      {draft.source_note && <p className="text-xs">Source / handover: {draft.source_note}</p>}
      {draft.dtr_period && (
        <p className="flex flex-wrap items-center gap-1.5 text-xs">
          DTR period {formatDate(draft.dtr_period.period_from)} – {formatDate(draft.dtr_period.period_to)}:
          <DtrPeriodStatusBadge status={draft.dtr_period.status} label={draft.dtr_period.status_label} />
        </p>
      )}
    </div>
  )
}

function UploadStep({ onUploaded }) {
  const toast = useToast()
  const options = useApi('/attendance/imports/options', undefined, { live: false })
  const [form, setForm] = React.useState({ client_company_id: '', period_from: '', period_to: todayISO(), source_note: '' })
  const [file, setFile] = React.useState(null)
  const [touched, setTouched] = React.useState({})
  const [errors, setErrors] = React.useState({})
  const [formError, setFormError] = React.useState(null)
  const [progress, setProgress] = React.useState(null)
  const [busy, setBusy] = React.useState(false)
  const fileInput = React.useRef(null)

  const limits = options.data ?? {}
  const clients = limits.clients ?? []
  const maxDays = limits.max_period_days ?? 62
  const maxKb = limits.max_file_kb ?? 5120
  const extensions = limits.extensions ?? ['csv', 'txt', 'xlsx', 'xls']

  const coverage = coverageProblems(form, maxDays)
  const fileProblem = file
    ? !extensions.includes(file.name.split('.').pop()?.toLowerCase())
      ? 'Only CSV and Excel (.csv, .xlsx, .xls) files can be imported.'
      : file.size > maxKb * 1024
        ? `The file is larger than ${Math.round(maxKb / 1024)} MB. Split it by period and import each part.`
        : null
    : null
  const days = daysInRange(form.period_from, form.period_to)

  const missing = [
    !form.client_company_id && 'a client',
    (coverage.period_from || coverage.period_to) && 'valid dates',
    (!file || fileProblem) && 'a file',
  ].filter(Boolean)

  const set = (key, value) => {
    setForm((current) => ({ ...current, [key]: value }))
    setTouched((current) => ({ ...current, [key]: true }))
    setErrors((current) => ({ ...current, [key]: undefined }))
    setFormError(null)
  }

  const fieldError = (key) => errors[key]?.[0] ?? (touched[key] ? coverage[key] : undefined)

  function chooseFile(next) {
    setFile(next ?? null)
    setTouched((current) => ({ ...current, file: true }))
    setErrors((current) => ({ ...current, file: undefined }))
    setFormError(null)
  }

  async function submit(event) {
    event.preventDefault()
    setTouched({ client_company_id: true, period_from: true, period_to: true, file: true })
    if (missing.length) return

    setBusy(true)
    setErrors({})
    setFormError(null)
    setProgress(0)
    try {
      const body = new FormData()
      Object.entries(form).forEach(([key, value]) => value && body.append(key, value))
      body.append('file', file)
      const response = await upload('/attendance/imports', body, { onProgress: setProgress })
      onUploaded(response.data.id)
    } catch (error) {
      if (error.isValidation && Object.keys(error.errors ?? {}).length) setErrors(error.errors)
      else {
        setFormError(error.message)
        toast.error('Could not upload the file', error.message)
      }
    } finally {
      setBusy(false)
      setProgress(null)
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      <Field
        label="Client company"
        htmlFor="import_client"
        required
        error={errors.client_company_id?.[0] ?? (touched.client_company_id && !form.client_company_id && clients.length ? 'Choose whose DTR this is.' : undefined)}
        hint="Whose DTR this is. Only clients with workers placed there are listed; rows for workers placed elsewhere are refused."
      >
        {options.loading ? (
          <Select disabled value="">
            <option value="">Loading clients…</option>
          </Select>
        ) : options.error ? (
          <InlineError message={`The client list could not be loaded: ${options.error.message}`} onRetry={options.refetch} />
        ) : clients.length === 0 ? (
          <p className="rounded-lg border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
            No client has workers placed with it yet, so there is no DTR to import. Deploy workers to a client first.
          </p>
        ) : (
          <Select value={form.client_company_id} onChange={(e) => set('client_company_id', e.target.value)}>
            <option value="">Choose a client…</option>
            {clients.map((c) => (
              <option key={c.id} value={String(c.id)}>
                {c.company_name}
                {c.status !== 'active' ? ' (inactive)' : ''} — {c.placed_now} placed now
              </option>
            ))}
          </Select>
        )}
      </Field>

      <FormGrid>
        <Field label="DTR covers from" htmlFor="import_from" required error={fieldError('period_from')}>
          <Input type="date" value={form.period_from} max={todayISO()} onChange={(e) => set('period_from', e.target.value)} />
        </Field>
        <Field
          label="To"
          htmlFor="import_to"
          required
          error={fieldError('period_to')}
          hint={`At most ${maxDays} days per file${days && days > 0 ? ` — this is ${days} day(s)` : ''}.`}
        >
          <Input type="date" value={form.period_to} max={todayISO()} min={form.period_from || undefined} onChange={(e) => set('period_to', e.target.value)} />
        </Field>
      </FormGrid>
      <p className="-mt-2 text-xs text-muted-foreground">
        Pick the dates on the calendar; they are saved as year-month-day. The way dates are written <em>inside</em> the
        file is chosen in the next step. Rows dated outside these days are reported and not imported.
      </p>

      <Field
        label="Source / Handover Details"
        htmlFor="import_note"
        error={errors.source_note?.[0]}
        hint="Who sent it, how, and when. You, the upload time, the client, the dates and the file name are recorded automatically."
      >
        <Input
          value={form.source_note}
          maxLength={500}
          onChange={(e) => set('source_note', e.target.value)}
          placeholder="e.g. Received from client HR through the coordinator on November 4, 2026"
        />
      </Field>

      <Field
        label="File"
        htmlFor="import_file"
        required
        error={errors.file?.[0] ?? (touched.file ? fileProblem ?? (!file ? 'Choose the DTR file to upload.' : undefined) : undefined)}
        hint={`CSV, .xlsx or .xls (first sheet), up to ${Math.round(maxKb / 1024)} MB. Kept privately with the import.`}
      >
        <Input
          ref={fileInput}
          type="file"
          accept=".csv,.txt,.xlsx,.xls"
          className={cn(file && 'sr-only')}
          onChange={(e) => chooseFile(e.target.files?.[0])}
        />
      </Field>
      {file && (
        <div className="-mt-2 flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2 text-sm">
          <FileSpreadsheet className="h-4 w-4 text-muted-foreground" />
          <span className="font-medium">{file.name}</span>
          <span className="text-xs text-muted-foreground">{formatBytes(file.size)}</span>
          <span className="ml-auto flex gap-1.5">
            <Button type="button" size="sm" variant="outline" onClick={() => fileInput.current?.click()} disabled={busy}>
              Replace file
            </Button>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              onClick={() => {
                if (fileInput.current) fileInput.current.value = ''
                chooseFile(null)
              }}
              disabled={busy}
              aria-label="Remove the chosen file"
            >
              <X className="h-3.5 w-3.5" />
            </Button>
          </span>
        </div>
      )}

      {formError && <InlineError message={formError} />}

      <div className="flex flex-wrap items-center justify-end gap-3">
        {missing.length > 0 && <p className="text-xs text-muted-foreground">Still needed: {missing.join(', ')}.</p>}
        <Button type="submit" disabled={busy || missing.length > 0}>
          {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />}
          {busy && progress !== null ? `Uploading… ${progress}%` : 'Upload and choose columns'}
        </Button>
      </div>
    </form>
  )
}

function MappingStep({ draft, mapping, problems, suggested, onChange }) {
  const columnOptions = draft.columns.map((c) => ({
    value: String(c.index),
    label: `${c.letter}${c.header ? ` — ${c.header}` : ''}`,
  }))
  const set = (key, value) => onChange({ ...mapping, [key]: value })
  const value = (key) => (mapping[key] === null || mapping[key] === undefined ? NOT_IN_FILE : String(mapping[key]))

  return (
    <section className="space-y-4" aria-label="Column mapping">
      <p className="text-xs text-muted-foreground">
        The first rows of the file, as read. Say which column holds each item below. The file must have one row per
        employee per day, with the time-in and the time-out in separate columns.
      </p>
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
      <p className="text-xs text-muted-foreground">
        Times may be written as 08:05, 8:05 AM or 20:05, or together with the date. A time-out not after the time-in is
        read as the next morning, as on a night shift.
      </p>

      {problems.length > 0 && (
        <ul role="alert" className="space-y-1 rounded-lg border border-destructive/40 bg-destructive/[0.06] px-3 py-2.5 text-xs">
          {problems.map((p) => (
            <li key={p}>{p}</li>
          ))}
        </ul>
      )}
    </section>
  )
}

function Tile({ label, value, tone }) {
  return (
    <div className="rounded-lg border px-3 py-2">
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={cn('text-lg font-semibold tabular-nums', tone === 'destructive' && value > 0 && 'text-destructive', tone === 'success' && 'text-success')}>
        {value}
      </p>
    </div>
  )
}

function PreviewStep({ draft, preview, rowFilter, onRowFilter }) {
  const c = preview.counts
  const s = preview.summary
  const [reasonFilter, setReasonFilter] = React.useState('')
  const rows = preview.rows.filter((row) =>
    rowFilter === 'all'
      ? true
      : rowFilter === 'problems'
        ? row.is_error && (!reasonFilter || row.status === reasonFilter)
        : row.status === rowFilter
  )
  const datesOutside =
    s.dates_in_file && (s.dates_in_file.from < draft.period_from || s.dates_in_file.to > draft.period_to)

  return (
    <section className="space-y-4" aria-label="Preview and validation">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        <Tile label="Rows read" value={s.total_rows} />
        <Tile label="Valid" value={s.valid_rows} tone="success" />
        <Tile label="Invalid" value={s.invalid_rows} tone="destructive" />
        <Tile label="Unmatched employees" value={s.unmatched_employees} tone="destructive" />
        <Tile label="Duplicates" value={s.duplicates} tone="destructive" />
        <Tile label="Outside the period" value={s.outside_period} tone="destructive" />
      </div>

      <div className="flex flex-wrap gap-2 text-sm">
        <Badge tone="success">{preview.ready} ready to import</Badge>
        {c.conflict > 0 && <Badge tone="warning">{c.conflict} differ from the DTR on file</Badge>}
        {c.unchanged > 0 && <Badge tone="muted">{c.unchanged} already on file</Badge>}
        {s.skipped_rows > 0 && <Badge tone="muted">{s.skipped_rows} skipped (blank or repeated)</Badge>}
        {preview.errors > 0 && <Badge tone="destructive">{preview.errors} with problems — will not be imported</Badge>}
      </div>

      {s.punch_log_suspected && (
        <Notice tone="destructive">
          This file looks like a raw punch log — the same employee and day on several rows, each with one time. That
          layout is not supported and nothing in it is turned into days. Ask the client for the DTR summary with one row
          per employee per day.
        </Notice>
      )}

      {datesOutside && (
        <Notice tone="warning">
          The file has dates from {formatDate(s.dates_in_file.from)} to {formatDate(s.dates_in_file.to)}, but this import
          was declared to cover {formatDate(draft.period_from)} – {formatDate(draft.period_to)}. Rows outside the declared
          days are not imported. If the declared days are wrong, discard this draft and upload again with the right dates.
        </Notice>
      )}

      {preview.employees_without_rows.length > 0 && (
        <p className="rounded-lg border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
          {preview.employees_without_rows.length} worker(s) placed at this client have no rows in the file and stay
          “awaiting DTR”:{' '}
          {preview.employees_without_rows.slice(0, 8).map((e) => e.full_name ?? e.employee_number).join(', ')}
          {preview.employees_without_rows.length > 8 && '…'}
        </p>
      )}

      <div className="flex flex-wrap items-center gap-1.5">
        {ROW_FILTERS.map(([value, label]) => (
          <Button key={value} size="sm" variant={rowFilter === value ? 'default' : 'outline'} onClick={() => onRowFilter(value)}>
            {label}
          </Button>
        ))}
        {rowFilter === 'problems' && s.reasons.length > 1 && (
          <Select aria-label="Problem" className="h-8 w-auto text-xs" value={reasonFilter} onChange={(e) => setReasonFilter(e.target.value)}>
            <option value="">Every problem</option>
            {s.reasons.map((r) => (
              <option key={r.status} value={r.status}>
                {r.label} ({r.rows})
              </option>
            ))}
          </Select>
        )}
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
    </section>
  )
}

function Notice({ tone, children }) {
  return (
    <div
      className={cn(
        'flex gap-2.5 rounded-lg border px-3 py-2.5 text-xs',
        tone === 'destructive' ? 'border-destructive/40 bg-destructive/[0.06]' : 'border-warning/40 bg-warning/[0.06]'
      )}
    >
      <Info className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', tone === 'destructive' ? 'text-destructive' : 'text-warning')} />
      <p>{children}</p>
    </div>
  )
}

function ConfirmStep({ draft, preview, conflictAction, onConflictAction, reason, onReason, toImport }) {
  const c = preview.counts
  const notImported = preview.errors + preview.summary.skipped_rows + c.unchanged + (conflictAction === 'replace' ? 0 : c.conflict)

  return (
    <section className="space-y-4" aria-label="Import confirmation">
      <ul className="space-y-1.5 rounded-lg border px-4 py-3 text-sm">
        <li>
          <strong>{toImport}</strong> day(s) will be written to the Attendance (DTR) records of {preview.employees_in_file}{' '}
          employee(s) at {draft.company}.
        </li>
        <li>
          <strong>{notImported}</strong> row(s) will not be imported. Each stays listed, with its row number and reason, on
          this import’s record ({draft.import_code}).
        </li>
        <li>The file is kept, privately, with the import. The same file cannot be imported twice.</li>
        <li>
          Imported days are compared with each employee’s approved schedule. Possible late arrivals, early departures,
          missing punches and times on rest days go to the review queue — they are not findings until HR verifies them.
        </li>
      </ul>

      <Notice tone="warning">
        Importing does not say the client’s DTR for {formatDate(draft.period_from)} – {formatDate(draft.period_to)} is
        complete. Scheduled days with nothing on file stay “Awaiting DTR” until HR confirms the DTR period complete (DTR
        imports → DTR periods). Only then are they “No DTR record”, for verification — never an absence.
      </Notice>

      {c.conflict > 0 && (
        <fieldset className="space-y-2 rounded-lg border p-3 text-sm">
          <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            {c.conflict} day(s) that differ from the DTR on file
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

function ResultStep({ result }) {
  return (
    <div className="space-y-3 text-sm" aria-label="Import results">
      <p className="font-medium">{result.import_code} imported.</p>
      <div className="flex flex-wrap gap-2">
        <Badge tone="success">{result.imported_rows} day(s) added</Badge>
        {result.replaced_rows > 0 && <Badge tone="warning">{result.replaced_rows} replaced</Badge>}
        <Badge tone="muted">{result.unchanged_rows} already on file</Badge>
        <Badge tone="muted">{result.skipped_rows} skipped</Badge>
        {result.error_rows > 0 && <Badge tone="destructive">{result.error_rows} with problems, not imported</Badge>}
      </div>
      <p className="text-muted-foreground">
        The imported days are now compared with each employee’s schedule, on the Attendance (DTR) page and on each
        employee’s Attendance (DTR) tab. Possible discrepancies appear in the review queue; rows with problems were not
        imported and are listed on the import’s record.
      </p>
      {result.dtr_period && (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-xs">
          <span>
            DTR period {formatDate(result.dtr_period.period_from)} – {formatDate(result.dtr_period.period_to)}:
          </span>
          <DtrPeriodStatusBadge status={result.dtr_period.status} label={result.dtr_period.status_label} />
          <span className="text-muted-foreground">
            When every file for these days is in, confirm the period complete on the DTR periods list — or mark it as an
            incomplete submission.
          </span>
        </div>
      )}
    </div>
  )
}
