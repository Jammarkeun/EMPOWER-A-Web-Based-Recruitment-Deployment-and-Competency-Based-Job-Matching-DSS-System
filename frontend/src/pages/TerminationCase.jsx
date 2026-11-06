import * as React from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  AlertTriangle,
  Ban,
  CheckCircle2,
  ClipboardCheck,
  CornerUpLeft,
  FileText,
  Info,
  Loader2,
  Send,
  ShieldAlert,
  XCircle,
} from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { get, post, put, upload } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { Field, FormGrid } from '@/components/ui/form'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { LoadingState, ErrorState } from '@/components/ui/states'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import { CaseHistory, NoticeStatus, WithdrawTerminationDialog } from '@/components/SeparationPanel'
import { formatDate, formatDateTime, humanise } from '@/lib/utils'

/**
 * One termination case, from HR's request to its finalisation.
 *
 * Two stages, each with its own person. HR files the request; an
 * administrator approves it, returns it for correction, or rejects it. An
 * approved request comes back to HR, who completes the pre-termination
 * clearance and only then finalises it. Approval changes nothing about the
 * employee - the screen says so wherever it could be misread.
 *
 * The records HR reads before finalising are kept apart because they mean
 * different things: obligations (which EMPOWER does not hold), the official
 * violation record beside reports that were never validated, and site
 * incidents, which name no employee and are context only. None of them
 * decides anything; only the clearance checklist gates finalisation, and the
 * server checks it again when HR confirms.
 *
 * Which buttons appear is decided by the server for this person and this
 * case (`can`). The server refuses the same actions regardless.
 */
const STEPS = [
  ['for_review', 'Submitted for review'],
  ['approved', 'Approved by an administrator'],
  ['clearance', 'Clearance complete'],
  ['finalized', 'Finalised by HR'],
]

const STATUS_TEXT = {
  draft: 'A draft. Nobody has been told; submit it when it is ready for an administrator.',
  for_review: 'Waiting for an administrator. The employee remains active and has not been told.',
  returned: 'Returned to HR for correction. Correct it and submit it again.',
  approved: 'Approved. Complete the clearance below, then finalise. The employee remains active and has not been told until then.',
  rejected: 'Rejected by an administrator. The employee remains active, and was never told of the request.',
  finalized: 'Finalised. Employment ended on the termination date, and the employee was sent a notice.',
  withdrawn: 'Withdrawn. The employee was not dismissed, and was never told of the request.',
}

