import * as React from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Loader2, Lock, Send, Save, Trash2, FileText, CornerUpLeft, CheckCircle2 } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useApi } from '@/hooks/useApi'
import { get, upload, api } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { Field, FormGrid } from '@/components/ui/form'
import { StatusBadge } from '@/components/ui/badge'
import { LoadingState, ErrorState } from '@/components/ui/states'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import { formatDate, formatDateTime, todayISO } from '@/lib/utils'
import { ReportStatus, OutOfScope } from './CoordinatorEmployeeDetail'

/**
 * A violation report: written, corrected, or read.
 *
 * New reports and drafts are a form; so is a report HR has returned. Once it is
 * with HR, or decided, it is read-only - the coordinator can see what HR did
 * and the official record it led to, but cannot change either.
 *
 * The client company and department are shown locked. They are not form
 * fields: the server stamps them from the coordinator's assignment and the
 * employee's placement, whatever the browser sends.
 */
export default function CoordinatorReport() {
  const { id } = useParams()
  const isNew = !id

  const report = useApi(isNew ? null : `/coordinator/violation-reports/${id}`, undefined, { enabled: !isNew })

  if (!isNew && report.loading) return <LoadingState label="Loading report…" />
  if (!isNew && report.error?.status === 403) {
    return <OutOfScope message={report.error.message} backTo="/coordinator/reports" backLabel="Back to your reports" />
  }
  if (!isNew && report.error) return <ErrorState message={report.error.message} onRetry={report.refetch} />

  const data = isNew ? null : report.data

  if (data && !data.can_edit) {
    return <ReportView report={data} />
  }

  return <ReportForm existing={data} onSaved={report.refetch} />
}

