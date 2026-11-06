import * as React from 'react'
import { useParams } from 'react-router-dom'
import { Loader2, Eye, CornerUpLeft, CheckCircle2, Ban, PackageCheck, ClipboardList, Printer } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { get, post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Field } from '@/components/ui/form'
import { Select } from '@/components/ui/select'
import { LoadingState, ErrorState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  ProvisionalFormNotice,
  RequisitionDetails,
  RequisitionPrintSheet,
  RequisitionProgress,
} from '@/components/coordination/RequisitionParts'
import { ReportStatus } from './coordinator/CoordinatorEmployeeDetail'
import { useUnsavedChanges } from '@/components/UnsavedChanges'
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
    description:
      'Approving creates the manpower request from this requisition straight away. You then set its matching criteria on the request.',
    converts: true,
  },
  convert: {
    label: 'Create manpower request',
    icon: ClipboardList,
    variant: 'default',
    title: 'Create the manpower request',
    description: 'This requisition was approved before approval created requests. Its request is made from what the form says.',
    converts: true,
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
            {r.can_convert && (
              <Button onClick={() => setAction('convert')}>
                <ClipboardList className="h-4 w-4" />
                Create manpower request
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
        requisition={r}
        onOpenChange={(open) => !open && setAction(null)}
        onDone={() => {
          setAction(null)
          refetch()
        }}
      />

    </div>
    {!r.provisional && <RequisitionPrintSheet requisition={r} />}
    </>
  )
}

function ReviewDialog({ action, requisition, onOpenChange, onDone }) {
  const toast = useToast()
  const config = action ? ACTIONS[action] : null
  const needs = requisition.conversion ?? {}
  const [note, setNote] = React.useState('')
  const [departmentId, setDepartmentId] = React.useState('')
  const [positionChoice, setPositionChoice] = React.useState('')
  const [departments, setDepartments] = React.useState([])
  const [positions, setPositions] = React.useState([])
  const [errors, setErrors] = React.useState({})
  const [busy, setBusy] = React.useState(false)
  const { guard, prompt } = useUnsavedChanges(Boolean(action) && (note.trim() !== '' || departmentId !== '' || positionChoice !== ''))
  const close = guard(onOpenChange)

  React.useEffect(() => {
    if (!action) return
    setNote('')
    setErrors({})
    setDepartmentId('')
    setPositionChoice('')
    if (!ACTIONS[action]?.converts) return

    // Only what the form leaves open is asked for; the rest comes from it.
    if (needs.needs_department) {
      get(`/clients/${requisition.client_company_id}/departments`)
        .then((response) => setDepartments(response.data.filter((d) => d.status === 'active')))
        .catch((err) => toast.error('Could not load departments', err.message))
    }
    if (needs.needs_position) {
      get('/positions', { client_company_id: requisition.client_company_id, status: 'active' })
        .then((response) => setPositions(response.data.positions ?? []))
        .catch(() => setPositions([]))
    }
  }, [action])

  if (!config) return null

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setErrors({})
    const payload = { note: note || null }
    if (config.converts) {
      if (departmentId) payload.client_department_id = Number(departmentId)
      if (positionChoice === 'new') payload.confirm_new_position = true
      else if (positionChoice) payload.job_position_id = Number(positionChoice)
    }
    try {
      const response =
        action === 'convert'
          ? await post(`/requisitions/${requisition.id}/convert`, payload)
          : await post(`/requisitions/${requisition.id}/review`, { action, ...payload })
      toast.success(response.message, action === 'convert' ? undefined : 'The coordinator has been told.')
      onDone()
    } catch (err) {
      if (err.isValidation) setErrors(err.errors)
      else toast.error('Could not update the requisition', err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !busy && close(open)}>
      <DialogContent className="max-w-md">
        {prompt}
        <DialogHeader>
          <DialogTitle>{config.title}</DialogTitle>
          <DialogDescription>{config.description}</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4" noValidate>
          {config.converts && (
            <div className="rounded-row bg-inset px-3 py-2 text-xs text-muted-foreground">
              The request takes this requisition's client, position, {requisition.workers_needed} worker(s), dates and
              wording. Nothing is typed in again.
            </div>
          )}

          {config.converts && needs.needs_department && (
            <Field
              label="Department"
              htmlFor="conversion_department"
              required
              error={errors.client_department_id?.[0]}
              hint="CDE's form has no department and the coordinator chose none. Pick the client department these workers are for."
            >
              <Select
                id="conversion_department"
                value={departmentId}
                onChange={(e) => setDepartmentId(e.target.value)}
                placeholder="Select a department"
              >
                {departments.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.department_name}
                  </option>
                ))}
              </Select>
            </Field>
          )}

          {config.converts && needs.needs_position && (
            <Field
              label={`Position for "${requisition.position_title}"`}
              htmlFor="conversion_position"
              required
              error={errors.job_position_id?.[0]}
              hint="Not one of this client's positions yet. Pick the one it means, or confirm it is a new position."
            >
              <Select
                id="conversion_position"
                value={positionChoice}
                onChange={(e) => setPositionChoice(e.target.value)}
                placeholder="Select a position"
              >
                <option value="new">
                  New position: {requisition.position_title}
                  {needs.matching_position ? ' (bring back the withdrawn one)' : ''}
                </option>
                {positions.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.position_title}
                  </option>
                ))}
              </Select>
            </Field>
          )}

          {action !== 'convert' && (
            <Field
              label={config.noteRequired ? 'Reason' : 'Note (optional)'}
              htmlFor="requisition_note"
              required={config.noteRequired}
              error={errors.note?.[0]}
            >
              <Textarea value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} />
            </Field>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => close(false)} disabled={busy}>
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