export default function TerminationCase() {
  const { id } = useParams()
  const { data: c, loading, error, refetch } = useApi(`/terminations/${id}`)

  if (loading) return <LoadingState label="Loading termination request…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!c) return null

  const base = `/employees/${c.employee.id}/terminations/${c.id}`

  return (
    <div className="space-y-5">
      <PageHeader
        breadcrumbs={[
          { label: 'Separations', to: '/separations?type=termination' },
          { label: c.reference },
        ]}
        title={`Termination request: ${c.employee.full_name}`}
        description={`${c.reference} · ${c.employee.employee_number} · filed by ${c.filed_by ?? 'HR'} ${formatDateTime(c.filed_at)}`}
      />

      <StageSummary termination={c} />

      {c.can.review_blocked && (
        <Notice tone="info" icon={Info}>
          {c.can.review_blocked}
        </Notice>
      )}

      {c.status === 'returned' && c.review.note && (
        <Notice tone="warning" icon={CornerUpLeft} title={`Returned by ${c.review.decided_by ?? 'an administrator'}`}>
          {c.review.note}
        </Notice>
      )}

      {c.status === 'rejected' && c.review.note && (
        <Notice tone="destructive" icon={XCircle} title={`Rejected by ${c.review.decided_by ?? 'an administrator'}`}>
          {c.review.note}
        </Notice>
      )}

      <div className="grid gap-4 lg:grid-cols-5">
        <div className="space-y-4 lg:col-span-3">
          <RequestDetails termination={c} base={base} />
          {c.can.edit && <EditRequest termination={c} base={base} onChanged={refetch} />}
          {c.can.review && <ReviewPanel termination={c} base={base} onChanged={refetch} />}
          {c.clearance.items.length > 0 && (
            <ClearanceChecklist termination={c} base={base} onChanged={refetch} />
          )}
          {c.is_legacy && (
            <Notice tone="info" icon={Info}>
              Finalised under the earlier one-step process, before requests were approved and cleared first. It has no
              separate approval or checklist. Clearance: {humanise(c.clearance.status ?? 'not recorded')}.
            </Notice>
          )}
          {(c.status === 'approved' || c.status === 'finalized') && !c.is_legacy && (
            <FinalReview termination={c} base={base} onChanged={refetch} />
          )}
        </div>

        <div className="space-y-4 lg:col-span-2">
          <ObligationsCard obligations={c.obligations} />
          <ViolationsCard violations={c.violations} employeeId={c.employee.id} />
          <IncidentsCard incidents={c.incidents} />
          {c.notice && (
            <Card>
              <CardContent className="pt-5">
                <NoticeStatus notice={c.notice} />
              </CardContent>
            </Card>
          )}
          <Card>
            <CardContent className="pt-2">
              <CaseHistory history={c.history} defaultOpen />
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}

// ------------------------------------------------------------------- summary

function StageSummary({ termination: c }) {
  const reached = {
    for_review: ['for_review', 'approved', 'finalized'].includes(c.status),
    approved: ['approved', 'finalized'].includes(c.status),
    clearance: c.stage === 'ready_for_finalization' || c.status === 'finalized',
    finalized: c.status === 'finalized',
  }
  const closed = ['rejected', 'withdrawn'].includes(c.status)

  return (
    <Card className={c.is_open ? 'border-destructive/40 bg-destructive/[0.03]' : ''}>
      <CardContent className="space-y-3 pt-5">
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={c.stage} label={c.stage_label} />
          <span className="text-sm text-muted-foreground">
            Employee status: <span className="font-medium text-foreground">{humanise(c.employee.employment_status)}</span>
          </span>
        </div>
        <p className="text-sm">{STATUS_TEXT[c.status]}</p>
        {!closed && !c.is_legacy && (
          <ol className="flex flex-wrap gap-x-5 gap-y-2 text-xs" aria-label="Progress of this request">
            {STEPS.map(([key, label]) => (
              <li
                key={key}
                className={`flex items-center gap-1.5 ${reached[key] ? 'font-medium text-foreground' : 'text-muted-foreground'}`}
              >
                {reached[key] ? (
                  <CheckCircle2 className="h-3.5 w-3.5 text-status-success-text" />
                ) : (
                  <span className="h-3.5 w-3.5 rounded-full border border-border" aria-hidden="true" />
                )}
                {label}
                <span className="sr-only">{reached[key] ? '(done)' : '(not yet)'}</span>
              </li>
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  )
}

function RequestDetails({ termination: c, base }) {
  const toast = useToast()
  const a = c.assignment

  async function openDocument() {
    try {
      const response = await get(`${base}/document`)
      if (!window.open(response.data.url, '_blank', 'noopener')) {
        toast.warning('Your browser blocked the document', 'Allow pop-ups for this site, then try again.')
      }
    } catch (err) {
      toast.error('Could not open the document', err.message)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <ShieldAlert className="h-4 w-4 text-destructive" />
          Employee and request
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <Detail
            label="Employee"
            value={
              <Link to={`/employees/${c.employee.id}`} className="font-medium text-primary hover:underline">
                {c.employee.full_name}
              </Link>
            }
          />
          <Detail label="Employee no." value={c.employee.employee_number} />
          <Detail label="Client company" value={a?.company ?? c.employee.company} />
          <Detail label="Department" value={a?.department ?? c.employee.department} />
          <Detail label="Position" value={a?.position ?? c.employee.position} />
          <Detail
            label="Placement"
            value={a ? `${a.deployment_code} · since ${formatDate(a.deployment_date)} · ${humanise(a.status)}` : 'No placement recorded'}
          />
          <Detail label="Request reference" value={c.reference} />
          <Detail label="Proposed effective date" value={formatDate(c.termination_date)} />
          <Detail label="Reason (internal)" value={c.reason} wide />
          {c.remarks && <Detail label="Remarks (internal)" value={c.remarks} wide />}
          <Detail label="Submitted for review" value={c.submitted_at ? formatDateTime(c.submitted_at) : 'Not yet'} />
          <Detail
            label="Administrator approval"
            value={
              c.review.approved_at
                ? `${c.review.approved_by} · ${formatDateTime(c.review.approved_at)}`
                : c.status === 'for_review'
                  ? 'Awaiting decision'
                  : c.is_legacy
                    ? 'Not recorded separately (one-step process)'
                    : 'Not approved'
            }
          />
          {c.finalized_at && (
            <Detail label="Finalised" value={`${c.finalized_by ?? 'HR'} · ${formatDateTime(c.finalized_at)}`} />
          )}
          {c.withdrawn_at && (
            <>
              <Detail label="Withdrawn" value={`${c.withdrawn_by ?? 'Staff'} · ${formatDateTime(c.withdrawn_at)}`} />
              <Detail label="Why it was withdrawn" value={c.withdrawal_reason} wide />
            </>
          )}
        </dl>
        {c.has_documents && (
          <Button variant="outline" size="sm" onClick={openDocument}>
            <FileText className="h-3.5 w-3.5" />
            Supporting documents
          </Button>
        )}
      </CardContent>
    </Card>
  )
}

// -------------------------------------------------------------- HR's request

function EditRequest({ termination: c, base, onChanged }) {
  const toast = useToast()
  const [form, setForm] = React.useState(() => fromCase(c))
  const [file, setFile] = React.useState(null)
  const [errors, setErrors] = React.useState({})
  const [busy, setBusy] = React.useState(null)
  const [withdrawOpen, setWithdrawOpen] = React.useState(false)
  const dirty = file !== null || JSON.stringify(form) !== JSON.stringify(fromCase(c))

  React.useEffect(() => {
    setForm(fromCase(c))
    setFile(null)
  }, [c])

  async function save() {
    const body = new FormData()
    body.append('reason', form.reason)
    body.append('termination_date', form.termination_date)
    body.append('remarks', form.remarks)
    if (file) body.append('documents', file)
    await upload(base, body)
  }

  async function run(kind) {
    setBusy(kind)
    setErrors({})
    try {
      if (dirty) await save()
      if (kind === 'submit') {
        await post(`${base}/submit`)
        toast.success('Request submitted', 'The administrators have been notified to review it.')
      } else {
        toast.success('Request saved')
      }
      onChanged()
    } catch (err) {
      if (err.isValidation) setErrors(err.errors)
      else toast.error('Could not save the request', err.message)
    } finally {
      setBusy(null)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">{c.status === 'returned' ? 'Correct the request' : 'Draft request'}</CardTitle>
        <CardDescription>
          The administrator sees the request as it stands when you submit it. Nothing reaches the employee.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <FormGrid>
          <Field label="Reason (internal)" htmlFor="tc_reason" required error={errors.reason?.[0]} className="sm:col-span-2">
            <Input
              id="tc_reason"
              value={form.reason}
              maxLength={255}
              onChange={(e) => setForm({ ...form, reason: e.target.value })}
            />
          </Field>
          <Field label="Proposed effective date" htmlFor="tc_date" required error={errors.termination_date?.[0]}>
            <Input
              id="tc_date"
              type="date"
              value={form.termination_date}
              onChange={(e) => setForm({ ...form, termination_date: e.target.value })}
            />
          </Field>
          <Field label="Replace supporting document" htmlFor="tc_documents" error={errors.documents?.[0]}>
            <Input id="tc_documents" type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </Field>
        </FormGrid>
        <Field label="Remarks (internal)" htmlFor="tc_remarks" error={errors.remarks?.[0]}>
          <Textarea
            id="tc_remarks"
            value={form.remarks}
            maxLength={5000}
            onChange={(e) => setForm({ ...form, remarks: e.target.value })}
          />
        </Field>
        <div className="flex flex-wrap justify-end gap-2">
          {c.can.withdraw && (
            <Button variant="ghost" size="sm" onClick={() => setWithdrawOpen(true)} disabled={busy !== null}>
              <Ban className="h-3.5 w-3.5" />
              Withdraw
            </Button>
          )}
          <Button variant="outline" size="sm" onClick={() => run('save')} disabled={!dirty || busy !== null}>
            {busy === 'save' && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
            Save
          </Button>
          {c.can.submit && (
            <Button size="sm" onClick={() => run('submit')} disabled={busy !== null || !form.reason.trim()}>
              {busy === 'submit' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />}
              {c.status === 'returned' ? 'Resubmit for review' : 'Submit for review'}
            </Button>
          )}
        </div>
      </CardContent>
      <WithdrawTerminationDialog
        open={withdrawOpen}
        onOpenChange={setWithdrawOpen}
        employee={c.employee}
        termination={c}
        onDone={onChanged}
      />
    </Card>
  )
}

function fromCase(c) {
  return { reason: c.reason ?? '', termination_date: c.termination_date ?? '', remarks: c.remarks ?? '' }
}

// ----------------------------------------------------- the administrator

const DECISIONS = {
  approve: {
    title: 'Approve this termination request?',
    description:
      'Approval does not terminate the employee. The request goes back to HR, who completes the pre-termination clearance and then finalises it.',
    confirmLabel: 'Approve request',
    tone: 'warning',
    toast: 'HR has been notified to complete the clearance. The employee remains active.',
  },
  return: {
    title: 'Return this request to HR?',
    description: 'HR is shown your note, corrects the request, and submits it again.',
    confirmLabel: 'Return for correction',
    tone: 'warning',
    toast: 'HR has been notified with your note.',
  },
  reject: {
    title: 'Reject this request?',
    description: 'The request is closed. The employee remains active and is not told.',
    confirmLabel: 'Reject request',
    tone: 'destructive',
    toast: 'The employee remains active.',
  },
}

function ReviewPanel({ termination: c, base, onChanged }) {
  const toast = useToast()
  const [decision, setDecision] = React.useState(null)
  const [note, setNote] = React.useState('')
  const [error, setError] = React.useState('')
  const config = decision ? DECISIONS[decision] : null

  async function decide() {
    if (decision !== 'approve' && !note.trim()) {
      setError('Write the reason. HR is shown it with the decision.')
      return
    }
    try {
      const response = await post(`${base}/review`, { decision, note: note.trim() || undefined })
      toast.success(response.message ?? 'Decision recorded', config.toast)
      setDecision(null)
      setNote('')
      onChanged()
    } catch (err) {
      setError(err.errors?.note?.[0] ?? err.message)
    }
  }

  return (
    <Card className="border-warning/40">
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <ClipboardCheck className="h-4 w-4" />
          Your decision
        </CardTitle>
        <CardDescription>
          Read the request and the records beside it. Approving sends it back to HR for clearance; it does not end the
          employment.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-wrap gap-2">
        <Button onClick={() => setDecision('approve')}>
          <CheckCircle2 className="h-4 w-4" />
          Approve…
        </Button>
        <Button variant="outline" onClick={() => setDecision('return')}>
          <CornerUpLeft className="h-4 w-4" />
          Return for correction…
        </Button>
        <Button variant="outline" onClick={() => setDecision('reject')}>
          <XCircle className="h-4 w-4" />
          Reject…
        </Button>
      </CardContent>

      {config && (
        <ConfirmActionDialog
          open
          onOpenChange={(open) => {
            if (!open) {
              setDecision(null)
              setError('')
            }
          }}
          tone={config.tone}
          title={config.title}
          description={config.description}
          details={[
            ['Employee', c.employee.full_name],
            ['Employee no.', c.employee.employee_number],
            ['Reason', c.reason],
            ['Proposed effective date', formatDate(c.termination_date)],
            ['Filed by', c.filed_by],
          ]}
          confirmLabel={config.confirmLabel}
          onConfirm={decide}
        >
          <Field
            label={decision === 'approve' ? 'Note to HR (optional)' : 'Reason (shown to HR)'}
            htmlFor="review_note"
            required={decision !== 'approve'}
            error={error}
          >
            <Textarea
              id="review_note"
              value={note}
              maxLength={1000}
              onChange={(e) => {
                setNote(e.target.value)
                setError('')
              }}
            />
          </Field>
        </ConfirmActionDialog>
      )}
    </Card>
  )
}

// ---------------------------------------------------------------- clearance

function ClearanceChecklist({ termination: c, base, onChanged }) {
  const editable = c.can.update_clearance
  const outstanding = c.clearance.outstanding

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <ClipboardCheck className="h-4 w-4" />
          Pre-termination clearance
        </CardTitle>
        <CardDescription>
          Every mandatory item must be cleared, or marked not applicable with a reason, before the termination can be
          finalised. Recorded for the office only; the employee is not told.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {c.status === 'approved' && (
          <p className={`text-sm ${outstanding.length ? 'text-status-warning-text' : 'text-status-success-text'}`} role="status">
            {outstanding.length
              ? `${outstanding.length} mandatory item${outstanding.length === 1 ? '' : 's'} still to resolve.`
              : 'Every mandatory item is resolved. The request is ready to finalise.'}
          </p>
        )}
        {c.clearance.items.map((item) => (
          <ClearanceItem key={item.id} item={item} editable={editable} endpoint={`${base}/clearance-items/${item.id}`} onChanged={onChanged} />
        ))}
      </CardContent>
    </Card>
  )
}

function ClearanceItem({ item, editable, endpoint, onChanged }) {
  const toast = useToast()
  const initial = React.useMemo(
    () => ({ status: item.status, remarks: item.remarks ?? '' }),
    [item.status, item.remarks]
  )
  const [form, setForm] = React.useState(initial)
  const [error, setError] = React.useState('')
  const [saving, setSaving] = React.useState(false)
  const dirty = JSON.stringify(form) !== JSON.stringify(initial)

  React.useEffect(() => setForm(initial), [initial])

  const label = (status) =>
    item.kind === 'record_review' && status === 'cleared' ? 'Reviewed' : humanise(status)

  async function save() {
    if (form.status === 'not_applicable' && !form.remarks.trim()) {
      setError('Say why this does not apply to the employee.')
      return
    }
    if (form.status === 'cleared' && item.requires_remarks && !form.remarks.trim()) {
      setError('Record what was verified, and with whom. EMPOWER holds no record of its own to show for this.')
      return
    }
    setSaving(true)
    setError('')
    try {
      await put(endpoint, form)
      toast.success('Clearance item updated', item.label)
      onChanged()
    } catch (err) {
      setError(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="rounded-md border bg-card p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="space-y-0.5">
          <p className="text-sm font-medium">
            {item.label}
            {item.is_mandatory && <span className="ml-2 text-xs font-normal text-muted-foreground">Mandatory</span>}
          </p>
          {item.description && <p className="text-xs text-muted-foreground">{item.description}</p>}
        </div>
        <StatusBadge status={item.status} label={item.status_label} />
      </div>

      {editable ? (
        <div className="mt-3 space-y-2">
          <FormGrid className="sm:grid-cols-2">
            <Field label="Outcome" htmlFor={`ci_status_${item.id}`}>
              <Select
                id={`ci_status_${item.id}`}
                value={form.status}
                onChange={(e) => setForm({ ...form, status: e.target.value })}
              >
                {item.allowed_statuses.map((status) => (
                  <option key={status} value={status}>
                    {label(status)}
                  </option>
                ))}
              </Select>
            </Field>
          </FormGrid>
          <Field
            label={item.requires_remarks ? 'What was verified, and with whom' : 'Remarks'}
            htmlFor={`ci_remarks_${item.id}`}
            required={form.status === 'not_applicable' || (form.status === 'cleared' && item.requires_remarks)}
            error={error}
          >
            <Textarea
              id={`ci_remarks_${item.id}`}
              value={form.remarks}
              maxLength={2000}
              onChange={(e) => {
                setForm({ ...form, remarks: e.target.value })
                setError('')
              }}
            />
          </Field>
          <div className="flex justify-end">
            <Button variant="outline" size="sm" onClick={save} disabled={!dirty || saving}>
              {saving && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
              Save item
            </Button>
          </div>
        </div>
      ) : (
        item.remarks && (
          <dl className="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
            <Detail label={item.requires_remarks ? 'What was verified, and with whom' : 'Remarks'} value={item.remarks} wide />
          </dl>
        )
      )}

      {item.verified_at && (
        <p className="mt-2 text-xs text-muted-foreground">
          Verified by {item.verified_by ?? 'staff'} · {formatDateTime(item.verified_at)}
        </p>
      )}
    </div>
  )
}

// ------------------------------------------------------------ finalisation

function FinalReview({ termination: c, base, onChanged }) {
  const toast = useToast()
  const [open, setOpen] = React.useState(false)
  const [message, setMessage] = React.useState(c.employee_message ?? '')
  const [withdrawOpen, setWithdrawOpen] = React.useState(false)

  if (c.status === 'finalized') {
    return (
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Final processing</CardTitle>
        </CardHeader>
        <CardContent className="text-sm">
          <Detail label="Message in the employee's notice" value={c.employee_message ?? 'None written'} wide />
        </CardContent>
      </Card>
    )
  }

  async function finalise() {
    try {
      await post(`${base}/finalise`, { confirm: true, employee_message: message.trim() || undefined })
      toast.success('Termination finalised', `${c.employee.full_name}'s employment has ended. A notice was sent to their portal.`)
      setOpen(false)
      onChanged()
    } catch (err) {
      const items = err.errors?.clearance
      toast.error('Could not finalise the termination', items ? items.join(' ') : err.message)
    }
  }

  const blockers = c.clearance.items.filter((item) => item.is_mandatory && !item.is_resolved)

  return (
    <Card className="border-destructive/40">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Final review</CardTitle>
        <CardDescription>
          What will be recorded when you finalise. The server checks the approval, the clearance and the employee's
          status again at that moment.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <Detail label="Approved by" value={`${c.review.approved_by} · ${formatDateTime(c.review.approved_at)}`} />
          <Detail label="Effective date" value={formatDate(c.termination_date)} />
          <Detail label="Employee" value={`${c.employee.full_name} (${c.employee.employee_number})`} />
          <Detail label="Assignment" value={[c.assignment?.company, c.assignment?.department].filter(Boolean).join(' · ')} />
          <Detail label="Reason (internal)" value={c.reason} wide />
          {c.clearance.items.map((item) => (
            <Detail key={item.key} label={item.label} value={item.status_label} />
          ))}
          <Detail
            label="Violation history"
            value={
              c.violations
                ? `${c.violations.official.length} on record, ${c.violations.unvalidated_reports.length} report(s) not validated`
                : 'Not visible to you'
            }
          />
          <Detail
            label="Incident reports (context only)"
            value={c.incidents ? `${c.incidents.context.length} from the placement` : 'Not visible to you'}
          />
        </dl>

        {blockers.length > 0 && (
          <div className="rounded-md border border-warning/40 bg-warning/[0.06] p-3 text-sm" role="status">
            <p className="flex items-center gap-1.5 font-medium">
              <AlertTriangle className="h-4 w-4 text-status-warning-text" />
              Finalisation is blocked until these are resolved:
            </p>
            <ul className="mt-1 list-disc pl-6">
              {blockers.map((item) => (
                <li key={item.key}>
                  {item.label}: {item.status_label.toLowerCase()}
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="flex flex-wrap items-center gap-2">
          {c.can.withdraw && (
            <Button variant="outline" size="sm" onClick={() => setWithdrawOpen(true)}>
              <Ban className="h-3.5 w-3.5" />
              Withdraw
            </Button>
          )}
          {c.can.update_clearance && (
            <Button variant="destructive" size="sm" onClick={() => setOpen(true)} disabled={!c.can.finalise}>
              Finalise termination…
            </Button>
          )}
          {!c.can.update_clearance && (
            <p className="text-xs text-muted-foreground">HR completes the clearance and finalises this request.</p>
          )}
        </div>
      </CardContent>

      <ConfirmActionDialog
        open={open}
        onOpenChange={setOpen}
        title="Terminate this employee?"
        description="Finalising ends this person's employment. It cannot be undone from the system."
        details={[
          ['Employee', c.employee.full_name],
          ['Employee no.', c.employee.employee_number],
          ['Placed with', c.assignment?.company ?? c.employee.company],
          ['Reason', c.reason],
          ['Effective date', formatDate(c.termination_date)],
          ['Approved by', c.review.approved_by],
          ['Clearance', 'Complete'],
        ]}
        consequences={[
          'The employee is marked terminated, and so is their applicant record.',
          `Their placement${c.assignment?.company ? ` with ${c.assignment.company}` : ''} is closed as of the effective date.`,
          'A snapshot of their full record is archived. Nothing is deleted.',
          'The employee is sent a notice in their portal with the effective date and the message below. The reason, remarks and clearance notes stay internal.',
        ]}
        confirmPhrase={c.employee.employee_number}
        confirmPhraseLabel="Type the employee number"
        confirmLabel="Confirm termination"
        onConfirm={finalise}
      >
        <Field
          label="Message to the employee"
          htmlFor="termination_employee_message"
          hint="Shown in their portal notice. Optional; without it, the notice asks them to contact the HR office."
        >
          <Textarea
            id="termination_employee_message"
            value={message}
            maxLength={2000}
            onChange={(e) => setMessage(e.target.value)}
          />
        </Field>
      </ConfirmActionDialog>

      <WithdrawTerminationDialog
        open={withdrawOpen}
        onOpenChange={setWithdrawOpen}
        employee={c.employee}
        termination={c}
        onDone={onChanged}
      />
    </Card>
  )
}

// ------------------------------------------------------- records to review

function ObligationsCard({ obligations }) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-base">Loans and outstanding obligations</CardTitle>
      </CardHeader>
      <CardContent>
        <p className="flex gap-2 text-sm text-muted-foreground">
          <Info className="mt-0.5 h-4 w-4 shrink-0" />
          {obligations.message}
        </p>
      </CardContent>
    </Card>
  )
}

function ViolationsCard({ violations, employeeId }) {
  const [viewing, setViewing] = React.useState(null)

  if (!violations) return null

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-base">Violation history</CardTitle>
        <CardDescription>
          Read before deciding. Neither the record nor the number of offences terminates anybody; that is the
          agency's decision. {violations.active_count} of {violations.official.length} still count (threshold{' '}
          {violations.threshold}).
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div>
          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Official record (validated by HR)
          </p>
          {violations.official.length === 0 ? (
            <p className="text-sm text-muted-foreground">No violation is on record for this employee.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {violations.official.map((v) => (
                  <TableRow key={v.id} className="cursor-pointer" onClick={() => setViewing(v)}>
                    <TableCell className="whitespace-nowrap text-sm">
                      {formatDate(v.date)}
                      {!v.counts_toward_threshold && <span className="block text-xs text-muted-foreground">Expired</span>}
                    </TableCell>
                    <TableCell className="text-sm">
                      <button
                        type="button"
                        className="font-medium text-primary hover:underline"
                        onClick={(e) => {
                          e.stopPropagation()
                          setViewing(v)
                        }}
                        aria-label={`View details: ${humanise(v.type)}, ${formatDate(v.date)}`}
                      >
                        {humanise(v.type)}
                      </button>
                      <span className="block text-xs text-muted-foreground">
                        {[v.company, v.penalty, v.from_report && `from ${v.from_report}`].filter(Boolean).join(' · ')}
                      </span>
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={v.status} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </div>

        {violations.unvalidated_reports.length > 0 && (
          <div>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Coordinator reports not validated
            </p>
            <p className="mb-2 text-xs text-muted-foreground">Reports, not findings. None is part of the record above.</p>
            <ul className="space-y-1 text-sm">
              {violations.unvalidated_reports.map((r) => (
                <li key={r.id} className="flex flex-wrap items-center gap-2">
                  <Link to={`/violation-reports/${r.id}`} className="font-mono text-xs text-primary hover:underline">
                    {r.report_code}
                  </Link>
                  <span>{humanise(r.type)}</span>
                  <span className="text-xs text-muted-foreground">{formatDate(r.date)}</span>
                  <Badge tone="muted">{r.status_label}</Badge>
                </li>
              ))}
            </ul>
          </div>
        )}
      </CardContent>

      <ViolationDetailDialog violation={viewing} employeeId={employeeId} onClose={() => setViewing(null)} />
    </Card>
  )
}

/**
 * One recorded violation, read-only, as HR reviews the record before
 * finalising. Changing a violation stays on the employee's Discipline tab;
 * this only shows what is on file, so reviewing it cannot alter it.
 */
function ViolationDetailDialog({ violation: v, employeeId, onClose }) {
  const toast = useToast()

  async function openEvidence() {
    try {
      const response = await get(`/employees/${employeeId}/violations/${v.id}/evidence`)
      if (!window.open(response.data.url, '_blank', 'noopener')) {
        toast.warning('Your browser blocked the document', 'Allow pop-ups for this site, then try again.')
      }
    } catch (err) {
      toast.error('Could not open the evidence', err.message)
    }
  }

  return (
    <Dialog open={Boolean(v)} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-lg">
        {v && (
          <>
            <DialogHeader>
              <DialogTitle>
                {humanise(v.type)} · {formatDate(v.date)}
              </DialogTitle>
              <DialogDescription>
                On the official record (validated by HR). Shown for review only; it does not decide the termination.
              </DialogDescription>
            </DialogHeader>

            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge status={v.status} />
              <Badge tone={v.counts_toward_threshold ? 'warning' : 'muted'}>
                {v.counts_toward_threshold
                  ? `Counts until ${formatDate(v.expires_on)}`
                  : `Stopped counting ${formatDate(v.expires_on)}`}
              </Badge>
            </div>

            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              <Detail label="What happened" value={<span className="whitespace-pre-line">{v.description}</span>} wide />
              <Detail label="Client company" value={v.company} />
              <Detail label="Department" value={v.department} />
              <Detail label="Penalty" value={v.penalty ?? 'None recorded'} />
              <Detail label="Resolved" value={v.resolution_date ? formatDate(v.resolution_date) : null} />
              <Detail label="Remarks" value={v.remarks && <span className="whitespace-pre-line">{v.remarks}</span>} wide />
              <Detail
                label="Recorded by"
                value={v.recorded_by ? `${v.recorded_by}${v.recorded_at ? ` · ${formatDateTime(v.recorded_at)}` : ''}` : null}
              />
              <Detail
                label="Reported by"
                value={
                  v.report_id ? (
                    <>
                      {v.reported_by ?? 'Coordinator'} ·{' '}
                      <Link to={`/violation-reports/${v.report_id}`} className="font-mono text-xs text-primary hover:underline">
                        {v.from_report}
                      </Link>
                    </>
                  ) : (
                    'Recorded directly by HR'
                  )
                }
              />
            </dl>

            {v.has_evidence && (
              <Button variant="outline" size="sm" className="self-start" onClick={openEvidence}>
                <FileText className="h-3.5 w-3.5" />
                Evidence
              </Button>
            )}
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}

function IncidentsCard({ incidents }) {
  if (!incidents) return null

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-base">Incident reports at the placement</CardTitle>
        <CardDescription>{incidents.note}</CardDescription>
      </CardHeader>
      <CardContent>
        {incidents.context.length === 0 ? (
          <p className="text-sm text-muted-foreground">No incident report was filed for this placement during the assignment.</p>
        ) : (
          <ul className="space-y-1.5 text-sm">
            {incidents.context.map((r) => (
              <li key={r.id}>
                <Link to={`/incident-reports/${r.id}`} className="font-medium text-primary hover:underline">
                  {r.title}
                </Link>
                <span className="block text-xs text-muted-foreground">
                  {r.report_code} · {formatDate(r.date)} · {[r.company, r.department ?? 'whole site'].join(' · ')} ·{' '}
                  {r.status_label}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

// ------------------------------------------------------------------ helpers

function Notice({ tone, icon: Icon, title, children }) {
  const tones = {
    info: 'border-status-info-border bg-status-info-bg text-status-info-text',
    warning: 'border-status-warning-border bg-status-warning-bg text-status-warning-text',
    destructive: 'border-status-danger-border bg-status-danger-bg text-status-danger-text',
  }

  return (
    <div className={`flex gap-2 rounded-md border p-3 text-sm ${tones[tone]}`} role="status">
      <Icon className="mt-0.5 h-4 w-4 shrink-0" />
      <div>
        {title && <p className="font-medium">{title}</p>}
        <p className="whitespace-pre-line">{children}</p>
      </div>
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
