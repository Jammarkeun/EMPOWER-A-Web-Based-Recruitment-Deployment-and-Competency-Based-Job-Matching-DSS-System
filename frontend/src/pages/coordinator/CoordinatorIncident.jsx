import * as React from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Loader2, Lock, Send, Save, Trash2, FileText } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useApi } from '@/hooks/useApi'
import { get, upload, api } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { Field, FormGrid } from '@/components/ui/form'
import { LoadingState, ErrorState } from '@/components/ui/states'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import IncidentThread from '@/components/coordination/IncidentThread'
import { formatDate, todayISO } from '@/lib/utils'
import { DOCUMENT_ACCEPT } from '@/lib/uploads'
import { ReportStatus, OutOfScope } from './CoordinatorEmployeeDetail'

/**
 * One incident report: written while it is a draft, read with its notes once
 * it is sent. The client company is not a field - the server stamps it from
 * the coordinator's assignment.
 */
export default function CoordinatorIncident() {
  const { id } = useParams()
  const isNew = !id

  const report = useApi(isNew ? null : `/coordinator/incident-reports/${id}`, undefined, { enabled: !isNew })

  if (!isNew && report.loading) return <LoadingState label="Loading report…" />
  if (!isNew && report.error?.status === 403) {
    return <OutOfScope message={report.error.message} backTo="/coordinator/incidents" backLabel="Back to your incident reports" />
  }
  if (!isNew && report.error) return <ErrorState message={report.error.message} onRetry={report.refetch} />

  const data = isNew ? null : report.data

  if (data && !data.can_edit) {
    return <IncidentView report={data} onChanged={report.refetch} />
  }

  return <IncidentForm existing={data} onSaved={report.refetch} />
}

