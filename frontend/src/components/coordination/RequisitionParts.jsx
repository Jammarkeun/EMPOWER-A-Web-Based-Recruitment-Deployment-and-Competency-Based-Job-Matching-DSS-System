import * as React from 'react'
import { Link } from 'react-router-dom'
import { FileText, Info, History } from 'lucide-react'
import { get } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { StatusBadge } from '@/components/ui/badge'
import { CDE_LETTERHEAD, CdeLetterhead, SignatureLine } from '@/components/CdeDocument'
import { ReportStatus } from '@/pages/coordinator/CoordinatorEmployeeDetail'
import { cn, formatDate, formatDateTime } from '@/lib/utils'

/** "Reason of Request", in the order the form lists the boxes. */
const REASON_ORDER = [
  ['new_position', 'New Position'],
  ['replacement', 'Replacement'],
  ['additional_manpower', 'Additional Manpower'],
  ['reliever_manpower', 'Reliever Manpower'],
  ['others', 'Others'],
]

/**
 * "Total Manpower Needed" as one line, the way CDE's own sample reads ("1 male
 * for SC Sealer"): how many, how many men and women when given, and for what.
 */
export function manpowerSummary(r) {
  const split = [
    r.male_count ? `${r.male_count} male` : null,
    r.female_count ? `${r.female_count} female` : null,
  ].filter(Boolean)

  return `${r.workers_needed}${split.length ? ` (${split.join(', ')})` : ''} for ${r.position_title}`
}

/** The ticked reason with what was written beside it. */
export function reasonText(r) {
  if (!r.request_reason) return null
  if (!r.request_reason_details) return r.request_reason_label
  return r.request_reason === 'others'
    ? `${r.request_reason_label}: ${r.request_reason_details}`
    : `${r.request_reason_label} of ${r.request_reason_details}`
}

/**
 * Shown on a requisition filed before CDE's own form arrived, so nobody reads
 * the temporary form's fields as CDE's.
 */
export function ProvisionalFormNotice({ version }) {
  return (
    <div
      role="note"
      className="flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-sm"
    >
      <Info className="mt-0.5 h-4 w-4 shrink-0 text-warning" aria-hidden="true" />
      <div>
        <p className="font-semibold">Filed on the temporary default form</p>
        <p className="text-muted-foreground">
          This requisition was written before CDE's Manpower Requisition Form was received, so it shows the fields the
          temporary form had. Nothing it recorded has been changed.
          {version && <span className="font-mono text-xs"> ({version})</span>}
        </p>
      </div>
    </div>
  )
}

/** Opens a requisition's attachment - only ever added on the temporary form. */
function AttachmentButton({ requisition, endpoint }) {
  const toast = useToast()

  async function open() {
    try {
      const response = await get(endpoint)
      if (!window.open(response.data.url, '_blank', 'noopener')) {
        toast.warning('Your browser blocked the document', 'Allow pop-ups for this site, then try again.')
      }
    } catch (err) {
      toast.error('Could not open the attachment', err.message)
    }
  }

  return (
    <Button variant="outline" size="sm" onClick={open}>
      <FileText className="h-3.5 w-3.5" />
      {requisition.attachment_name ?? 'Attachment'}
    </Button>
  )
}

/**
 * What the temporary default form recorded beyond CDE's fields. Only the ones
 * that hold something are listed. Once a requisition is on CDE's form, its
 * numbers of men and women belong to "Total Manpower Needed" instead.
 */
export function temporaryFormRows(r) {
  const gender =
    r.provisional && r.gender_requirement === 'as_specified' && (r.male_count != null || r.female_count != null)
      ? `${r.gender_requirement_label}: ${r.male_count ?? 0} male, ${r.female_count ?? 0} female`
      : r.gender_requirement_label

  return [
    ['Reason for request', r.reason],
    ['Gender requirement', gender],
    ['Employment type', r.employment_type_label],
    ['Work location', r.work_location],
    ['Work schedule or shift', r.work_schedule],
    ['Salary range or compensation notes', r.compensation_notes],
    ['Additional instructions', r.additional_instructions],
    ['Requested by (client contact)', r.client_requester_name],
  ].filter(([, value]) => value !== null && value !== undefined && value !== '')
}

export function TemporaryFormFields({ requisition, attachmentEndpoint, className }) {
  const rows = temporaryFormRows(requisition)
  if (!rows.length && !requisition.has_attachment) return null

  return (
    <div className={cn('space-y-2 rounded-md border border-dashed p-3 text-sm', className)}>
      <p className="text-xs font-medium text-muted-foreground">Recorded earlier on the temporary default form</p>
      <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
        {rows.map(([label, value]) => (
          <div key={label}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="whitespace-pre-line">{value}</dd>
          </div>
        ))}
      </dl>
      {requisition.has_attachment && attachmentEndpoint && (
        <AttachmentButton requisition={requisition} endpoint={attachmentEndpoint} />
      )}
    </div>
  )
}

