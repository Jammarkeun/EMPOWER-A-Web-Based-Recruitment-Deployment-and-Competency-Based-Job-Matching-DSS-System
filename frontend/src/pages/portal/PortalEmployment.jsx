import * as React from 'react'
import { Loader2, FileWarning, FileText, LogOut, ScrollText, Phone, CheckCircle2 } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { get, post, upload } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Field } from '@/components/ui/form'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { DOCUMENT_ACCEPT } from '@/lib/uploads'
import { formatDate, formatDateTime, todayISO } from '@/lib/utils'

const EMPLOYMENT_LABELS = {
  active: 'Active',
  resigned: 'Resigned',
  terminated: 'Employment ended',
}

/**
 * The employee's own employment record, and the way out of it they control.
 *
 * Everything about a separation here comes from the server already filtered
 * for the employee (SeparationPresenter): what they asked for, what HR told
 * them, and what happens next. The office's own reasons and notes never reach
 * this page, so nothing here has to hide them.
 */
export default function PortalEmployment() {
  const { data, loading, error, refetch, setData } = useApi('/portal/employment')
  const [requestOpen, setRequestOpen] = React.useState(false)

  if (loading) return <LoadingState label="Loading your employment record…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const separation = data.separation
  // Every separation action answers with the section as it now stands.
  const onSeparation = (next) => setData((current) => ({ ...current, separation: next }))

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-[26px] font-normal leading-tight tracking-[-0.01em] sm:text-[32px]">My employment</h1>
          <p className="text-sm text-muted-foreground">
            Employee number <span className="font-mono">{data.employee_number}</span>
          </p>
        </div>
        <StatusBadge status={data.status} label={EMPLOYMENT_LABELS[data.status]} />
      </div>

      {separation?.notice && <NoticeCard notice={separation.notice} onChanged={onSeparation} />}

      {separation?.resignation && (
        <ResignationCard resignation={separation.resignation} onChanged={onSeparation} />
      )}

      {separation?.eligibility?.can_request ? (
        <Card>
          <CardContent className="flex flex-wrap items-center justify-between gap-3 pt-5">
            <div className="min-w-0">
              <p className="text-sm font-medium">Request to resign</p>
              <p className="text-sm text-muted-foreground">
                Send HR a resignation request. Your employment continues until HR completes it after
                clearance.
              </p>
            </div>
            <Button variant="outline" onClick={() => setRequestOpen(true)}>
              <LogOut className="h-4 w-4" />
              Request to resign
            </Button>
          </CardContent>
        </Card>
      ) : (
        // Only worth saying for an active employee: for anyone else the status
        // above already explains it, and an open request is shown above.
        data.status === 'active' &&
        !separation?.resignation?.is_open &&
        separation?.eligibility?.reason && (
          <p className="text-sm text-muted-foreground">{separation.eligibility.reason}</p>
        )
      )}

      {separation?.past_resignations?.length > 0 && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Earlier resignation requests</CardTitle>
          </CardHeader>
          <CardContent className="divide-y">
            {separation.past_resignations.map((past, index) => (
              <div key={index} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                <p className="text-sm">Submitted {formatDate(past.submitted_on)}</p>
                <span className="text-xs text-muted-foreground">
                  {past.status_label}
                  {past.closed_on ? ` · ${formatDate(past.closed_on)}` : ''}
                </span>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">
            {data.status === 'active' ? 'Current assignment' : 'Last assignment'}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-3 sm:grid-cols-2">
            {[
              ['Company', data.current.company],
              ['Department', data.current.department],
              ['Position', data.current.position],
              ['Supervisor', data.current.supervisor],
              ['Start date', formatDate(data.hire_date)],
              ['Biometrics number', data.biometric_number],
            ]
              .filter(([, value]) => value)
              .map(([label, value]) => (
                <div key={label}>
                  <dt className="text-xs text-muted-foreground">{label}</dt>
                  <dd className="text-sm font-medium">{value}</dd>
                </div>
              ))}
          </dl>
        </CardContent>
      </Card>

      {data.deployments?.length > 1 && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Assignment history</CardTitle>
          </CardHeader>
          <CardContent className="divide-y">
            {data.deployments.map((deployment) => (
              <div key={deployment.code} className="py-2.5">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{deployment.position}</p>
                    <p className="text-xs text-muted-foreground">
                      {deployment.company}
                      {deployment.department && ` · ${deployment.department}`}
                    </p>
                  </div>
                  <Badge tone={deployment.status === 'active' ? 'success' : 'muted'}>
                    {deployment.status}
                  </Badge>
                </div>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {formatDate(deployment.from)}
                  {deployment.to ? ` to ${formatDate(deployment.to)}` : ' — present'}
                </p>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      {/*
        Disciplinary records are shown to the employee deliberately. A record
        they cannot see is one they cannot answer, and the agency's own process
        requires them to be informed of a violation anyway.
      */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Disciplinary record</CardTitle>
        </CardHeader>
        <CardContent>
          {!data.violations?.length ? (
            <EmptyState
              icon={FileWarning}
              title="No violations on record"
              description="Your disciplinary record is clear."
            />
          ) : (
            <div className="divide-y">
              {data.violations.map((violation, index) => (
                <div key={index} className="py-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone="warning">{violation.type}</Badge>
                    <span className="text-xs text-muted-foreground">{formatDate(violation.date)}</span>
                  </div>
                  <p className="mt-1.5 text-sm">{violation.description}</p>
                  {violation.penalty && (
                    <p className="mt-0.5 text-xs text-muted-foreground">Penalty: {violation.penalty}</p>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      <ResignationRequestDialog
        open={requestOpen}
        onOpenChange={setRequestOpen}
        noticeDays={separation?.notice_days}
        onSubmitted={onSeparation}
      />
    </div>
  )
}

// ------------------------------------------------------------- the notice

/**
 * A finalised termination, as the employee is told it.
 *
 * Acknowledging records receipt and nothing more; the page says so before the
 * button, because "I acknowledge" is easily read as "I agree".
 */
function NoticeCard({ notice, onChanged }) {
  const toast = useToast()
  const [busy, setBusy] = React.useState(false)

  async function acknowledge() {
    setBusy(true)
    try {
      const response = await post('/portal/separation-notice/acknowledge')
      onChanged(response.data)
      toast.success('Receipt recorded', 'The office can see that you have received this notice.')
    } catch (error) {
      toast.error('Could not record your confirmation', error.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card className="border-primary/30" aria-labelledby="notice-title">
      <CardHeader className="pb-3">
        <CardTitle id="notice-title" className="flex items-center gap-2 text-base">
          <ScrollText className="h-4 w-4 text-primary" />
          Notice about your employment
        </CardTitle>
        <CardDescription>Issued {formatDate(notice.issued_on)}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <p className="text-sm">
          A formal decision ending your employment has been recorded, effective{' '}
          <span className="font-medium">{formatDate(notice.effective_date)}</span>. Your employment record is
          now closed.
        </p>

        {notice.message ? (
          <div className="rounded-md border bg-muted/30 p-3">
            <p className="text-xs font-medium text-muted-foreground">From the HR office</p>
            <p className="mt-1 whitespace-pre-line text-sm">{notice.message}</p>
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">
            The HR office will give you the details of this decision. Please contact them if you have not
            received them.
          </p>
        )}

        <dl className="grid gap-3 sm:grid-cols-3">
          <Detail label="Effective date" value={formatDate(notice.effective_date)} />
          <Detail label="Notice issued" value={formatDate(notice.issued_on)} />
          <Detail label="Clearance" value={notice.clearance?.label} />
        </dl>

        <NextSteps steps={notice.next_steps} />
        <OfficeContact office={notice.office} />

        <div className="border-t pt-4">
          {notice.acknowledged_at ? (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <CheckCircle2 className="h-4 w-4 text-success" />
              You confirmed receiving this notice on {formatDateTime(notice.acknowledged_at)}.
            </p>
          ) : (
            <div className="space-y-2">
              <p className="text-xs text-muted-foreground">
                Confirming tells the office that you have received and read this notice. It does not mean
                you agree with the decision, and it does not affect any of your rights.
              </p>
              <Button size="sm" onClick={acknowledge} disabled={busy}>
                {busy && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
                I have received this notice
              </Button>
            </div>
          )}
        </div>

        <Timeline entries={notice.timeline} />
      </CardContent>
    </Card>
  )
}

// ------------------------------------------------------- the request's progress

function ResignationCard({ resignation, onChanged }) {
  const toast = useToast()
  const [withdrawOpen, setWithdrawOpen] = React.useState(false)
  const [withdrawReason, setWithdrawReason] = React.useState('')
  const [reply, setReply] = React.useState('')
  const [replyError, setReplyError] = React.useState('')
  const [sending, setSending] = React.useState(false)

  async function withdraw() {
    try {
      const response = await post('/portal/resignation/withdraw', { reason: withdrawReason.trim() || undefined })
      onChanged(response.data)
      setWithdrawOpen(false)
      toast.success('Request withdrawn', 'Your employment continues.')
    } catch (error) {
      toast.error('Could not withdraw the request', error.message)
    }
  }

  async function sendReply(event) {
    event.preventDefault()
    if (!reply.trim()) {
      setReplyError('Write your reply to HR.')
      return
    }

    setSending(true)
    setReplyError('')
    try {
      const response = await post('/portal/resignation/respond', { message: reply.trim() })
      onChanged(response.data)
      setReply('')
      toast.success('Reply sent', 'HR will continue reviewing your request.')
    } catch (error) {
      setReplyError(error.errors?.message?.[0] ?? error.message)
    } finally {
      setSending(false)
    }
  }

  async function openLetter() {
    try {
      const response = await get('/portal/resignation/letter')
      const opened = window.open(response.data.url, '_blank', 'noopener')
      if (!opened) toast.warning('Your browser blocked the letter', 'Allow pop-ups for this site, then try again.')
    } catch (error) {
      toast.error('Could not open the letter', error.message)
    }
  }

  const tone = resignation.status === 'info_requested' ? 'border-warning/40 bg-warning/[0.04]' : ''

  return (
    <Card className={tone}>
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div className="space-y-1">
            <CardTitle className="text-base">Your resignation request</CardTitle>
            <CardDescription>
              Submitted {formatDate(resignation.submitted_on)}
              {resignation.submitted_through === 'office' ? ' at the office' : ' through the portal'}
            </CardDescription>
          </div>
          <StatusBadge status={resignation.status} label={resignation.status_label} />
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <dl className="grid gap-3 sm:grid-cols-3">
          <Detail label="Last day you asked for" value={formatDate(resignation.requested_last_day, 'Not stated')} />
          <Detail
            label="Last working day (confirmed by HR)"
            value={resignation.confirmed_last_day ? formatDate(resignation.confirmed_last_day) : 'Not confirmed yet'}
          />
          {resignation.clearance && <Detail label="Clearance" value={resignation.clearance.label} />}
          <Detail label="Your reason" value={resignation.reason} wide />
          {resignation.remarks && <Detail label="Your remarks" value={resignation.remarks} wide />}
        </dl>

        {resignation.hr_message && (
          <div className="rounded-md border bg-muted/30 p-3">
            <p className="text-xs font-medium text-muted-foreground">Message from HR</p>
            <p className="mt-1 whitespace-pre-line text-sm">{resignation.hr_message}</p>
          </div>
        )}

        <NextSteps steps={resignation.next_steps} />

        {resignation.can_respond && (
          <form onSubmit={sendReply} className="space-y-2" noValidate>
            <Field label="Your reply to HR" htmlFor="resignation_reply" required error={replyError}>
              <Textarea value={reply} maxLength={2000} onChange={(e) => setReply(e.target.value)} />
            </Field>
            <Button type="submit" size="sm" disabled={sending}>
              {sending && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
              Send reply
            </Button>
          </form>
        )}

        {(resignation.can_withdraw || resignation.has_letter) && (
          <div className="flex flex-wrap gap-2">
            {resignation.has_letter && (
              <Button variant="outline" size="sm" onClick={openLetter}>
                <FileText className="h-3.5 w-3.5" />
                Your letter
              </Button>
            )}
            {resignation.can_withdraw && (
              <Button variant="ghost" size="sm" onClick={() => setWithdrawOpen(true)}>
                Withdraw request
              </Button>
            )}
          </div>
        )}

        <Timeline entries={resignation.timeline} />
      </CardContent>

      <ConfirmActionDialog
        open={withdrawOpen}
        onOpenChange={(next) => {
          setWithdrawOpen(next)
          if (!next) setWithdrawReason('')
        }}
        tone="warning"
        title="Withdraw your resignation request?"
        description="Your employment continues as before. The request stays on file, marked withdrawn, and you can send a new one later."
        confirmLabel="Withdraw request"
        onConfirm={withdraw}
      >
        <Field label="Reason (optional)" htmlFor="withdraw_reason">
          <Textarea value={withdrawReason} maxLength={255} onChange={(e) => setWithdrawReason(e.target.value)} />
        </Field>
      </ConfirmActionDialog>
    </Card>
  )
}

// ---------------------------------------------------------------- the form

function daysBetween(fromISO, toISO) {
  // Both are plain dates, read the same way, so the difference is whole days.
  return Math.round((Date.parse(toISO) - Date.parse(fromISO)) / 86400000)
}

const EMPTY_REQUEST = { requested_exit_date: '', reason: '', remarks: '', acknowledged: false }

function ResignationRequestDialog({ open, onOpenChange, noticeDays, onSubmitted }) {
  const toast = useToast()
  const [form, setForm] = React.useState(EMPTY_REQUEST)
  const [letter, setLetter] = React.useState(null)
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const today = todayISO()

  React.useEffect(() => {
    if (open) {
      setForm(EMPTY_REQUEST)
      setLetter(null)
      setErrors({})
    }
  }, [open])

  const notice = form.requested_exit_date ? daysBetween(today, form.requested_exit_date) : null
  const shortNotice = noticeDays && notice !== null && notice >= 0 && notice < noticeDays

  function validate() {
    const found = {}
    if (!form.requested_exit_date) found.requested_exit_date = ['Choose the last day you intend to work.']
    else if (form.requested_exit_date < today) found.requested_exit_date = ['Your last working day cannot be in the past.']
    if (!form.reason.trim()) found.reason = ['Tell HR why you are resigning.']
    if (!form.acknowledged) {
      found.acknowledged = ['Please confirm that you understand this request does not end your employment by itself.']
    }
    return found
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const found = validate()
    setErrors(found)
    if (Object.keys(found).length) return

    const body = new FormData()
    body.append('requested_exit_date', form.requested_exit_date)
    body.append('reason', form.reason.trim())
    if (form.remarks.trim()) body.append('remarks', form.remarks.trim())
    if (letter) body.append('letter', letter)
    body.append('acknowledged', '1')

    setSubmitting(true)
    try {
      const response = await upload('/portal/resignation', body)
      toast.success('Request sent to HR', 'Keep reporting for work until HR confirms your last working day.')
      onOpenChange(false)
      onSubmitted(response.data)
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not send your request', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !submitting && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Request to resign</DialogTitle>
          <DialogDescription>
            HR reviews your request and confirms your last working day. Your employment continues until HR
            completes your resignation after clearance.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Field
            label="Last day you intend to work"
            htmlFor="requested_exit_date"
            required
            error={errors.requested_exit_date?.[0]}
            hint={noticeDays ? `CDE asks for ${noticeDays} days' notice. HR will confirm the date with you.` : undefined}
          >
            <Input
              type="date"
              value={form.requested_exit_date}
              min={today}
              onChange={(e) => setForm({ ...form, requested_exit_date: e.target.value })}
            />
          </Field>
          {shortNotice && !errors.requested_exit_date && (
            <p className="-mt-2 text-xs text-warning" role="status">
              That is {notice} {notice === 1 ? 'day' : 'days'} from today, less than the {noticeDays} days&apos; notice
              CDE asks for. You can still send it; explain below if you need to leave sooner.
            </p>
          )}

          <Field label="Reason for resigning" htmlFor="resignation_reason" required error={errors.reason?.[0]}>
            <Textarea
              value={form.reason}
              maxLength={255}
              onChange={(e) => setForm({ ...form, reason: e.target.value })}
            />
          </Field>

          <Field label="Anything else HR should know" htmlFor="resignation_remarks" error={errors.remarks?.[0]}>
            <Textarea
              value={form.remarks}
              maxLength={2000}
              onChange={(e) => setForm({ ...form, remarks: e.target.value })}
            />
          </Field>

          <Field
            label="Resignation letter"
            htmlFor="resignation_letter"
            error={errors.letter?.[0]}
            hint="Optional. If you have a signed letter, attach a photo or scan of it."
          >
            <Input type="file" accept={DOCUMENT_ACCEPT} onChange={(e) => setLetter(e.target.files?.[0] ?? null)} />
          </Field>

          <div className="space-y-1">
            <label className="flex cursor-pointer items-start gap-2 text-sm">
              <input
                type="checkbox"
                checked={form.acknowledged}
                onChange={(e) => setForm({ ...form, acknowledged: e.target.checked })}
                aria-invalid={errors.acknowledged ? 'true' : undefined}
                aria-describedby={errors.acknowledged ? 'acknowledged-error' : undefined}
                className="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-input accent-primary"
              />
              <span>
                I understand that this is a request. My employment continues until HR completes my resignation
                after clearance, and I will keep reporting for work until HR confirms my last working day.
              </span>
            </label>
            {errors.acknowledged && (
              <p id="acknowledged-error" className="text-xs font-medium text-destructive">
                {errors.acknowledged[0]}
              </p>
            )}
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Send request
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

// ----------------------------------------------------------------- pieces

function Detail({ label, value, wide = false }) {
  if (!value) return null

  return (
    <div className={wide ? 'sm:col-span-full' : undefined}>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="text-sm font-medium">{value}</dd>
    </div>
  )
}

function NextSteps({ steps }) {
  if (!steps?.length) return null

  return (
    <div className="space-y-1.5">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">What happens next</p>
      <ul className="list-disc space-y-1 pl-5 text-sm">
        {steps.map((step) => (
          <li key={step}>{step}</li>
        ))}
      </ul>
    </div>
  )
}

function OfficeContact({ office }) {
  if (!office?.name) return null

  return (
    <div className="space-y-0.5 text-sm">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Contact</p>
      <p className="font-medium">{office.name}</p>
      {office.address && <p className="text-muted-foreground">{office.address}</p>}
      {office.contact && (
        <a href={`tel:${office.contact}`} className="inline-flex items-center gap-2 text-primary hover:underline">
          <Phone className="h-3.5 w-3.5" />
          {office.contact}
        </a>
      )}
    </div>
  )
}

function Timeline({ entries }) {
  if (!entries?.length) return null

  return (
    <div className="space-y-2">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">History</p>
      <ol className="relative space-y-3 border-l pl-5">
        {entries.map((entry, index) => (
          <li key={index} className="relative">
            <span className="absolute -left-[27px] mt-1 h-2.5 w-2.5 rounded-full border-2 border-card bg-primary" />
            <p className="text-sm font-medium">{entry.label}</p>
            {entry.note && <p className="whitespace-pre-line text-xs text-muted-foreground">{entry.note}</p>}
            <p className="text-xs text-muted-foreground">
              {entry.by} · {formatDateTime(entry.at)}
            </p>
          </li>
        ))}
      </ol>
    </div>
  )
}