function ReportForm({ existing, onSaved }) {
  const { user } = useAuth()
  const toast = useToast()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()

  const options = useApi('/coordinator/violation-reports/options', undefined, { cacheKey: 'coordinator-report-options' })
  const employees = useApi('/coordinator/employees', { status: 'active', per_page: 100 })

  const [form, setForm] = React.useState(() => ({
    employee_id: String(existing?.employee?.id ?? searchParams.get('employee') ?? ''),
    violation_type: existing?.violation_type ?? '',
    incident_date: existing?.incident_date ?? todayISO(),
    details: existing?.details ?? '',
    notes: existing?.notes ?? '',
  }))
  const [evidence, setEvidence] = React.useState(null)
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(null)
  const [confirmSubmit, setConfirmSubmit] = React.useState(false)
  const [confirmDiscard, setConfirmDiscard] = React.useState(false)

  const selected = (employees.data ?? []).find((e) => String(e.id) === form.employee_id)
  const set = (key) => (event) => setForm((f) => ({ ...f, [key]: event.target.value }))

  async function save(submit) {
    setSaving(submit ? 'submit' : 'draft')
    setErrors({})

    const body = new FormData()
    Object.entries(form).forEach(([key, value]) => value !== '' && body.append(key, value))
    if (evidence) body.append('evidence', evidence)
    body.append('submit', submit ? '1' : '0')

    try {
      const response = await upload(
        existing ? `/coordinator/violation-reports/${existing.id}` : '/coordinator/violation-reports',
        body
      )
      toast.success(response.message, submit ? 'HR has been notified.' : 'You can come back to it before submitting.')
      setConfirmSubmit(false)

      if (existing) onSaved()
      else navigate(`/coordinator/reports/${response.data.id}`, { replace: true })
    } catch (error) {
      setConfirmSubmit(false)
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not save the report', error.message)
    } finally {
      setSaving(null)
    }
  }

  async function discard() {
    try {
      await api.delete(`/coordinator/violation-reports/${existing.id}`)
      toast.success('Draft discarded')
      navigate('/coordinator/reports', { replace: true })
    } catch (error) {
      toast.error('Could not discard the draft', error.message)
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        breadcrumbs={[
          { label: 'Violation Reports', to: '/coordinator/reports' },
          { label: existing?.report_code ?? 'New report' },
        ]}
        title={existing ? `Report ${existing.report_code}` : 'New violation report'}
        description="Record what the client reported. HR reviews it before anything goes on the employee's record."
      />

      {existing?.status === 'returned' && (
        <div className="flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-sm">
          <CornerUpLeft className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
          <p>
            HR returned this report for correction
            {existing.reviewed_by && ` (${existing.reviewed_by})`}: <span className="font-medium">{existing.review_note}</span>
          </p>
        </div>
      )}

      <Card>
        <CardContent className="space-y-4 pt-5">
          <FormGrid>
            <Field label="Employee" htmlFor="employee_id" required error={errors.employee_id?.[0]}>
              <Select value={form.employee_id} onChange={set('employee_id')} placeholder="Choose an employee">
                {(employees.data ?? []).map((e) => (
                  <option key={e.id} value={String(e.id)}>
                    {e.full_name} · {e.employee_number}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label="Violation" htmlFor="violation_type" required error={errors.violation_type?.[0]}>
              <Select value={form.violation_type} onChange={set('violation_type')} placeholder="Choose the violation">
                {(options.data?.violation_types ?? []).map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </Select>
            </Field>

            <Locked label="Client company" value={user?.coordinator?.company_name ?? existing?.company_name} />
            <Locked label="Department" value={selected?.department ?? existing?.department_name ?? 'Set from the employee'} />

            <Field label="Incident date" htmlFor="incident_date" required error={errors.incident_date?.[0]}>
              <Input
                type="date"
                value={form.incident_date}
                max={todayISO()}
                onChange={set('incident_date')}
              />
            </Field>

            <Field
              label="Supporting evidence"
              htmlFor="evidence"
              error={errors.evidence?.[0]}
              hint={existing?.evidence_name ? `Attached: ${existing.evidence_name}. Choosing a file replaces it.` : 'Optional. PDF or image, up to 10 MB.'}
            >
              <Input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" onChange={(e) => setEvidence(e.target.files?.[0] ?? null)} />
            </Field>
          </FormGrid>

          <Field label="What happened" htmlFor="details" required error={errors.details?.[0]}>
            <Textarea
              rows={5}
              value={form.details}
              onChange={set('details')}
              placeholder="What the client reported, when, and who reported it to you."
            />
          </Field>

          <Field label="Notes for HR" htmlFor="notes" error={errors.notes?.[0]}>
            <Textarea rows={2} value={form.notes} onChange={set('notes')} placeholder="Optional" />
          </Field>

          <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-4">
            <div>
              {existing?.can_discard && (
                <Button variant="ghost" onClick={() => setConfirmDiscard(true)} disabled={saving !== null}>
                  <Trash2 className="h-4 w-4" />
                  Discard draft
                </Button>
              )}
            </div>
            <div className="flex gap-2">
              <Button variant="outline" onClick={() => save(false)} disabled={saving !== null}>
                {saving === 'draft' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                Save draft
              </Button>
              <Button onClick={() => setConfirmSubmit(true)} disabled={saving !== null}>
                <Send className="h-4 w-4" />
                Submit to HR
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <ConfirmActionDialog
        open={confirmSubmit}
        onOpenChange={setConfirmSubmit}
        tone="warning"
        title="Submit this report to HR?"
        description="HR is notified and reviews it. You cannot change it after this unless HR returns it to you."
        details={[
          ['Employee', selected?.full_name],
          ['Violation', options.data?.violation_types?.find((t) => t.value === form.violation_type)?.label],
          ['Incident date', formatDate(form.incident_date)],
        ]}
        confirmLabel="Submit report"
        onConfirm={() => save(true)}
      />

      <ConfirmActionDialog
        open={confirmDiscard}
        onOpenChange={setConfirmDiscard}
        tone="warning"
        title="Discard this draft?"
        description="It was never sent to HR, so nothing else is affected."
        confirmLabel="Discard draft"
        onConfirm={discard}
      />
    </div>
  )
}

function Locked({ label, value }) {
  return (
    <div className="space-y-1.5">
      <p className="flex items-center gap-1.5 text-sm font-medium">
        {label}
        <Lock className="h-3 w-3 text-muted-foreground" aria-label="Set automatically" />
      </p>
      <div className="flex h-9 items-center rounded-control border bg-muted/40 px-3 text-sm text-muted-foreground">
        {value ?? '—'}
      </div>
    </div>
  )
}

function ReportView({ report }) {
  const toast = useToast()

  async function openEvidence() {
    try {
      const response = await get(`/coordinator/violation-reports/${report.id}/evidence`)
      if (!window.open(response.data.url, '_blank', 'noopener')) {
        toast.warning('Your browser blocked the document', 'Allow pop-ups for this site, then try again.')
      }
    } catch (error) {
      toast.error('Could not open the evidence', error.message)
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        breadcrumbs={[{ label: 'Violation Reports', to: '/coordinator/reports' }, { label: report.report_code }]}
        title={`Report ${report.report_code}`}
        description={`${report.employee?.full_name} · ${report.type_label} on ${formatDate(report.incident_date)}`}
      />

      <div className="flex flex-wrap items-center gap-2">
        <ReportStatus status={report.status} label={report.status_label} />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-3">
            <CardTitle className="text-base">The report</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3 text-sm">
            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
              {[
                ['Employee', `${report.employee?.full_name} (${report.employee?.employee_number})`],
                ['Client company', report.company_name],
                ['Department', report.department_name],
                ['Violation', report.type_label],
                ['Incident date', formatDate(report.incident_date)],
                ['Reported by', report.reported_by?.name],
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
                <p className="text-xs text-muted-foreground">Notes for HR</p>
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
            <CardTitle className="text-base">With HR</CardTitle>
            <CardDescription>What has happened to this report.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3 text-sm">
            <p>
              <span className="text-xs text-muted-foreground">Submitted</span>
              <br />
              {formatDateTime(report.submitted_at)}
            </p>
            {report.reviewed_at && (
              <p>
                <span className="text-xs text-muted-foreground">Reviewed</span>
                <br />
                {formatDateTime(report.reviewed_at)} by {report.reviewed_by}
              </p>
            )}
            {report.review_note && (
              <p>
                <span className="text-xs text-muted-foreground">HR's note</span>
                <br />
                {report.review_note}
              </p>
            )}
            {report.violation && (
              <div className="rounded-md border border-status-success-border bg-status-success-bg/40 p-3">
                <p className="flex items-center gap-1.5 font-medium">
                  <CheckCircle2 className="h-4 w-4 text-success" />
                  Recorded as an official violation
                </p>
                <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                  <StatusBadge status={report.violation.status} />
                  {report.violation.penalty && <span>Penalty: {report.violation.penalty}</span>}
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
