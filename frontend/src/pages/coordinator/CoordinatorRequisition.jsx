import * as React from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Loader2, Lock, Send, Save, Trash2, CornerUpLeft, Printer, Info } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useApi } from '@/hooks/useApi'
import { post, api } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input, NumberInput } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { Field, FormGrid } from '@/components/ui/form'
import { LoadingState, ErrorState } from '@/components/ui/states'
import { LogoMark } from '@/components/Logo'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import {
  ProvisionalFormNotice,
  RequisitionDetails,
  RequisitionPrintSheet,
  RequisitionProgress,
  TemporaryFormFields,
  manpowerSummary,
} from '@/components/coordination/RequisitionParts'
import { formatDate, todayISO } from '@/lib/utils'
import { ReportStatus, OutOfScope } from './CoordinatorEmployeeDetail'

/** The reasons with something written beside the tick. */
const REASONS_WITH_DETAILS = ['replacement', 'others']

/**
 * One manpower requisition: CDE's Manpower Requisition Form while it is a
 * draft or returned, read-only once it is with HR.
 */
export default function CoordinatorRequisition() {
  const { id } = useParams()
  const isNew = !id

  const requisition = useApi(isNew ? null : `/coordinator/requisitions/${id}`, undefined, { enabled: !isNew })
  const options = useApi('/coordinator/requisitions/options')

  if ((!isNew && requisition.loading) || options.loading) return <LoadingState label="Loading requisition…" />
  if (!isNew && requisition.error?.status === 403) {
    return <OutOfScope message={requisition.error.message} backTo="/coordinator/requisitions" backLabel="Back to your requisitions" />
  }
  if (!isNew && requisition.error) return <ErrorState message={requisition.error.message} onRetry={requisition.refetch} />
  if (options.error) return <ErrorState message={options.error.message} onRetry={options.refetch} />

  const data = isNew ? null : requisition.data

  if (data && !data.can_edit) {
    return (
      <>
        <div className="no-print space-y-5">
          <PageHeader
            breadcrumbs={[{ label: 'Manpower Requisitions', to: '/coordinator/requisitions' }, { label: data.requisition_code }]}
            title={`${data.workers_needed} × ${data.position_title}`}
            description={`${data.requisition_code} · needed by ${formatDate(data.required_by)}`}
            actions={
              !data.provisional && (
                <Button variant="outline" onClick={() => window.print()}>
                  <Printer className="h-4 w-4" />
                  Print form
                </Button>
              )
            }
          />
          {data.provisional && <ProvisionalFormNotice version={data.form_version} />}
          <ReportStatus status={data.status} label={data.status_label} />
          <div className="grid gap-4 lg:grid-cols-3">
            <RequisitionDetails requisition={data} attachmentEndpoint={`/coordinator/requisitions/${data.id}/attachment`} />
            <RequisitionProgress requisition={data} />
          </div>
        </div>
        {!data.provisional && <RequisitionPrintSheet requisition={data} />}
      </>
    )
  }

  return <RequisitionForm existing={data} options={options.data} onSaved={requisition.refetch} />
}

/**
 * The same rules the server applies, in the same words, so a missing field is
 * pointed out before anything is sent. The server checks again regardless.
 */
export function validateRequisition(form) {
  const errors = {}
  const total = Number(form.workers_needed)
  const positionPicked = form.job_position_id && form.job_position_id !== 'new'

  if (!form.date_requested) errors.date_requested = ['Enter the Date requested.']
  else if (form.date_requested > todayISO()) errors.date_requested = ['The Date requested cannot be in the future.']

  if (!form.required_by) errors.required_by = ['Enter the Date Needed.']
  else if (form.date_requested && form.required_by < form.date_requested) {
    errors.required_by = ['The Date Needed cannot be before the Date requested.']
  }

  if (form.workers_needed === '') errors.workers_needed = ['Enter the Total Manpower Needed.']
  else if (!Number.isInteger(total) || total < 1) errors.workers_needed = ['The Total Manpower Needed must be at least 1.']

  if (!positionPicked && !form.position_title.trim()) errors.position_title = ['Say which position the manpower is for.']

  if (Number(form.male_count || 0) + Number(form.female_count || 0) > total) {
    errors.male_count = ['The numbers of men and women add up to more than the Total Manpower Needed.']
  }

  if (!form.request_reason) errors.request_reason = ['Tick the Reason of Request.']
  else if (form.request_reason === 'others' && !form.request_reason_details.trim()) {
    errors.request_reason_details = ['Write the reason beside "Others".']
  }

  return errors
}

