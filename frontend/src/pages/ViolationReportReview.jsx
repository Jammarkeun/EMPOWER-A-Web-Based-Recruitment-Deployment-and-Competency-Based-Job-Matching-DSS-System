import * as React from 'react'
import { Link, useParams } from 'react-router-dom'
import { Loader2, FileText, CheckCircle2, CornerUpLeft, Ban, Eye } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { get, post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { Field } from '@/components/ui/form'
import { StatusBadge } from '@/components/ui/badge'
import { LoadingState, ErrorState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { formatDate, formatDateTime } from '@/lib/utils'
import { ReportStatus } from './coordinator/CoordinatorEmployeeDetail'

const VIOLATION_TYPES = [
  { value: 'awol', label: 'AWOL' },
  { value: 'absences', label: 'Absences' },
  { value: 'suspension', label: 'Suspension' },
  { value: 'late', label: 'Late' },
  { value: 'misconduct', label: 'Misconduct' },
  { value: 'policy_violation', label: 'Policy Violation' },
]

const ACTIONS = {
  return: {
    title: 'Return to the coordinator',
    description: 'They can correct it and submit it again. Say what needs fixing.',
    confirm: 'Return report',
    noteRequired: true,
  },
  validate: {
    title: 'Validate this report',
    description:
      "An official violation is recorded on the employee's file, linked to this report and the coordinator who made it. It counts towards the review threshold; reaching it flags the employee for review and never terminates anybody.",
    confirm: 'Validate and record violation',
    noteRequired: false,
  },
  dismiss: {
    title: 'Dismiss this report',
    description: 'No violation is recorded. The report stays on file as dismissed, with your reason.',
    confirm: 'Dismiss report',
    noteRequired: true,
  },
}

/**
 * HR's review of one coordinator report.
 */
export default function ViolationReportReview() {
  const { id } = useParams()
  const { can } = useAuth()
  const toast = useToast()
  const { data: report, loading, error, refetch } = useApi(`/violation-reports/${id}`)
  const [action, setAction] = React.useState(null)
  const [busy, setBusy] = React.useState(false)

  if (loading) return <LoadingState label="Loading report…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!report) return null

  async function startReview() {
    setBusy(true)
    try {
      await post(`/violation-reports/${id}/review`, { action: 'start_review' })
      toast.success('Marked as under review', 'The coordinator has been told.')
      refetch()
    } catch (err) {
      toast.error('Could not update the report', err.message)
    } finally {
      setBusy(false)
    }
  }

  async function openEvidence() {
    try {
      const response = await get(`/violation-reports/${id}/evidence`)
      if (!window.open(response.data.url, '_blank', 'noopener')) {
        toast.warning('Your browser blocked the document', 'Allow pop-ups for this site, then try again.')
      }
    } catch (err) {
      toast.error('Could not open the evidence', err.message)
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        breadcrumbs={[{ label: 'Violation Reports', to: '/violation-reports' }, { label: report.report_code }]}
        title={`Report ${report.report_code}`}
        description={`${report.company_name} · reported by ${report.reported_by?.name} · submitted ${formatDateTime(report.submitted_at)}`}
        actions={
          report.can_review && (
            <>
              {report.status === 'submitted' && (
                <Button variant="outline" onClick={startReview} disabled={busy}>
                  {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Eye className="h-4 w-4" />}
                  Start review
                </Button>
              )}
              <Button variant="outline" onClick={() => setAction('return')}>
                <CornerUpLeft className="h-4 w-4" />
                Return
              </Button>
              <Button variant="outline" onClick={() => setAction('dismiss')}>
                <Ban className="h-4 w-4" />
                Dismiss
              </Button>
              <Button onClick={() => setAction('validate')}>
                <CheckCircle2 className="h-4 w-4" />
                Validate
              </Button>
            </>
          )
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <ReportStatus status={report.status} label={report.status_label} />
        {!can('violation_reports.review') && (
          <span className="text-xs text-muted-foreground">You can read this report but not review it.</span>
        )}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-3">
            <CardTitle className="text-base">What the coordinator reported</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3 text-sm">
            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
              <div>
                <dt className="text-xs text-muted-foreground">Employee</dt>
                <dd>
                  <Link to={`/employees/${report.employee?.id}`} className="text-primary hover:underline">
                    {report.employee?.full_name}
                  </Link>{' '}
                  <span className="font-mono text-xs text-muted-foreground">{report.employee?.employee_number}</span>
                </dd>
              </div>
              {[
                ['Position', report.employee?.position],
                ['Client company', report.company_name],
                ['Department', report.department_name],
                ['Violation', report.type_label],
                ['Incident date', formatDate(report.incident_date)],
              ]
                .filter(([, v]) => v)
                .map(([label, value]) => (
                  <div key={label}>
                    <dt className="text-xs text-muted-foreground">{label}</dt>
                    <dd>{value}</dd>
                  </div>
                ))}
            </dl>
            <div>
              <p className="text-xs text-muted-foreground">What happened</p>
              <p className="whitespace-pre-line">{report.details}</p>
            </div>
            {report.notes && (
              <div>
                <p className="text-xs text-muted-foreground">Coordinator's notes</p>
                <p className="whitespace-pre-line">{report.notes}</p>
              </div>
            )}
            {report.has_evidence && (
              <Button variant="outline" size="sm" onClick={openEvidence}>
                <FileText className="h-3.5 w-3.5" />
                {report.evidence_name ?? 'Evidence'}
              </Button>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Review</CardTitle>
            <CardDescription>HR validates; the administrator alone finalises any termination.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3 text-sm">
            {report.reviewed_at ? (
              <p>
                {formatDateTime(report.reviewed_at)} by {report.reviewed_by}
                {report.review_note && (
                  <>
                    <br />
                    <span className="text-muted-foreground">{report.review_note}</span>
                  </>
                )}
              </p>
            ) : (
              <p className="text-muted-foreground">Not reviewed yet.</p>
            )}
            {report.violation && (
              <div className="rounded-md border p-3">
                <p className="font-medium">Official violation recorded</p>
                <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                  <StatusBadge status={report.violation.status} />
                  {report.violation.penalty && <span>Penalty: {report.violation.penalty}</span>}
                </div>
                <Link to={`/employees/${report.employee?.id}`} className="mt-2 inline-block text-xs text-primary hover:underline">
                  Open the employee's disciplinary record
                </Link>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      <ReviewDialog
        action={action}
        report={report}
        onOpenChange={(open) => !open && setAction(null)}
        onDone={() => {
          setAction(null)
          refetch()
        }}
      />
    </div>
  )
}

function ReviewDialog({ action, report, onOpenChange, onDone }) {
  const toast = useToast()
  const config = action ? ACTIONS[action] : null
  const [form, setForm] = React.useState({ note: '', violation_type: '', penalty: '' })
  const [errors, setErrors] = React.useState({})
  const [busy, setBusy] = React.useState(false)

  React.useEffect(() => {
    if (action) {
      setForm({ note: '', violation_type: report.violation_type, penalty: '' })
      setErrors({})
    }
  }, [action, report.violation_type])

  if (!config) return null

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      const response = await post(`/violation-reports/${report.id}/review`, {
        action,
        note: form.note || null,
        ...(action === 'validate' ? { violation_type: form.violation_type, penalty: form.penalty || null } : {}),
      })
      toast.success(response.message, 'The coordinator has been told.')
      onDone()
    } catch (err) {
      if (err.isValidation) setErrors(err.errors)
      else toast.error('Could not update the report', err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !busy && onOpenChange(open)}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{config.title}</DialogTitle>
          <DialogDescription>{config.description}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4" noValidate>
          {action === 'validate' && (
            <>
              <Field label="Violation" htmlFor="review_type" hint="Correct the classification if the coordinator chose the wrong one.">
                <Select value={form.violation_type} onChange={(e) => setForm({ ...form, violation_type: e.target.value })}>
                  {VIOLATION_TYPES.map((t) => (
                    <option key={t.value} value={t.value}>
                      {t.label}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Penalty" htmlFor="review_penalty" error={errors.penalty?.[0]}>
                <Input value={form.penalty} onChange={(e) => setForm({ ...form, penalty: e.target.value })} placeholder="e.g. Written warning" />
              </Field>
            </>
          )}
          <Field
            label={config.noteRequired ? 'Reason' : 'Note (optional)'}
            htmlFor="review_note"
            required={config.noteRequired}
            error={errors.note?.[0]}
          >
            <Textarea value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
              Cancel
            </Button>
            <Button type="submit" variant={action === 'dismiss' ? 'destructive' : 'default'} disabled={busy}>
              {busy && <Loader2 className="h-4 w-4 animate-spin" />}
              {config.confirm}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
