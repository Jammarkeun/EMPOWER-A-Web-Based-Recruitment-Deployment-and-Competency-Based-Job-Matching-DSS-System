import * as React from 'react'
import { CornerUpLeft, FileSpreadsheet, Loader2, Send, X } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { upload } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Field, FormGrid } from '@/components/ui/form'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { coverageProblems, daysInRange } from '@/components/attendance/DtrImportDialog'
import { formatFileSize } from '@/lib/attendance'
import { cn, formatDate, todayISO } from '@/lib/utils'

/**
 * The coordinator hands the client's DTR file to HR.
 *
 * Two steps: the details, then a review of what will be sent. The file is
 * held to the importer's own limits (from the server), but nothing is
 * imported: HR checks the file and imports it in the Attendance (DTR) module.
 * When `correcting` is given, this is the corrected file for one HR returned;
 * the returned one is kept as it was.
 */
export default function DtrSubmissionForm({ open, onOpenChange, correcting, onSubmitted }) {
  const toast = useToast()
  const options = useApi(open ? '/coordinator/dtr-submissions/options' : null, undefined, { enabled: open, live: false })
  const blank = React.useCallback(
    () => ({
      period_from: correcting?.period_from ?? '',
      period_to: correcting?.period_to ?? todayISO(),
      source_note: '',
    }),
    [correcting]
  )
  const [form, setForm] = React.useState(blank)
  const [file, setFile] = React.useState(null)
  const [step, setStep] = React.useState('details')
  const [touched, setTouched] = React.useState({})
  const [errors, setErrors] = React.useState({})
  const [progress, setProgress] = React.useState(null)
  const [busy, setBusy] = React.useState(false)

  React.useEffect(() => {
    if (open) {
      setForm(blank())
      setFile(null)
      setStep('details')
      setTouched({})
      setErrors({})
    }
  }, [open, blank])

  const limits = options.data ?? {}
  const client = limits.clients?.[0]
  const maxDays = limits.max_period_days ?? 62
  const maxKb = limits.max_file_kb ?? 5120
  const extensions = limits.extensions ?? ['csv', 'txt', 'xlsx', 'xls']
  const coverage = coverageProblems(form, maxDays)
  const days = daysInRange(form.period_from, form.period_to)
  const fileProblem = file
    ? !extensions.includes(file.name.split('.').pop()?.toLowerCase())
      ? 'Only CSV and Excel (.csv, .xlsx, .xls) files can be sent.'
      : file.size > maxKb * 1024
        ? `The file is larger than ${Math.round(maxKb / 1024)} MB. Ask the client to split it by period.`
        : null
    : 'Choose the DTR file to send.'

  const set = (key, value) => {
    setForm((current) => ({ ...current, [key]: value }))
    setTouched((current) => ({ ...current, [key]: true }))
    setErrors((current) => ({ ...current, [key]: undefined }))
  }
  const fieldError = (key) => errors[key]?.[0] ?? (touched[key] ? coverage[key] : undefined)

  function review(event) {
    event.preventDefault()
    setTouched({ period_from: true, period_to: true, file: true })
    if (coverage.period_from || coverage.period_to || fileProblem) return
    setStep('review')
  }

  async function send() {
    setBusy(true)
    setProgress(0)
    try {
      const body = new FormData()
      Object.entries(form).forEach(([key, value]) => value && body.append(key, value))
      body.append('file', file)
      const response = await upload(
        correcting ? `/coordinator/dtr-submissions/${correcting.id}/resubmit` : '/coordinator/dtr-submissions',
        body,
        { onProgress: setProgress }
      )
      toast.success(response.message, 'HR has been notified. Nothing is imported until HR checks the file.')
      onOpenChange(false)
      onSubmitted?.(response.data)
    } catch (error) {
      if (error.isValidation && Object.keys(error.errors ?? {}).length) {
        setErrors(error.errors)
        setStep('details')
      } else toast.error('Could not send the DTR file', error.message)
    } finally {
      setBusy(false)
      setProgress(null)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !busy && onOpenChange(next)}>
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>{correcting ? `Send a corrected file for ${correcting.submission_code}` : 'Submit DTR file to HR'}</DialogTitle>
          <DialogDescription>
            Send the DTR exactly as the client gave it. HR checks every row and imports it; nothing is recorded until then.
          </DialogDescription>
        </DialogHeader>

        {correcting?.review_note && (
          <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2 text-sm">
            <CornerUpLeft className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
            <span>
              HR returned {correcting.submission_code}: <span className="font-medium">{correcting.review_note}</span>
            </span>
          </p>
        )}

        {step === 'details' ? (
          <form id="dtr-submission-form" onSubmit={review} className="space-y-4" noValidate>
            <Field label="Client company" htmlFor="dtr_client" required hint="Your assigned client. DTR files are sent for it only.">
              <Select value={client ? String(client.id) : ''} disabled>
                <option value="">{options.loading ? 'Loading…' : client ? client.company_name : 'No assigned client'}</option>
                {client && <option value={String(client.id)}>{client.company_name}</option>}
              </Select>
            </Field>

            <FormGrid>
              <Field label="DTR covers from" htmlFor="dtr_from" required error={fieldError('period_from')}>
                <Input type="date" value={form.period_from} max={todayISO()} onChange={(e) => set('period_from', e.target.value)} />
              </Field>
              <Field
                label="To"
                htmlFor="dtr_to"
                required
                error={fieldError('period_to')}
                hint={`At most ${maxDays} days per file${days && days > 0 ? ` — this is ${days} day(s)` : ''}.`}
              >
                <Input type="date" value={form.period_to} max={todayISO()} min={form.period_from || undefined} onChange={(e) => set('period_to', e.target.value)} />
              </Field>
            </FormGrid>

            <Field
              label="Source / handover details"
              htmlFor="dtr_note"
              error={errors.source_note?.[0]}
              hint="Who gave you the file, how, and when. You, the time sent and the file name are recorded automatically."
            >
              <Input
                value={form.source_note}
                maxLength={500}
                onChange={(e) => set('source_note', e.target.value)}
                placeholder="e.g. Received from the client's HR by email on November 4, 2026"
              />
            </Field>

            <Field
              label="DTR file"
              htmlFor="dtr_file"
              required
              error={errors.file?.[0] ?? (touched.file && fileProblem ? fileProblem : undefined)}
              hint={`CSV, .xlsx or .xls (first sheet), up to ${Math.round(maxKb / 1024)} MB, one row per employee per day.`}
            >
              {file ? (
                <div className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                  <FileSpreadsheet className="h-4 w-4 text-muted-foreground" />
                  <span className="min-w-0 flex-1 truncate">{file.name}</span>
                  <span className="text-xs text-muted-foreground">{formatFileSize(file.size)}</span>
                  <Button type="button" variant="ghost" size="icon" aria-label="Choose another file" onClick={() => setFile(null)}>
                    <X className="h-4 w-4" />
                  </Button>
                </div>
              ) : (
                <Input
                  type="file"
                  accept=".csv,.txt,.xlsx,.xls"
                  onChange={(e) => {
                    setFile(e.target.files?.[0] ?? null)
                    setTouched((t) => ({ ...t, file: true }))
                    setErrors((current) => ({ ...current, file: undefined }))
                  }}
                />
              )}
            </Field>
          </form>
        ) : (
          <div className="space-y-3">
            <p className="text-sm font-medium">Check before sending</p>
            <dl className="grid gap-x-4 gap-y-2 rounded-md border bg-muted/30 p-3 text-sm sm:grid-cols-[auto_1fr]">
              {[
                ['Client company', client?.company_name],
                ['DTR covers', `${formatDate(form.period_from)} – ${formatDate(form.period_to)} (${days} day(s))`],
                ['File', `${file?.name} · ${formatFileSize(file?.size)}`],
                ['Source', form.source_note || '—'],
                ['Corrects', correcting?.submission_code],
              ]
                .filter(([, value]) => value)
                .map(([label, value]) => (
                  <React.Fragment key={label}>
                    <dt className="text-xs text-muted-foreground sm:pt-0.5">{label}</dt>
                    <dd className="font-medium">{value}</dd>
                  </React.Fragment>
                ))}
            </dl>
            <p className="text-xs text-muted-foreground">
              HR is notified. Nothing is imported until HR has checked every row; you will be told when it is imported or if it
              needs correcting.
            </p>
            {progress !== null && (
              <div className="h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100}>
                <div className="h-full bg-primary transition-all" style={{ width: `${progress}%` }} />
              </div>
            )}
          </div>
        )}

        <DialogFooter>
          {step === 'details' ? (
            <>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" form="dtr-submission-form" disabled={!client}>
                Review
              </Button>
            </>
          ) : (
            <>
              <Button type="button" variant="outline" onClick={() => setStep('details')} disabled={busy}>
                Back
              </Button>
              <Button type="button" onClick={send} disabled={busy} className={cn(busy && 'cursor-wait')}>
                {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                Send to HR
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
