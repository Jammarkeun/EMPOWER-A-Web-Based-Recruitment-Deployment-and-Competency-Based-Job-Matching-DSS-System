import * as React from 'react'
import { FileText, Loader2, CheckCircle2, Ban, ShieldAlert, LogOut, History } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { get, post, put } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
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
 *
 * What the employee sees is kept apart from what the office writes for
 * itself: the "message to the employee" fields are shown in their portal; the
 * reason, remarks and closing reasons are not.
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

const CLEARANCE_OPTIONS = [
  ['pending', 'Pending'],
  ['in_progress', 'In progress'],
  ['cleared', 'Cleared'],
]

// ------------------------------------------------------------------ termination

function TerminationCard({ employee, termination, onChanged }) {
  const { can } = useAuth()
  const toast = useToast()
  const [confirmOpen, setConfirmOpen] = React.useState(false)
  const [withdrawOpen, setWithdrawOpen] = React.useState(false)
  const [message, setMessage] = React.useState(termination.employee_message ?? '')
  const [clearance, setClearance] = React.useState(termination.clearance_status ?? 'pending')
  const [savingClearance, setSavingClearance] = React.useState(false)

  React.useEffect(() => {
    setMessage(termination.employee_message ?? '')
    setClearance(termination.clearance_status ?? 'pending')
  }, [termination])

  const mayFinalise = can('separation.finalize_termination')
  const mayWithdraw = can('separation.create') || mayFinalise
  const mayClear = can('separation.create') && termination.status === 'finalized'

  async function finalise() {
    try {
      await post(`/employees/${employee.id}/terminations/${termination.id}/finalise`, {
        confirm: true,
        employee_message: message.trim() || undefined,
      })
      toast.success('Termination finalised', `${employee.full_name}'s employment has ended. A notice was sent to their portal.`)
      setConfirmOpen(false)
      onChanged()
    } catch (error) {
      toast.error('Could not finalise the termination', error.message)
    }
  }

  async function saveClearance() {
    setSavingClearance(true)
    try {
      await put(`/employees/${employee.id}/terminations/${termination.id}/clearance`, { clearance_status: clearance })
      toast.success('Clearance updated', 'The employee can see it in their portal.')
      onChanged()
    } catch (error) {
      toast.error('Could not update the clearance', error.message)
    } finally {
      setSavingClearance(false)
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
                ? 'Filed for review. Employment continues, and the employee is not told, until an administrator finalises it.'
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
          <Detail label="Reason (internal)" value={termination.reason} wide />
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
          {termination.remarks && <Detail label="Remarks (internal)" value={termination.remarks} wide />}
          {termination.status === 'finalized' && (
            <Detail label="Message in the employee's notice" value={termination.employee_message ?? 'None written'} wide />
          )}
        </dl>

        {termination.notice && <NoticeStatus notice={termination.notice} />}

        {mayClear && (
          <div className="flex flex-wrap items-end gap-2 rounded-md border bg-card p-3">
            <Field label="Clearance" htmlFor={`termination_clearance_${termination.id}`} className="w-48">
              <Select value={clearance} onChange={(e) => setClearance(e.target.value)}>
                {CLEARANCE_OPTIONS.map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </Select>
            </Field>
            <Button
              variant="outline"
              size="sm"
              onClick={saveClearance}
              disabled={savingClearance || clearance === (termination.clearance_status ?? 'pending')}
            >
              {savingClearance && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
              Save clearance
            </Button>
          </div>
        )}
        {!mayClear && termination.clearance_status && (
          <p className="text-sm">
            <span className="text-muted-foreground">Clearance: </span>
            {humanise(termination.clearance_status)}
          </p>
        )}

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

        <CaseHistory history={termination.history} />
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
          'The employee is sent a notice in their portal with the effective date and the message below. The reason and remarks stay internal.',
        ]}
        confirmPhrase={employee.employee_number}
        confirmPhraseLabel="Type the employee number"
        confirmLabel="Confirm termination"
        onConfirm={finalise}
      >
        <Field
          label="Message to the employee"
          htmlFor="termination_employee_message"
          hint="Shown in their portal notice. Optional; without it, the notice asks them to contact the HR office."
        >
          <Textarea value={message} maxLength={2000} onChange={(e) => setMessage(e.target.value)} />
        </Field>
      </ConfirmActionDialog>

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

const NOTIFICATION_STATES = {
  not_sent: 'Not sent (finalised before portal notices existed)',
  not_delivered: 'Not delivered: the employee has turned these notifications off. The notice is still on their portal.',
  unread: 'Delivered, not yet read',
  read: 'Read',
}

/**
 * Four separate facts, never merged into one "seen" tick: the notification
 * was created, it was read, the notice was opened, and the employee confirmed
 * receiving it. None of them is agreement, and nothing waits on any of them.
 */
function NoticeStatus({ notice }) {
  return (
    <div className="rounded-md border bg-muted/20 p-3">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Employee notice</p>
      <dl className="mt-2 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        <Detail label="Issued" value={formatDateTime(notice.issued_at)} />
        <Detail
          label="Notification"
          value={
            notice.notification === 'read'
              ? `Read ${formatDateTime(notice.notification_read_at)}`
              : NOTIFICATION_STATES[notice.notification]
          }
        />
        <Detail label="Opened in the portal" value={notice.viewed_at ? formatDateTime(notice.viewed_at) : 'Not yet'} />
        <Detail
          label="Receipt confirmed"
          value={notice.acknowledged_at ? formatDateTime(notice.acknowledged_at) : 'Not yet'}
        />
      </dl>
      {!notice.has_portal_account && (
        <p className="mt-2 text-xs text-warning">
          This employee has no portal account, so the notice cannot be read online. Give it to them in person.
        </p>
      )}
    </div>
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
            The employee stays active and is not notified. The termination stays on file, marked withdrawn, with your reason.
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

const RESIGNATION_STATUS_LABELS = {
  filed: 'Under review',
  info_requested: 'More information needed from the employee',
  accepted: 'Accepted',
}

function ResignationCard({ employee, resignation, onChanged }) {
  const { can } = useAuth()
  const toast = useToast()
  const [confirmOpen, setConfirmOpen] = React.useState(false)
  const [withdrawOpen, setWithdrawOpen] = React.useState(false)
  const [withdrawal, setWithdrawal] = React.useState({ reason: '', message: '', error: '' })
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
  const statusOptions = [resignation.status, ...(resignation.allowed_statuses ?? [])].filter(
    (status, index, all) => status !== 'withdrawn' && all.indexOf(status) === index
  )
  const shortNotice =
    resignation.notice_given_days !== null &&
    resignation.notice_given_days !== undefined &&
    resignation.notice_days &&
    resignation.notice_given_days < resignation.notice_days

  async function save(patch = form) {
    setSaving(true)
    setErrors({})
    try {
      await put(`/employees/${employee.id}/resignations/${resignation.id}`, {
        ...patch,
        exit_date: patch.exit_date || null,
      })
      toast.success('Resignation updated', 'Anything the employee should know has been sent to their portal.')
      onChanged()
      return true
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not update the resignation', error.message)
      return false
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

  async function withdraw() {
    if (!withdrawal.reason.trim()) {
      setWithdrawal((current) => ({ ...current, error: 'Say why the request is being closed.' }))
      return
    }

    const saved = await save({
      ...form,
      status: 'withdrawn',
      withdrawal_reason: withdrawal.reason.trim(),
      employee_message: withdrawal.message.trim() || form.employee_message,
    })
    if (saved) setWithdrawOpen(false)
  }

  function changeStatus(status) {
    // Accepting confirms a last working day; start from the one asked for.
    const exitDate = status === 'accepted' && !form.exit_date ? resignation.requested_exit_date ?? '' : form.exit_date
    setForm({ ...form, status, exit_date: exitDate })
  }

  return (
    <Card className={resignation.is_open ? 'border-warning/40 bg-warning/[0.04]' : ''}>
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="space-y-1">
            <CardTitle className="flex items-center gap-2">
              <LogOut className="h-4 w-4 text-warning" />
              Resignation
              <Badge tone={resignation.source === 'portal' ? 'info' : 'muted'}>
                {resignation.source === 'portal' ? 'Requested in the portal' : 'Filed at the office'}
              </Badge>
            </CardTitle>
            <CardDescription>
              {resignation.is_open
                ? 'Employment continues while the notice period is rendered and clearance is completed.'
                : resignation.status === 'withdrawn'
                  ? 'Withdrawn. The employee stayed on.'
                  : 'Completed. Employment ended on the exit date.'}
            </CardDescription>
          </div>
          <StatusBadge status={resignation.status} label={RESIGNATION_STATUS_LABELS[resignation.status]} />
        </div>
      </CardHeader>

      <CardContent className="space-y-4">
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
          <Detail label="Reason" value={resignation.reason} wide />
          <Detail label="Filed" value={formatDate(resignation.filing_date)} />
          <Detail label="Last day requested" value={formatDate(resignation.requested_exit_date, 'Not stated')} />
          <Detail
            label="Notice to render"
            value={resignation.rendering_days !== null ? `${resignation.rendering_days} days` : 'Not stated'}
          />
          {!mayUpdate && (
            <>
              <Detail label="Exit date" value={formatDate(resignation.exit_date, 'Not set')} />
              <Detail label="Clearance" value={humanise(resignation.clearance_status)} />
              {resignation.employee_message && (
                <Detail label="Message to the employee" value={resignation.employee_message} wide />
              )}
            </>
          )}
          {resignation.employee_remarks && (
            <Detail label="Employee's remarks" value={resignation.employee_remarks} wide />
          )}
          {resignation.withdrawn_at && (
            <>
              <Detail
                label="Closed"
                value={`${formatDateTime(resignation.withdrawn_at)}${resignation.withdrawn_by ? ` by ${resignation.withdrawn_by}` : ''}`}
              />
              <Detail label="Why (internal)" value={resignation.withdrawal_reason} wide />
            </>
          )}
          {resignation.processed_by && <Detail label="Processed by" value={resignation.processed_by} />}
        </dl>

        {shortNotice && resignation.is_open && (
          <p className="text-xs text-warning">
            The employee asked for {resignation.notice_given_days} days&apos; notice; CDE&apos;s stated period is{' '}
            {resignation.notice_days}. Record the agreed rendering and confirm the last working day with them.
          </p>
        )}

        {mayUpdate && (
          <div className="rounded-md border bg-card p-3">
            <FormGrid className="sm:grid-cols-3">
              <Field label="Status" htmlFor="resignation_status" error={errors.status?.[0]}>
                <Select value={form.status} onChange={(e) => changeStatus(e.target.value)}>
                  {statusOptions.map((status) => (
                    <option key={status} value={status}>
                      {RESIGNATION_STATUS_LABELS[status] ?? humanise(status)}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field
                label="Last working day (confirmed)"
                htmlFor="exit_date"
                error={errors.exit_date?.[0]}
              >
                <Input
                  type="date"
                  value={form.exit_date}
                  min={resignation.filing_date}
                  onChange={(e) => setForm({ ...form, exit_date: e.target.value })}
                />
              </Field>
              <Field label="Clearance" htmlFor="clearance_status" error={errors.clearance_status?.[0]}>
                <Select
                  value={form.clearance_status}
                  onChange={(e) => setForm({ ...form, clearance_status: e.target.value })}
                >
                  {CLEARANCE_OPTIONS.map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </Select>
              </Field>
            </FormGrid>
            <Field
              label="Message to the employee"
              htmlFor="resignation_employee_message"
              className="mt-3"
              required={form.status === 'info_requested'}
              error={errors.employee_message?.[0]}
              hint="Shown in their portal. Required when asking them for more information."
            >
              <Textarea
                value={form.employee_message}
                maxLength={2000}
                onChange={(e) => setForm({ ...form, employee_message: e.target.value })}
              />
            </Field>
            <div className="mt-3 flex flex-wrap justify-end gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  setWithdrawal({ reason: '', message: '', error: '' })
                  setWithdrawOpen(true)
                }}
                disabled={saving}
              >
                <Ban className="h-3.5 w-3.5" />
                Close as withdrawn
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
                  {resignation.status === 'info_requested'
                    ? 'Waiting on the employee. Put it back under review or accept it first.'
                    : 'Needs clearance marked “Cleared” and an exit date before it can be completed.'}
                </p>
              )}
              {resignation.can_be_completed && dirty && (
                <p className="text-xs text-muted-foreground">Save your changes first.</p>
              )}
            </>
          )}
        </div>

        <CaseHistory history={resignation.history} />
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
          'The employee is told in their portal that the resignation is complete.',
        ]}
        confirmLabel="Complete resignation"
        onConfirm={complete}
      />

      <ConfirmActionDialog
        open={withdrawOpen}
        onOpenChange={setWithdrawOpen}
        tone="warning"
        title="Close this resignation as withdrawn?"
        description="The employee stays on. The resignation stays on file, marked withdrawn, with your reason."
        details={[
          ['Employee', employee.full_name],
          ['Filed', formatDate(resignation.filing_date)],
        ]}
        confirmLabel="Close as withdrawn"
        onConfirm={withdraw}
      >
        <div className="space-y-3">
          <Field label="Why (internal)" htmlFor="resignation_withdrawal_reason" required error={withdrawal.error}>
            <Textarea
              value={withdrawal.reason}
              maxLength={255}
              onChange={(e) => setWithdrawal({ ...withdrawal, reason: e.target.value, error: '' })}
            />
          </Field>
          <Field
            label="Message to the employee"
            htmlFor="resignation_withdrawal_message"
            hint="Optional. Shown in their portal."
          >
            <Textarea
              value={withdrawal.message}
              maxLength={2000}
              onChange={(e) => setWithdrawal({ ...withdrawal, message: e.target.value })}
            />
          </Field>
        </div>
      </ConfirmActionDialog>
    </Card>
  )
}

function fromResignation(resignation) {
  return {
    clearance_status: resignation.clearance_status ?? 'pending',
    exit_date: resignation.exit_date ?? '',
    status: resignation.is_open ? resignation.status : 'filed',
    employee_message: resignation.employee_message ?? '',
  }
}

// ---------------------------------------------------------------- history

const EVENT_LABELS = {
  filed: 'Filed',
  info_requested: 'More information requested',
  employee_responded: 'Employee replied',
  accepted: 'Accepted',
  reopened: 'Back under review',
  message: 'Message sent to the employee',
  exit_date_changed: 'Last working day set',
  clearance_changed: 'Clearance changed',
  updated: 'Internal details edited',
  withdrawn: 'Withdrawn',
  completed: 'Completed',
  finalised: 'Finalised; notice issued',
  notice_viewed: 'Employee opened the notice',
  notice_acknowledged: 'Employee confirmed receipt',
}

function eventDetail(entry) {
  const details = entry.details ?? {}
  if (entry.event === 'exit_date_changed') return formatDate(details.to)
  if (entry.event === 'clearance_changed') return `${humanise(details.from ?? 'none')} → ${humanise(details.to)}`
  if (entry.event === 'updated') return (details.fields ?? []).map(humanise).join(', ')
  if (entry.event === 'withdrawn' && details.reason) return details.reason
  return null
}

function CaseHistory({ history }) {
  const [open, setOpen] = React.useState(false)
  if (!history?.length) return null

  return (
    <div className="border-t pt-3">
      <Button variant="ghost" size="sm" className="-ml-2" onClick={() => setOpen((v) => !v)} aria-expanded={open}>
        <History className="h-3.5 w-3.5" />
        {open ? 'Hide history' : `History (${history.length})`}
      </Button>
      {open && (
        <ol className="mt-2 space-y-2">
          {history.map((entry, index) => (
            <li key={index} className="text-sm">
              <div className="flex flex-wrap items-center gap-x-2">
                <span className="font-medium">{EVENT_LABELS[entry.event] ?? humanise(entry.event)}</span>
                {!entry.visible_to_employee && <Badge tone="muted">Internal</Badge>}
              </div>
              {eventDetail(entry) && <p className="text-xs text-muted-foreground">{eventDetail(entry)}</p>}
              {entry.note && (
                <p className="whitespace-pre-line text-xs text-muted-foreground">
                  {entry.by_employee ? 'Employee: ' : 'To the employee: '}
                  {entry.note}
                </p>
              )}
              <p className="text-xs text-muted-foreground">
                {entry.by_employee ? 'Employee' : entry.by ?? 'Staff'} · {formatDateTime(entry.at)}
              </p>
            </li>
          ))}
        </ol>
      )}
    </div>
  )
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