/** Everything the requisition recorded, in the order of the form it was written on. */
export function RequisitionDetails({ requisition, attachmentEndpoint }) {
  return requisition.provisional ? (
    <TemporaryFormDetails requisition={requisition} attachmentEndpoint={attachmentEndpoint} />
  ) : (
    <OfficialFormDetails requisition={requisition} attachmentEndpoint={attachmentEndpoint} />
  )
}

function Item({ label, children, wide }) {
  return (
    <div className={wide ? 'sm:col-span-2' : undefined}>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="whitespace-pre-line">{children}</dd>
    </div>
  )
}

function OfficialFormDetails({ requisition: r, attachmentEndpoint }) {
  return (
    <Card className="lg:col-span-2">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Manpower Requisition Form</CardTitle>
        <CardDescription>CDE Manpower Services</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4 text-sm">
        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
          <Item label="Date requested">{formatDate(r.date_requested)}</Item>
          <Item label="Date Needed">{formatDate(r.required_by)}</Item>
          <Item label="Total Manpower Needed" wide>
            {manpowerSummary(r)}
          </Item>
          <Item label="Required Qualification(s)" wide>
            {r.qualifications || <span className="text-muted-foreground">None written</span>}
          </Item>
          <Item label="Other Remarks" wide>
            {r.other_remarks || <span className="text-muted-foreground">None written</span>}
          </Item>
          <Item label="Reason of Request" wide>
            {reasonText(r) ?? '—'}
          </Item>
          <Item label="Requested by">{r.requested_by?.name ?? '—'}</Item>
          <Item label="Acknowledged by">
            {r.acknowledged_by ? (
              <>
                {r.acknowledged_by.name}
                <span className="text-xs text-muted-foreground"> · {formatDateTime(r.acknowledged_by.at)}</span>
              </>
            ) : (
              <span className="text-muted-foreground">Recorded when HR approves it</span>
            )}
          </Item>
        </dl>

        {/* EMPOWER's own context, not fields on CDE's form. */}
        <dl className="grid gap-x-6 gap-y-3 border-t pt-3 sm:grid-cols-2">
          <Item label="Client company">{r.company_name ?? '—'}</Item>
          <Item label="Department (EMPOWER)">{r.department_name ?? 'Not specified'}</Item>
        </dl>

        <TemporaryFormFields requisition={r} attachmentEndpoint={attachmentEndpoint} />
      </CardContent>
    </Card>
  )
}

/** A requisition filed on the temporary default form, as that form laid it out. */
function TemporaryFormDetails({ requisition: r, attachmentEndpoint }) {
  const rows = [
    ['Client company', r.company_name],
    ['Department', r.department_name ?? 'Not specified'],
    ['Position', r.position_title],
    ['Number of personnel needed', r.workers_needed],
    ['Date of request', formatDate(r.date_requested)],
    ['Required deployment date', formatDate(r.required_by)],
    ['Filed by (coordinator)', r.requested_by?.name],
  ].filter(([, value]) => value !== null && value !== undefined && value !== '')

  const longRows = [['Required qualifications / skills', r.qualifications]].filter(([, value]) => value)

  return (
    <Card className="lg:col-span-2">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">The requisition</CardTitle>
        <CardDescription>Written on the temporary default form ({r.form_version}).</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3 text-sm">
        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
          {rows.map(([label, value]) => (
            <div key={label}>
              <dt className="text-xs text-muted-foreground">{label}</dt>
              <dd>{value}</dd>
            </div>
          ))}
          {temporaryFormRows(r)
            .filter(([label]) => label !== 'Reason for request')
            .map(([label, value]) => (
              <div key={label}>
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd>{value}</dd>
              </div>
            ))}
        </dl>
        {[['Reason for request', r.reason], ...longRows]
          .filter(([, value]) => value)
          .map(([label, value]) => (
            <div key={label}>
              <p className="text-xs text-muted-foreground">{label}</p>
              <p className="whitespace-pre-line">{value}</p>
            </div>
          ))}
        {r.has_attachment && attachmentEndpoint && <AttachmentButton requisition={r} endpoint={attachmentEndpoint} />}
      </CardContent>
    </Card>
  )
}

/**
 * The requisition laid out like CDE's paper form, for printing and signing.
 *
 * Hidden on screen; the page around it is marked no-print. Only offered for
 * requisitions written on CDE's form - printing a temporary-form one on CDE's
 * layout would put fields on it that CDE never asked for. The signature lines
 * carry the names EMPOWER recorded; signing them is still done on paper.
 */
