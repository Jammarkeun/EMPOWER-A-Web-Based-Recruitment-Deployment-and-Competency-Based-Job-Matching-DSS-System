import * as React from 'react'
import { AlertTriangle, Loader2 } from 'lucide-react'
import { post } from '@/lib/api'
import { useApi } from '@/hooks/useApi'
import { useToast } from '@/components/ui/toast'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Field } from '@/components/ui/form'
import { ErrorState, LoadingState } from '@/components/ui/states'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { cn, formatDate, formatDateTime } from '@/lib/utils'

export const DTR_PERIOD_STATUS = {
  received: ['Received', 'info'],
  processing: ['Processing', 'warning'],
  incomplete: ['Incomplete Submission', 'destructive'],
  complete: ['Complete for Period', 'success'],
}

export function DtrPeriodStatusBadge({ status, label }) {
  const [fallback, tone] = DTR_PERIOD_STATUS[status] ?? [status, 'muted']
  return <Badge tone={tone}>{label ?? fallback}</Badge>
}

/** What HR may do next with a period in each status. */
const ACTIONS = {
  received: ['incomplete'],
  processing: ['complete', 'incomplete'],
  incomplete: ['complete', 'reopen'],
  complete: ['reopen'],
}

const ACTION_LABELS = {
  complete: 'Confirm complete for period',
  incomplete: 'Mark as incomplete submission',
  reopen: 'Reopen period',
}

/**
 * One client's DTR period: the files received for it, who was placed there
 * and how much of each one's DTR is on file, and HR's decision on whether the
 * submission is complete.
 *
 * Confirming it complete is what turns a scheduled day with nothing on file
 * from "Awaiting DTR" into "No DTR record" - a gap to verify, never an
 * absence. An import alone never does.
 */