function RequisitionForm({ existing, options, onSaved }) {
  const { user } = useAuth()
  const toast = useToast()
  const navigate = useNavigate()
  const reasons = options?.form?.reasons ?? []

  const [form, setForm] = React.useState(() => ({
    date_requested: existing?.date_requested ?? todayISO(),
    required_by: existing?.required_by ?? '',
    workers_needed: existing?.workers_needed ? String(existing.workers_needed) : '1',
    male_count: existing?.male_count != null ? String(existing.male_count) : '',
    female_count: existing?.female_count != null ? String(existing.female_count) : '',
    job_position_id: existing?.job_position_id ? String(existing.job_position_id) : '',
    position_title: existing?.position_title ?? '',
    qualifications: existing?.qualifications ?? '',
    other_remarks: existing?.other_remarks ?? '',
    request_reason: existing?.request_reason ?? '',
    request_reason_details: existing?.request_reason_details ?? '',
    client_department_id: existing?.client_department_id ? String(existing.client_department_id) : '',
  }))
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(null)
  const [confirmSubmit, setConfirmSubmit] = React.useState(false)
  const [confirmDiscard, setConfirmDiscard] = React.useState(false)

  const set = (key) => (event) => setForm((f) => ({ ...f, [key]: event.target.value }))
  const positionPicked = form.job_position_id && form.job_position_id !== 'new'
  const positionTitle = positionPicked
    ? options?.positions?.find((p) => String(p.id) === form.job_position_id)?.position_title
    : form.position_title

  function checked() {
    const found = validateRequisition(form)
    setErrors(found)
    if (Object.keys(found).length) {
      toast.error('Some fields need attention', 'Check the fields marked below.')
      return false
    }
    return true
  }

  async function save(submit) {
    setSaving(submit ? 'submit' : 'draft')
    setErrors({})

    const number = (value) => (value === '' ? null : Number(value))
    const text = (value) => value.trim() || null

    // Empty fields go as null, so clearing one on a returned requisition
    // really clears it.
    const body = {
      date_requested: form.date_requested,
      required_by: form.required_by,
      workers_needed: number(form.workers_needed),
      male_count: number(form.male_count),
      female_count: number(form.female_count),
      job_position_id: positionPicked ? Number(form.job_position_id) : null,
      position_title: positionPicked ? null : text(form.position_title),
      qualifications: text(form.qualifications),
      other_remarks: text(form.other_remarks),
      request_reason: form.request_reason,
      request_reason_details: REASONS_WITH_DETAILS.includes(form.request_reason) ? text(form.request_reason_details) : null,
      client_department_id: number(form.client_department_id),
      submit,
    }

    try {
      const response = await post(existing ? `/coordinator/requisitions/${existing.id}` : '/coordinator/requisitions', body)
      toast.success(response.message, submit ? 'HR has been notified.' : 'You can come back to it before submitting.')
      setConfirmSubmit(false)

      if (existing) onSaved()
      else navigate(`/coordinator/requisitions/${response.data.id}`, { replace: true })
    } catch (error) {
      setConfirmSubmit(false)
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not save the requisition', error.message)
    } finally {
      setSaving(null)
    }
  }

  async function discard() {
    try {
      await api.delete(`/coordinator/requisitions/${existing.id}`)
      toast.success('Draft discarded')
      navigate('/coordinator/requisitions', { replace: true })
    } catch (error) {
      toast.error('Could not discard the draft', error.message)
    }
  }

  const err = (key) => errors[key]?.[0]

  return (
    <div className="space-y-5">
      <PageHeader
        breadcrumbs={[
          { label: 'Manpower Requisitions', to: '/coordinator/requisitions' },
          { label: existing?.requisition_code ?? 'New requisition' },
        ]}
        title={existing ? `Requisition ${existing.requisition_code}` : 'New manpower requisition'}
        description="What your client is asking CDE for. HR reviews it before any recruiting starts."
      />

      {existing?.status === 'returned' && (
        <div className="flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-sm">
          <CornerUpLeft className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
          <p>
            HR returned this requisition for correction
            {existing.reviewed_by && ` (${existing.reviewed_by})`}: <span className="font-medium">{existing.review_note}</span>
          </p>
        </div>
      )}

      {existing?.provisional && (
        <div role="note" className="space-y-3 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-sm">
          <p className="flex items-start gap-2.5">
            <Info className="mt-0.5 h-4 w-4 shrink-0 text-warning" aria-hidden="true" />
            <span>
              <span className="font-semibold">Started on the temporary default form.</span> Complete it on CDE's form below
              before submitting. What the temporary form recorded stays on the requisition.
            </span>
          </p>
          <TemporaryFormFields
            requisition={existing}
            attachmentEndpoint={`/coordinator/requisitions/${existing.id}/attachment`}
            className="bg-card"
          />
        </div>
      )}

      <Card>
        <CardContent className="space-y-6 pt-5">
          <div className="flex flex-wrap items-center gap-3 border-b pb-4">
            <LogoMark size="md" />
            <div className="min-w-0">
              <p className="text-xs text-muted-foreground">CDE Manpower Services</p>
              <h2 className="text-base font-semibold">{options?.form?.title ?? 'Manpower Requisition Form'}</h2>
            </div>
            <p className="ml-auto flex items-center gap-1.5 text-sm text-muted-foreground">
              <Lock className="h-3 w-3" aria-label="Set automatically" />
              {user?.coordinator?.company_name ?? existing?.company_name ?? '—'}
            </p>
          </div>

          <div className="grid gap-6 lg:grid-cols-3">
            <div className="space-y-4 lg:col-span-2">
              <FormGrid>
                <Field label="Date requested" htmlFor="date_requested" required error={err('date_requested')}>
                  <Input type="date" value={form.date_requested} max={todayISO()} onChange={set('date_requested')} />
                </Field>
                <Field label="Date Needed" htmlFor="required_by" required error={err('required_by')}>
                  <Input type="date" value={form.required_by} min={form.date_requested || undefined} onChange={set('required_by')} />
                </Field>
              </FormGrid>

              {/* One line on paper ("1 male for SC Sealer"): how many, of whom
                  how many men and women, and for what position. */}
              <fieldset className="space-y-4 rounded-md border p-3">
                <legend className="px-1 text-sm font-semibold">Total Manpower Needed</legend>
                <FormGrid>
                  <Field label="Total Manpower Needed" htmlFor="workers_needed" required error={err('workers_needed')}>
                    <NumberInput min="1" max="1000" step="1" value={form.workers_needed} onChange={set('workers_needed')} />
                  </Field>
                  <Field label="For which position" htmlFor="job_position_id" required error={err('job_position_id')}>
                    <Select value={form.job_position_id} onChange={set('job_position_id')}>
                      <option value="">{options?.positions?.length ? 'Choose a position' : 'Name the position below'}</option>
                      {(options?.positions ?? []).map((p) => (
                        <option key={p.id} value={String(p.id)}>
                          {p.position_title}
                        </option>
                      ))}
                      <option value="new">Other — not listed</option>
                    </Select>
                  </Field>
                  {!positionPicked && (
                    <Field label="Position title" htmlFor="position_title" required error={err('position_title')} className="sm:col-span-2">
                      <Input value={form.position_title} onChange={set('position_title')} maxLength={150} placeholder="e.g. SC Sealer" />
                    </Field>
                  )}
                  <Field
                    label="Of whom male"
                    htmlFor="male_count"
                    error={err('male_count')}
                    hint="If the client said how many men."
                  >
                    <NumberInput min="0" max="1000" step="1" value={form.male_count} onChange={set('male_count')} />
                  </Field>
                  <Field
                    label="Of whom female"
                    htmlFor="female_count"
                    error={err('female_count')}
                    hint="If the client said how many women."
                  >
                    <NumberInput min="0" max="1000" step="1" value={form.female_count} onChange={set('female_count')} />
                  </Field>
                </FormGrid>
              </fieldset>

              <Field label="Required Qualification(s)" htmlFor="qualifications" error={err('qualifications')}>
                <Textarea
                  rows={3}
                  maxLength={2000}
                  value={form.qualifications}
                  onChange={set('qualifications')}
                  placeholder={'e.g. At least 5\'2" in height; can work standing for long periods; capable of heavy lifting.'}
                />
              </Field>

              <Field label="Other Remarks" htmlFor="other_remarks" error={err('other_remarks')}>
                <Textarea rows={2} maxLength={2000} value={form.other_remarks} onChange={set('other_remarks')} />
              </Field>
            </div>

            <fieldset
              className="space-y-3 lg:border-l lg:pl-6"
              aria-describedby={err('request_reason') ? 'request_reason-error' : undefined}
            >
              <legend className="mb-1 text-sm font-semibold">
                Reason of Request <span className="text-destructive" aria-hidden="true">*</span>
              </legend>
              {reasons.map((reason) => (
                <div key={reason.value} className="space-y-2">
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="radio"
                      name="request_reason"
                      value={reason.value}
                      checked={form.request_reason === reason.value}
                      onChange={set('request_reason')}
                      className="accent-primary"
                    />
                    {reason.label}
                  </label>
                  {form.request_reason === reason.value && reason.value === 'replacement' && (
                    <Field
                      label="Replacement of"
                      htmlFor="request_reason_details"
                      error={err('request_reason_details')}
                      hint="Whom the new worker replaces, if known."
                      className="pl-6"
                    >
                      <Input value={form.request_reason_details} onChange={set('request_reason_details')} maxLength={190} />
                    </Field>
                  )}
                  {form.request_reason === reason.value && reason.value === 'others' && (
                    <Field label="Others" htmlFor="request_reason_details" required error={err('request_reason_details')} className="pl-6">
                      <Input value={form.request_reason_details} onChange={set('request_reason_details')} maxLength={190} />
                    </Field>
                  )}
                </div>
              ))}
              {err('request_reason') && (
                <p id="request_reason-error" className="text-xs font-medium text-destructive">
                  {err('request_reason')}
                </p>
              )}
            </fieldset>
          </div>

          <FormGrid className="border-t pt-4">
            <div className="space-y-1.5">
              <p className="flex items-center gap-1.5 text-sm font-medium">
                Requested by
                <Lock className="h-3 w-3 text-muted-foreground" aria-label="Set automatically" />
              </p>
              <div className="flex h-11 items-center rounded-control border bg-muted/40 px-3 text-sm text-muted-foreground">
                {existing?.requested_by?.name ?? user?.full_name}
              </div>
            </div>
            <div className="space-y-1.5">
              <p className="flex items-center gap-1.5 text-sm font-medium">
                Acknowledged by
                <Lock className="h-3 w-3 text-muted-foreground" aria-label="Set automatically" />
              </p>
              <div className="flex h-11 items-center rounded-control border bg-muted/40 px-3 text-sm text-muted-foreground">
                CDE, when HR approves it
              </div>
            </div>
          </FormGrid>

          {/* Not on CDE's form. HR needs a department to record the request,
              so it helps when the coordinator already knows it. */}
          <div className="space-y-3 border-t pt-4">
            <div>
              <h3 className="text-sm font-semibold">For EMPOWER</h3>
              <p className="text-xs text-muted-foreground">Not on CDE's form. Optional.</p>
            </div>
            <FormGrid>
              <Field label="Department, if known" htmlFor="client_department_id" error={err('client_department_id')}>
                <Select value={form.client_department_id} onChange={set('client_department_id')} placeholder="Not one department">
                  {(options?.departments ?? []).map((d) => (
                    <option key={d.id} value={String(d.id)}>
                      {d.department_name}
                    </option>
                  ))}
                </Select>
              </Field>
            </FormGrid>
          </div>

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
              <Button variant="outline" onClick={() => checked() && save(false)} disabled={saving !== null}>
                {saving === 'draft' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                Save draft
              </Button>
              <Button onClick={() => checked() && setConfirmSubmit(true)} disabled={saving !== null}>
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
        title="Submit this requisition to HR?"
        description="HR is notified and reviews it. Submitting does not approve it. You cannot change it unless HR returns it to you."
        details={[
          ['Total Manpower Needed', manpowerSummary({ ...form, position_title: positionTitle })],
          ['Date Needed', formatDate(form.required_by)],
          ['Reason of Request', reasons.find((r) => r.value === form.request_reason)?.label],
        ]}
        confirmLabel="Submit requisition"
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