export function RequisitionPrintSheet({ requisition: r }) {
  const line = 'border-b border-black'

  return (
    <div className="hidden bg-white text-[11pt] text-black print:block">
      <div className="border-2 border-black">
        <CdeLetterhead title="Manpower Requisition Form" className="border-b-2 border-black" />

        <div className="grid grid-cols-[2fr_1fr]">
          <div className="border-r-2 border-black">
            <p className={cn('px-3 py-1.5', line)}>
              Date requested: <span className="ml-2">{formatDate(r.date_requested)}</span>
            </p>
            <p className={cn('px-3 py-1.5', line)}>
              Date Needed: <span className="ml-2">{formatDate(r.required_by)}</span>
            </p>
            <p className={cn('min-h-[3.5rem] px-3 py-1.5', line)}>
              Total Manpower Needed: <span className="ml-2">{manpowerSummary(r)}</span>
            </p>
            <p className={cn('min-h-[5rem] whitespace-pre-line px-3 py-1.5', line)}>
              Required Qualification(s): <span className="ml-2">{r.qualifications}</span>
            </p>
            <p className={cn('min-h-[5rem] whitespace-pre-line px-3 py-1.5', line)}>
              Other Remarks: <span className="ml-2">{r.other_remarks}</span>
            </p>

            <div className="space-y-8 px-3 pb-4 pt-10">
              <SignatureLine label="Requested by:" name={r.requested_by?.name} />
              <SignatureLine label="Acknowledged by:" name={r.acknowledged_by?.name} note={CDE_LETTERHEAD.agency} />
            </div>
          </div>

          <div className="px-4 py-3">
            <p className="mb-4 text-center font-bold">Reason of Request</p>
            <ul className="space-y-4">
              {REASON_ORDER.map(([value, label]) => {
                const ticked = r.request_reason === value
                return (
                  <li key={value} className="flex items-end gap-1.5">
                    <span className={cn('inline-block w-6 text-center leading-none', line)}>{ticked ? '✓' : ''}</span>
                    <span>
                      {label}
                      {value === 'others' && ':'}
                    </span>
                    {ticked && r.request_reason_details && (
                      <span className={value === 'others' ? cn('flex-1', line) : undefined}>
                        {value === 'others' ? r.request_reason_details : `of ${r.request_reason_details}`}
                      </span>
                    )}
                    {value === 'others' && !(ticked && r.request_reason_details) && <span className={cn('flex-1', line)} />}
                  </li>
                )
              })}
            </ul>
          </div>
        </div>
      </div>

      <p className="mt-2 text-[8pt] text-neutral-600">
        {r.requisition_code} · {r.company_name}
        {r.department_name ? ` · ${r.department_name}` : ''} · printed from EMPOWER {formatDateTime(new Date().toISOString())}
      </p>
    </div>
  )
}

/** Where it stands with HR, every step it has taken, and the request it became. */
export function RequisitionProgress({ requisition, requestLink }) {
  const r = requisition

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <History className="h-4 w-4" /> With HR
        </CardTitle>
        <CardDescription>Submitting approves nothing. HR reviews every requisition.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4 text-sm">
        {r.review_note && (
          <p>
            <span className="text-xs text-muted-foreground">HR's latest note</span>
            <br />
            {r.review_note}
          </p>
        )}

        {r.job_request && (
          <div className="rounded-md border border-status-success-border bg-status-success-bg/40 p-3">
            <p className="font-medium">Recorded as a manpower request</p>
            <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
              {requestLink ? (
                <Link to={`/job-requests/${r.job_request.id}`} className="font-mono text-primary hover:underline">
                  {r.job_request.request_code}
                </Link>
              ) : (
                <span className="font-mono">{r.job_request.request_code}</span>
              )}
              <StatusBadge status={r.job_request.request_status} />
              <span>
                {r.job_request.workers_fulfilled} of {r.job_request.workers_needed} deployed
              </span>
            </div>
          </div>
        )}

        <ol className="space-y-2 border-l pl-4">
          {(r.history ?? []).map((h) => (
            <li key={h.id} className="relative">
              <span className="absolute -left-[21px] top-1.5 h-2 w-2 rounded-full bg-brand" aria-hidden="true" />
              <div className="flex flex-wrap items-center gap-2">
                <ReportStatus status={h.to_status} label={h.to_label} />
                <span className="text-xs text-muted-foreground">
                  {formatDateTime(h.changed_at)}
                  {h.changed_by && ` · ${h.changed_by}`}
                </span>
              </div>
              {h.note && <p className="mt-1 text-xs">{h.note}</p>}
            </li>
          ))}
        </ol>
      </CardContent>
    </Card>
  )
}
