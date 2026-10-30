import * as React from 'react'
import { useParams } from 'react-router-dom'
import { Loader2, Eye, CornerUpLeft, CheckCircle2, Ban, PackageCheck, ClipboardList, Printer } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Field } from '@/components/ui/form'
import { LoadingState, ErrorState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import NewJobRequestDialog from '@/components/dialogs/NewJobRequestDialog'
import {
  ProvisionalFormNotice,
  RequisitionDetails,
  RequisitionPrintSheet,
  RequisitionProgress,
  manpowerSummary,
  reasonText,
} from '@/components/coordination/RequisitionParts'
import { ReportStatus } from './coordinator/CoordinatorEmployeeDetail'
import { formatDate, formatDateTime } from '@/lib/utils'

const ACTIONS = {
  start_review: { label: 'Start review', icon: Eye, variant: 'outline' },
  return: {
    label: 'Return',
    icon: CornerUpLeft,
    variant: 'outline',
    title: 'Return to the coordinator',
    description: 'They can correct it and submit it again. Say what needs fixing.',
    noteRequired: true,
  },
  reject: {
    label: 'Reject',
    icon: Ban,
    variant: 'outline',
    title: 'Reject this requisition',
    description: 'CDE will not act on it. It stays on file as rejected, with your reason.',
    noteRequired: true,
  },
  approve: {
    label: 'Approve',
    icon: CheckCircle2,
    variant: 'default',
    title: 'Approve this requisition',
    description: 'Approving starts nothing by itself. Record it as a manpower request next, to set criteria and recruit.',
  },
  fulfil: {
    label: 'Mark fulfilled',
    icon: PackageCheck,
    variant: 'outline',
    title: 'Mark this requisition fulfilled',
    description: 'Use when the client has the people it asked for.',
  },
}

/**
 * What the manpower request keeps of the requisition, as its remarks. On CDE's
 * form that is the reason, the qualifications and the remarks; a temporary-form
 * requisition only ever had its free-text reason.
 */
export function requestRemarks(r) {
  if (r.provisional) return r.reason

  return [
    `Total Manpower Needed: ${manpowerSummary(r)}`,
    `Reason of Request: ${reasonText(r)}`,
    r.qualifications && `Required Qualification(s): ${r.qualifications}`,
    r.other_remarks && `Other Remarks: ${r.other_remarks}`,
  ]
    .filter(Boolean)
    .join('\n')
}

/**
 * HR's review of one coordinator requisition.
 *
 * The buttons offered are exactly the ones the server lists for this
 * requisition and this person - nobody sees "Approve" on one they filed.
 */
export default function RequisitionDetail() {
  const { id } = useParams()
  const toast = useToast()
  const { data: r, loading, error, refetch } = useApi(`/requisitions/${id}`)
  const [action, setAction] = React.useState(null)
  const [busy, setBusy] = React.useState(false)
  const [recording, setRecording] = React.useState(false)

  if (loading) return <LoadingState label="Loading requisition…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!r) return null

  async function quick(name) {
    setBusy(true)
    try {
      const response = await post(`/requisitions/${id}/review`, { action: name })
      toast.success(response.message, 'The coordinator has been told.')
      refetch()
    } catch (err) {
      toast.error('Could not update the requisition', err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
    <div className="no-print space-y-5">
      <PageHeader
        breadcrumbs={[{ label: 'Manpower Requisitions', to: '/requisitions' }, { label: r.requisition_code }]}
        title={`${r.workers_needed} × ${r.position_title}`}
        description={`${r.company_name} · needed by ${formatDate(r.required_by)} · filed by ${r.requested_by?.name} ${formatDateTime(r.submitted_at)}`}
        actions={
          <>
            {!r.provisional && (
              <Button variant="outline" onClick={() => window.print()}>
                <Printer className="h-4 w-4" />
                Print form
              </Button>
            )}
            {(r.review_actions ?? []).map((name) => {
              const config = ACTIONS[name]
              const Icon = config.icon
              return (
                <Button
                  key={name}
                  variant={config.variant}
                  disabled={busy}
                  onClick={() => (name === 'start_review' ? quick(name) : setAction(name))}
                >
                  {busy && name === 'start_review' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Icon className="h-4 w-4" />}
                  {config.label}
                </Button>
              )
            })}
            {r.can_record_request && (
              <Button onClick={() => setRecording(true)}>
                <ClipboardList className="h-4 w-4" />
                Record as manpower request
              </Button>
            )}
          </>
        }
      />

      {r.provisional && <ProvisionalFormNotice version={r.form_version} />}

      <ReportStatus status={r.status} label={r.status_label} />

      <div className="grid gap-4 lg:grid-cols-3">
        <RequisitionDetails requisition={r} attachmentEndpoint={`/requisitions/${r.id}/attachment`} />
        <RequisitionProgress requisition={r} requestLink />
      </div>

      <ReviewDialog
        action={action}
        requisitionId={r.id}
        onOpenChange={(open) => !open && setAction(null)}
        onDone={() => {
          setAction(null)
          refetch()
        }}
      />

      <NewJobRequestDialog
        open={recording}
        onOpenChange={setRecording}
        onCreated={refetch}
        initial={{
          manpower_requisition_id: r.id,
          client_company_id: String(r.client_company_id),
          client_department_id: r.client_department_id ? String(r.client_department_id) : '',
          job_position_id: r.job_position_id ? String(r.job_position_id) : '',
          position_title: r.job_position_id ? '' : r.position_title,
          workers_needed: String(r.workers_needed),
          date_requested: r.date_requested,
          deployment_deadline: r.required_by,
          request_source: `Coordinator requisition ${r.requisition_code}`,
          remarks: requestRemarks(r),
        }}
        sourceLabel={r.requisition_code}
      />
    </div>
    {!r.provisional && <RequisitionPrintSheet requisition={r} />}
    </>
  )
}

function ReviewDialog({ action, requisitionId, onOpenChange, onDone }) {
  const toast = useToast()
  const config = action ? ACTIONS[action] : null
  const [note, setNote] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [busy, setBusy] = React.useState(false)

  React.useEffect(() => {
    if (action) {
      setNote('')
      setErrors({})
    }
  }, [action])

  if (!config) return null

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      const response = await post(`/requisitions/${requisitionId}/review`, { action, note: note || null })
      toast.success(response.message, 'The coordinator has been told.')
      onDone()
    } catch (err) {
      if (err.isValidation) setErrors(err.errors)
      else toast.error('Could not update the requisition', err.message)
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
          <Field
            label={config.noteRequired ? 'Reason' : 'Note (optional)'}
            htmlFor="requisition_note"
            required={config.noteRequired}
            error={errors.note?.[0]}
          >
            <Textarea value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
              Cancel
            </Button>
            <Button type="submit" variant={action === 'reject' ? 'destructive' : 'default'} disabled={busy}>
              {busy && <Loader2 className="h-4 w-4 animate-spin" />}
              {config.label}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
