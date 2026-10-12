import * as React from 'react'
import { FileText, Loader2, CheckCircle2, Ban, ShieldAlert, LogOut } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { get, post, put } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { StatusBadge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { Field, FormGrid } from '@/components/ui/form'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import { formatDate, formatDateTime, humanise } from '@/lib/utils'

/**
 * The way out of employment, carried through on the employee's own record.
 *
 * CDE has two doors - resignation and termination - and each is a process, not
 * a status: a resignation is filed, cleared, and only then completed; a
 * termination is filed for review and an administrator either finalises it or
 * withdraws it. Before this panel, the filing could be made here but nothing
 * after it could: there was no screen to grant clearance, complete a
 * resignation, or finalise a termination, so the last stage of the lifecycle
 * existed only in the API.
 *
 * Every step that ends someone's employment goes through a confirmation that
 * names the person and says what will happen. The server refuses the same
 * steps without that confirmation, so the dialog is a courtesy, not the lock.
 */
export default function SeparationPanel({ employee, resignation, termination, onChanged }) {
  if (!resignation && !termination) return null

  return (
    <div className="mt-4 space-y-4">
      {termination && <TerminationCard employee={employee} termination={termination} onChanged={onChanged} />}
      {resignation && <ResignationCard employee={employee} resignation={resignation} onChanged={onChanged} />}
    </div>
  )
}

async function openDocument(url, toast) {
  try {
    const response = await get(url)
    const opened = window.open(response.data.url, '_blank', 'noopener')
    if (!opened) {
      toast.warning('Your browser blocked the document', 'Allow pop-ups for this site, then try again.')
    }
  } catch (error) {
    toast.error('Could not open the document', error.message)
  }
}

// ------------------------------------------------------------------ termination

function TerminationCard({ employee, termination, onChanged }) {
  const { can } = useAuth()
  const toast = useToast()
  const [confirmOpen, setConfirmOpen] = React.useState(false)
  const [withdrawOpen, setWithdrawOpen] = React.useState(false)

  const mayFinalise = can('separation.finalize_termination')
  const mayWithdraw = can('separation.create') || mayFinalise

  async function finalise() {
    try {
      await post(`/employees/${employee.id}/terminations/${termination.id}/finalise`, { confirm: true })
      toast.success('Termination finalised', `${employee.full_name}'s employment has ended. The record is archived.`)
      setConfirmOpen(false)
      onChanged()
    } catch (error) {
      toast.error('Could not finalise the termination', error.message)
    }
  }

  return (
    <Card className={termination.is_open ? 'border-destructive/40 bg-destructive/[0.03]' : ''}>
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="space-y-1">
            <CardTitle className="flex items-center gap-2">
              <ShieldAlert className="h-4 w-4 text-destructive" />
              Termination
            </CardTitle>
            <CardDescription>
              {termination.is_open
                ? 'Filed for review. Employment continues until an administrator finalises it.'
                : termination.status === 'withdrawn'
                  ? 'Withdrawn after review. The employee was not dismissed.'
                  : 'Finalised. Employment ended on the termination date.'}
            </CardDescription>
          </div>
          <StatusBadge status={termination.status} label={termination.status === 'for_review' ? 'For review' : undefined} />
        </div>
      </CardHeader>

      <CardContent className="space-y-4">
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <Detail label="Reason" value={termination.reason} wide />
          <Detail label="Termination date" value={formatDate(termination.termination_date)} />
          <Detail
            label="Filed"
            value={`${formatDateTime(termination.filed_at)}${termination.filed_by ? ` by ${termination.filed_by}` : ''}`}
          />
          {termination.finalized_at && (
            <Detail
              label="Finalised"
              value={`${formatDateTime(termination.finalized_at)}${termination.finalized_by ? ` by ${termination.finalized_by}` : ''}`}
            />
          )}
          {termination.withdrawn_at && (
            <>
              <Detail
                label="Withdrawn"
                value={`${formatDateTime(termination.withdrawn_at)}${termination.withdrawn_by ? ` by ${termination.withdrawn_by}` : ''}`}
              />
              <Detail label="Why it was withdrawn" value={termination.withdrawal_reason} wide />
            </>
          )}
          {termination.remarks && <Detail label="Remarks" value={termination.remarks} wide />}
        </dl>

        <div className="flex flex-wrap items-center gap-2">
          {termination.has_documents && (
            <Button
              variant="outline"
              size="sm"
              onClick={() =>
                openDocument(`/employees/${employee.id}/terminations/${termination.id}/document`, toast)
              }
            >
              <FileText className="h-3.5 w-3.5" />
              Supporting documents
            </Button>
          )}

          {termination.is_open && mayWithdraw && (
            <Button variant="outline" size="sm" onClick={() => setWithdrawOpen(true)}>
              <Ban className="h-3.5 w-3.5" />
              Withdraw
            </Button>
          )}

          {termination.is_open && mayFinalise && (
            <Button variant="destructive" size="sm" onClick={() => setConfirmOpen(true)}>
              Finalise termination…
            </Button>
          )}

          {termination.is_open && !mayFinalise && (
            <p className="text-xs text-muted-foreground">
              Awaiting an administrator to finalise or withdraw it.
            </p>
          )}
        </div>
      </CardContent>

      <ConfirmActionDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title="Terminate this employee?"
        description="Finalising ends this person's employment. It cannot be undone from the system."
        details={[
          ['Employee', employee.full_name],
          ['Employee no.', employee.employee_number],
          ['Placed with', employee.current_company],
          ['Reason', termination.reason],
          ['Termination date', formatDate(termination.termination_date)],
          ['Filed by', termination.filed_by],
        ]}
        consequences={[
          'The employee is marked terminated, and so is their applicant record.',
          `Their placement${employee.current_company ? ` with ${employee.current_company}` : ''} is closed as of the termination date.`,
          'A snapshot of their full record is archived. Nothing is deleted.',
          'The employee is notified that their record is closed.',
        ]}
        confirmPhrase={employee.employee_number}
        confirmPhraseLabel="Type the employee number"
        confirmLabel="Confirm termination"
        onConfirm={finalise}
      />

      <WithdrawTerminationDialog
        open={withdrawOpen}
        onOpenChange={setWithdrawOpen}
        employee={employee}
        termination={termination}
        onDone={onChanged}
      />
    </Card>
  )
}

function WithdrawTerminationDialog({ open, onOpenChange, employee, termination, onDone }) {
  const toast = useToast()
  const [reason, setReason] = React.useState('')
  const [error, setError] = React.useState('')
  const [busy, setBusy] = React.useState(false)

  React.useEffect(() => {
    if (open) {
      setReason('')
      setError('')
    }
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()

    if (!reason.trim()) {
      setError('Say why it is being withdrawn. A dropped case with no reason looks like a buried one.')
      return
    }

    setBusy(true)
    try {
      await post(`/employees/${employee.id}/terminations/${termination.id}/withdraw`, { reason })
      toast.success('Termination withdrawn', `${employee.full_name} remains an active employee.`)
      onOpenChange(false)
      onDone()
    } catch (err) {
      setError(err.errors?.reason?.[0] ?? err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !busy && onOpenChange(next)}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Withdraw this termination?</DialogTitle>
          <DialogDescription>
            The employee stays active. The termination stays on file, marked withdrawn, with your reason.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Field label="Reason for withdrawing" htmlFor="withdraw_reason" required error={error}>
            <Textarea value={reason} onChange={(e) => setReason(e.target.value)} />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
              Cancel
            </Button>
            <Button type="submit" disabled={busy}>
              {busy && <Loader2 className="h-4 w-4 animate-spin" />}
              Withdraw termination
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

// ------------------------------------------------------------------ resignation

function ResignationCard({ employee, resignation, onChanged }) {
  const { can } = useAuth()
  const toast = useToast()
  const [confirmOpen, setConfirmOpen] = React.useState(false)
  const [withdrawOpen, setWithdrawOpen] = React.useState(false)
  const [form, setForm] = React.useState(() => fromResignation(resignation))
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(false)

  React.useEffect(() => {
    setForm(fromResignation(resignation))
    setErrors({})
  }, [resignation])

  const mayUpdate = can('separation.create') && resignation.is_open
  const mayComplete = can('separation.approve') && resignation.is_open
  const dirty = JSON.stringify(form) !== JSON.stringify(fromResignation(resignation))

  async function save(patch = form) {
    setSaving(true)
    setErrors({})
    try {
      await put(`/employees/${employee.id}/resignations/${resignation.id}`, {
        ...patch,
        exit_date: patch.exit_date || null,
      })
      toast.success('Resignation updated')
      onChanged()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not update the resignation', error.message)
    } finally {
      setSaving(false)
    }
  }

  async function complete() {
    try {
      await post(`/employees/${employee.id}/resignations/${resignation.id}/complete`, { confirm: true })
      toast.success('Resignation completed', `${employee.full_name}'s employment has ended. The record is archived.`)
      setConfirmOpen(false)
      onChanged()
    } catch (error) {
      toast.error('Could not complete the resignation', error.message)
    }
  }

  return (
    <Card className={resignation.is_open ? 'border-warning/40 bg-warning/[0.04]' : ''}>
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="space-y-1">
            <CardTitle className="flex items-center gap-2">
              <LogOut className="h-4 w-4 text-warning" />
              Resignation
            </CardTitle>
            <CardDescription>
              {resignation.is_open
                ? 'Employment continues while the notice period is rendered and clearance is completed.'
                : resignation.status === 'withdrawn'
                  ? 'Withdrawn. The employee stayed on.'
                  : 'Completed. Employment ended on the exit date.'}
            </CardDescription>
          </div>
          <StatusBadge status={resignation.status} />
        </div>
      </CardHeader>

      <CardContent className="space-y-4">
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
          <Detail label="Reason" value={resignation.reason} wide />
          <Detail label="Filed" value={formatDate(resignation.filing_date)} />
          <Detail
            label="Notice to render"
            value={resignation.rendering_days !== null ? `${resignation.rendering_days} days` : 'Not stated'}
          />
          {!mayUpdate && (
            <>
              <Detail label="Exit date" value={formatDate(resignation.exit_date, 'Not set')} />
              <Detail label="Clearance" value={humanise(resignation.clearance_status)} />
            </>
          )}
          {resignation.processed_by && <Detail label="Processed by" value={resignation.processed_by} />}
        </dl>

        {mayUpdate && (
          <div className="rounded-md border bg-card p-3">
            <FormGrid className="sm:grid-cols-3">
              <Field label="Clearance" htmlFor="clearance_status" error={errors.clearance_status?.[0]}>
                <Select
                  value={form.clearance_status}
                  onChange={(e) => setForm({ ...form, clearance_status: e.target.value })}
                >
                  <option value="pending">Pending</option>
                  <option value="in_progress">In progress</option>
                  <option value="cleared">Cleared</option>
                </Select>
              </Field>
              <Field label="Exit date" htmlFor="exit_date" error={errors.exit_date?.[0]}>
                <Input
                  type="date"
                  value={form.exit_date}
                  min={resignation.filing_date}
                  onChange={(e) => setForm({ ...form, exit_date: e.target.value })}
                />
              </Field>
              <Field label="Status" htmlFor="resignation_status" error={errors.status?.[0]}>
                <Select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                  <option value="filed">Filed</option>
                  <option value="accepted">Accepted</option>
                </Select>
              </Field>
            </FormGrid>
            <div className="mt-3 flex flex-wrap justify-end gap-2">
              <Button variant="ghost" size="sm" onClick={() => setWithdrawOpen(true)} disabled={saving}>
                <Ban className="h-3.5 w-3.5" />
                Withdraw resignation
              </Button>
              <Button variant="outline" size="sm" onClick={() => save()} disabled={!dirty || saving}>
                {saving && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
                Save changes
              </Button>
            </div>
          </div>
        )}

        <div className="flex flex-wrap items-center gap-2">
          {resignation.has_letter && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => openDocument(`/employees/${employee.id}/resignations/${resignation.id}/letter`, toast)}
            >
              <FileText className="h-3.5 w-3.5" />
              Resignation letter
            </Button>
          )}

          {mayComplete && (
            <>
              <Button
                size="sm"
                onClick={() => setConfirmOpen(true)}
                disabled={!resignation.can_be_completed || dirty}
              >
                <CheckCircle2 className="h-3.5 w-3.5" />
                Complete resignation…
              </Button>
              {!resignation.can_be_completed && (
                <p className="text-xs text-muted-foreground">
                  Needs clearance marked “Cleared” and an exit date before it can be completed.
                </p>
              )}
              {resignation.can_be_completed && dirty && (
                <p className="text-xs text-muted-foreground">Save your changes first.</p>
              )}
            </>
          )}
        </div>
      </CardContent>

      <ConfirmActionDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title="Complete this resignation?"
        description="Completing it ends this person's employment. It cannot be undone from the system."
        details={[
          ['Employee', employee.full_name],
          ['Employee no.', employee.employee_number],
          ['Exit date', formatDate(resignation.exit_date)],
          ['Clearance', humanise(resignation.clearance_status)],
        ]}
        consequences={[
          'The employee is marked resigned, and so is their applicant record.',
          'Their placement is closed as of the exit date.',
          'A snapshot of their full record is archived. Nothing is deleted.',
        ]}
        confirmLabel="Complete resignation"
        onConfirm={complete}
      />

      <ConfirmActionDialog
        open={withdrawOpen}
        onOpenChange={setWithdrawOpen}
        tone="warning"
        title="Withdraw this resignation?"
        description="The employee stays on. The resignation stays on file, marked withdrawn."
        details={[
          ['Employee', employee.full_name],
          ['Filed', formatDate(resignation.filing_date)],
        ]}
        confirmLabel="Withdraw resignation"
        onConfirm={async () => {
          await save({ ...form, status: 'withdrawn' })
          setWithdrawOpen(false)
        }}
      />
    </Card>
  )
}

function fromResignation(resignation) {
  return {
    clearance_status: resignation.clearance_status ?? 'pending',
    exit_date: resignation.exit_date ?? '',
    status: resignation.status === 'accepted' ? 'accepted' : 'filed',
  }
}

function Detail({ label, value, wide = false }) {
  if (!value) return null

  return (
    <div className={wide ? 'sm:col-span-full' : undefined}>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="text-sm">{value}</dd>
    </div>
  )
}