function IncidentForm({ existing, onSaved }) {
  const { user } = useAuth()
  const toast = useToast()
  const navigate = useNavigate()
  const options = useApi('/coordinator/incident-reports/options', undefined, { cacheKey: 'coordinator-incident-options' })

  const [form, setForm] = React.useState(() => ({
    title: existing?.title ?? '',
    incident_date: existing?.incident_date ?? todayISO(),
    incident_time: existing?.incident_time ?? '',
    client_department_id: existing?.client_department_id ? String(existing.client_department_id) : '',
    location: existing?.location ?? '',
    details: existing?.details ?? '',
  }))
  const [evidence, setEvidence] = React.useState(null)
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(null)
  const [confirmSubmit, setConfirmSubmit] = React.useState(false)
  const [confirmDiscard, setConfirmDiscard] = React.useState(false)

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
        existing ? `/coordinator/incident-reports/${existing.id}` : '/coordinator/incident-reports',
        body
      )
      toast.success(response.message, submit ? 'The office has been notified.' : 'You can come back to it before sending.')
      setConfirmSubmit(false)

      if (existing) onSaved()
      else navigate(`/coordinator/incidents/${response.data.id}`, { replace: true })
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
      await api.delete(`/coordinator/incident-reports/${existing.id}`)
      toast.success('Draft discarded')
      navigate('/coordinator/incidents', { replace: true })
    } catch (error) {
      toast.error('Could not discard the draft', error.message)
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        breadcrumbs={[
          { label: 'Incident Reports', to: '/coordinator/incidents' },
          { label: existing?.report_code ?? 'New report' },
        ]}
        title={existing ? `Incident ${existing.report_code}` : 'New incident report'}
        description="What happened on site, for the office to read. If it is about one worker's conduct, file a violation report instead."
      />

      <Card>
        <CardContent className="space-y-4 pt-5">
          <Field label="What happened, in a line" htmlFor="title" required error={errors.title?.[0]}>
            <Input value={form.title} onChange={set('title')} maxLength={150} placeholder="e.g. Loading department sent home - truck broke down" />
          </Field>

          <FormGrid>
            <div className="space-y-1.5">
              <p className="flex items-center gap-1.5 text-sm font-medium">
                Client company
                <Lock className="h-3 w-3 text-muted-foreground" aria-label="Set automatically" />
              </p>
              <div className="flex h-11 items-center rounded-control border bg-muted/40 px-3 text-sm text-muted-foreground">
                {user?.coordinator?.company_name ?? existing?.company_name ?? '—'}
              </div>
            </div>

            <Field label="Department" htmlFor="client_department_id" error={errors.client_department_id?.[0]} hint="Optional">
              <Select value={form.client_department_id} onChange={set('client_department_id')} placeholder="Not one department">
                {(options.data?.departments ?? []).map((d) => (
                  <option key={d.id} value={String(d.id)}>
                    {d.department_name}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label="Date" htmlFor="incident_date" required error={errors.incident_date?.[0]}>
              <Input type="date" value={form.incident_date} max={todayISO()} onChange={set('incident_date')} />
            </Field>

            <Field label="Time" htmlFor="incident_time" error={errors.incident_time?.[0]} hint="Optional">
              <Input type="time" value={form.incident_time} onChange={set('incident_time')} />
            </Field>

            <Field label="Location on site" htmlFor="location" error={errors.location?.[0]} hint="Optional">
              <Input value={form.location} onChange={set('location')} maxLength={150} placeholder="e.g. Warehouse loading bay" />
            </Field>

            <Field
              label="Supporting evidence"
              htmlFor="evidence"
              error={errors.evidence?.[0]}
              hint={existing?.evidence_name ? `Attached: ${existing.evidence_name}. Choosing a file replaces it.` : 'Optional. PDF, photo or Office document, up to 10 MB.'}
            >
              <Input type="file" accept={DOCUMENT_ACCEPT}onChange={(e) => setEvidence(e.target.files?.[0] ?? null)} />
            </Field>
          </FormGrid>

          <Field label="Details" htmlFor="details" required error={errors.details?.[0]}>
            <Textarea
              rows={5}
              value={form.details}
              onChange={set('details')}
              placeholder="What happened, who was affected, what was done about it, and who told you."
            />
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
                Send to the office
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <ConfirmActionDialog
        open={confirmSubmit}
        onOpenChange={setConfirmSubmit}
        tone="warning"
        title="Send this incident report?"
        description="The office is notified. After this you can add follow-up notes, but not change the report."
        details={[
          ['What happened', form.title],
          ['Date', formatDate(form.incident_date)],
        ]}
        confirmLabel="Send report"
        onConfirm={() => save(true)}
      />

      <ConfirmActionDialog
        open={confirmDiscard}
        onOpenChange={setConfirmDiscard}
        tone="warning"
        title="Discard this draft?"
        description="It was never sent, so nothing else is affected."
        confirmLabel="Discard draft"
        onConfirm={discard}
      />
    </div>
  )
}

function IncidentView({ report, onChanged }) {
  const toast = useToast()

  async function openEvidence() {
    try {
      const response = await get(`/coordinator/incident-reports/${report.id}/evidence`)
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
        breadcrumbs={[{ label: 'Incident Reports', to: '/coordinator/incidents' }, { label: report.report_code }]}
        title={report.title}
        description={`${report.report_code} · ${formatDate(report.incident_date)}${report.incident_time ? ` at ${report.incident_time}` : ''}`}
      />

      <ReportStatus status={report.status} label={report.status_label} />

      <div className="grid gap-4 lg:grid-cols-5">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-3">
            <CardTitle className="text-base">The report</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3 text-sm">
            <IncidentFacts report={report} />
            {report.has_evidence && (
              <Button variant="outline" size="sm" onClick={openEvidence}>
                <FileText className="h-3.5 w-3.5" />
                {report.evidence_name ?? 'Evidence'}
              </Button>
            )}
          </CardContent>
        </Card>

        <div className="lg:col-span-3">
          <IncidentThread
            report={report}
            endpoint={`/coordinator/incident-reports/${report.id}/notes`}
            canChangeStatus={false}
            onChanged={onChanged}
          />
        </div>
      </div>
    </div>
  )
}

/** The facts of an incident, shared with the office's screen. */
export function IncidentFacts({ report }) {
  return (
    <>
      <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        {[
          ['Client company', report.company_name],
          ['Department', report.department_name],
          ['Location', report.location],
          ['Reported by', report.reported_by?.name],
          ['Resolved', report.resolved_at ? `${formatDate(report.resolved_at)}${report.resolved_by ? ` by ${report.resolved_by}` : ''}` : null],
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
        <p className="text-xs text-muted-foreground">Details</p>
        <p className="whitespace-pre-line">{report.details}</p>
      </div>
    </>
  )
}