export default function DtrPeriodDialog({ open, onOpenChange, periodId, onDone }) {
  const toast = useToast()
  const { data, loading, error, refetch, setData } = useApi(periodId ? `/attendance/dtr-periods/${periodId}` : null, undefined, {
    enabled: Boolean(open && periodId),
    live: false,
  })
  const [action, setAction] = React.useState(null)
  const [note, setNote] = React.useState('')
  const [acknowledged, setAcknowledged] = React.useState(false)
  const [busy, setBusy] = React.useState(false)
  const [actionError, setActionError] = React.useState(null)

  React.useEffect(() => {
    if (open) {
      setAction(null)
      setNote('')
      setAcknowledged(false)
      setActionError(null)
    }
  }, [open, periodId])

  const totals = data?.reconciliation?.totals
  const gaps = totals?.days_awaiting_dtr ?? 0
  const noteRequired = action === 'incomplete' || action === 'reopen'
  const canSubmit = action && (!noteRequired || note.trim()) && (action !== 'complete' || gaps === 0 || acknowledged)

  async function submit() {
    setBusy(true)
    setActionError(null)
    try {
      const response = await post(`/attendance/dtr-periods/${periodId}/status`, {
        action,
        note: note.trim() || null,
        acknowledge_gaps: action === 'complete' ? acknowledged : undefined,
      })
      setData(response.data)
      toast.success(response.message)
      setAction(null)
      setNote('')
      setAcknowledged(false)
      onDone?.()
    } catch (err) {
      setActionError(err.message)
      toast.error('Could not update the DTR period', err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(value) => !busy && onOpenChange(value)}>
      <DialogContent className="max-h-[92vh] max-w-4xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>DTR period</DialogTitle>
          <DialogDescription>
            Whether the client’s DTR for these days has arrived in full. Days with nothing on file stay “Awaiting DTR”
            until it is confirmed complete; then they are “No DTR record”, for verification — never an absence.
          </DialogDescription>
        </DialogHeader>

        {loading && <LoadingState label="Loading the period…" />}
        {error && <ErrorState message={error.message} onRetry={refetch} />}

        {data && (
          <div className="space-y-5 text-sm">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{data.company}</span>
              <span className="text-muted-foreground">
                {formatDate(data.period_from)} – {formatDate(data.period_to)}
              </span>
              <DtrPeriodStatusBadge status={data.status} label={data.status_label} />
            </div>
            {data.status_by && (
              <p className="-mt-3 text-xs text-muted-foreground">
                {data.status_label} — {data.status_by}, {formatDateTime(data.status_at)}
                {data.status_note && `: “${data.status_note}”`}
              </p>
            )}

            {totals && (
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                <Stat label="Employees placed" value={totals.employees_placed} />
                <Stat label="With rows on file" value={totals.employees_with_records} />
                <Stat label="Days on file" value={totals.records} />
                <Stat label="Awaiting DTR" value={totals.days_awaiting_dtr} tone={gaps > 0 ? 'warning' : null} />
                <Stat label="No DTR record" value={totals.days_no_record} tone={totals.days_no_record > 0 ? 'destructive' : null} />
              </div>
            )}

            <section className="space-y-2">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Employees placed during the period</h3>
              <div className="max-h-60 overflow-auto rounded-lg border">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Employee</TableHead>
                      <TableHead className="text-right">Scheduled days</TableHead>
                      <TableHead className="text-right">On file</TableHead>
                      <TableHead className="text-right">Awaiting DTR</TableHead>
                      <TableHead className="text-right">No DTR record</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {data.reconciliation.employees.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={5} className="text-center text-muted-foreground">
                          Nobody was placed at this client during the period.
                        </TableCell>
                      </TableRow>
                    ) : (
                      data.reconciliation.employees.map((e) => (
                        <TableRow key={e.employee_id}>
                          <TableCell>
                            {e.full_name}
                            <span className="block font-mono text-xs text-muted-foreground">{e.employee_number}</span>
                            {!e.has_schedule && <span className="block text-xs text-warning">No approved schedule — nothing to compare</span>}
                          </TableCell>
                          <TableCell className="text-right tabular-nums">{e.scheduled_days}</TableCell>
                          <TableCell className={cn('text-right tabular-nums', e.records === 0 && 'font-semibold text-warning')}>{e.records}</TableCell>
                          <TableCell className="text-right tabular-nums">{e.days_awaiting_dtr}</TableCell>
                          <TableCell className="text-right tabular-nums">{e.days_no_record}</TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              </div>
            </section>

            <section className="space-y-2">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Files received</h3>
              <ul className="space-y-1 text-xs">
                {data.imports.map((i) => (
                  <li key={i.id} className="flex flex-wrap gap-x-2">
                    <span className="font-mono font-semibold">{i.import_code}</span>
                    <span>{i.original_filename}</span>
                    <span className="text-muted-foreground">
                      {formatDate(i.period_from)} – {formatDate(i.period_to)} · {i.uploaded_by} · {formatDateTime(i.uploaded_at)}
                    </span>
                    <Badge tone={i.status === 'completed' ? 'success' : i.status === 'draft' ? 'warning' : 'muted'}>
                      {i.status === 'completed' ? `Imported, ${i.imported_rows} day(s)` : i.status === 'draft' ? 'Draft — not imported' : 'Discarded'}
                    </Badge>
                  </li>
                ))}
              </ul>
            </section>

            {data.events.length > 0 && (
              <section className="space-y-2">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">History</h3>
                <ol className="space-y-1 text-xs">
                  {data.events.map((e, i) => (
                    <li key={i}>
                      <span className="text-muted-foreground">{formatDateTime(e.at)}</span> · {e.actor} · {e.to_label}
                      {e.note && <span className="text-muted-foreground"> — {e.note}</span>}
                    </li>
                  ))}
                </ol>
              </section>
            )}

            {action && (
              <section className="space-y-3 rounded-lg border p-3">
                <h3 className="font-medium">{ACTION_LABELS[action]}</h3>
                {action === 'complete' && (
                  <p className="text-xs text-muted-foreground">
                    Confirm only when the client has sent everything for these days. Each scheduled day still with nothing
                    on file becomes “No DTR record” and joins the review queue for verification. It is not recorded as an
                    absence.
                  </p>
                )}
                {action === 'reopen' && (
                  <p className="text-xs text-muted-foreground">
                    “No DTR record” days go back to “Awaiting DTR”. Reviews already started on such days must be closed first.
                  </p>
                )}
                <Field
                  label={action === 'incomplete' ? 'What is missing' : action === 'reopen' ? 'Why reopen' : 'Note (optional)'}
                  htmlFor="period_note"
                  required={noteRequired}
                >
                  <Textarea
                    value={note}
                    maxLength={1000}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder={
                      action === 'incomplete'
                        ? 'e.g. Page 2 (Warehouse) not received; coordinator following up with client HR'
                        : action === 'reopen'
                          ? 'e.g. Client sent a corrected file for 18 October'
                          : 'e.g. Client HR confirmed by email that no other pages are coming'
                    }
                  />
                </Field>
                {action === 'complete' && gaps > 0 && (
                  <label className="flex cursor-pointer items-start gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={acknowledged}
                      onChange={(e) => setAcknowledged(e.target.checked)}
                      className="mt-1 h-4 w-4 accent-primary"
                    />
                    <span>
                      I understand that {gaps} scheduled day(s) with nothing on file will become “No DTR record”, for
                      verification.
                    </span>
                  </label>
                )}
                {actionError && (
                  <p role="alert" className="flex gap-2 text-xs text-destructive">
                    <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {actionError}
                  </p>
                )}
                <div className="flex justify-end gap-2">
                  <Button variant="outline" size="sm" onClick={() => setAction(null)} disabled={busy}>
                    Back
                  </Button>
                  <Button size="sm" onClick={submit} disabled={busy || !canSubmit}>
                    {busy && <Loader2 className="h-4 w-4 animate-spin" />}
                    {ACTION_LABELS[action]}
                  </Button>
                </div>
              </section>
            )}
          </div>
        )}

        <DialogFooter className="gap-2">
          {data && !action &&
            (ACTIONS[data.status] ?? []).map((a) => (
              <Button key={a} variant={a === 'complete' ? 'default' : 'outline'} onClick={() => setAction(a)}>
                {ACTION_LABELS[a]}
              </Button>
            ))}
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function Stat({ label, value, tone }) {
  return (
    <div className="rounded-lg border px-3 py-2">
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={cn('text-lg font-semibold tabular-nums', tone === 'warning' && 'text-warning', tone === 'destructive' && 'text-destructive')}>
        {value}
      </p>
    </div>
  )
}
