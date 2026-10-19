import * as React from 'react'
import { Loader2, Briefcase } from 'lucide-react'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Field } from '@/components/ui/form'
import { Textarea } from '@/components/ui/textarea'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { formatDate, formatDateTime, humanise } from '@/lib/utils'

/**
 * The placement in progress and the applicant's own answer to it (R-05, R-07).
 *
 * Each answer belongs to one endorsement: a decline stops that placement and
 * no other, and a new endorsement asks again. The office can correct an
 * answer - with a reason - but the original stays in the history beside it.
 */
function PlacementPanel({ applicant, placement, canCorrect, onChanged }) {
  const [correcting, setCorrecting] = React.useState(false)

  if (!placement) return null

  const withClient = ['client_evaluation', 'approved'].includes(applicant.current_status)
  if (!withClient && !placement.endorsement) return null

  const {
    endorsement,
    current_decision: current,
    decisions = [],
    client_approval: approval,
    decision_due: due,
    approval_required_before_deployment: approvalRequired,
  } = placement
  const request = endorsement?.job_request

  return (
    <Card className="mt-5">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Briefcase className="h-4 w-4 text-muted-foreground" />
          Placement
        </CardTitle>
        <CardDescription>
          The endorsement in progress and the applicant's answer to it. A decline stops this placement only.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-3">
          <PlacementFact label="Endorsed for">
            {request ? `${request.position_title} · ${request.company_name ?? '—'} (${request.request_code})` : 'No specific request named'}
          </PlacementFact>
          <PlacementFact label="Endorsed">
            {endorsement?.endorsed_at
              ? `${formatDate(endorsement.endorsed_at)}${endorsement.endorsed_by ? ` by ${endorsement.endorsed_by}` : ''}`
              : 'Not recorded'}
          </PlacementFact>
          <PlacementFact label="Client approval">
            {approval
              ? `Recorded ${formatDate(approval.recorded_at)}${approval.recorded_by ? ` by ${approval.recorded_by}` : ''}`
              : applicant.current_status === 'approved'
                ? 'Not recorded'
                : 'Awaiting the client'}
          </PlacementFact>
        </div>

        {endorsement?.recorded_retroactively && endorsement.note && (
          <p className="text-xs text-muted-foreground">{endorsement.note}</p>
        )}

        <div className="rounded-row bg-inset p-3">
          <p className="text-xs font-medium text-muted-foreground">Applicant's answer</p>
          {due ? (
            <p className="mt-1 text-sm">Not answered yet. The applicant answers on their portal.</p>
          ) : current ? (
            <>
              <div className="mt-1 flex flex-wrap items-center gap-2">
                <Badge tone={current.decision === 'accepted' ? 'success' : 'destructive'}>
                  {current.decision === 'accepted' ? 'Accepted' : 'Declined'}
                </Badge>
                <span className="text-xs text-muted-foreground">
                  {current.source === 'staff' ? `Corrected by ${current.recorded_by ?? 'the office'}` : 'Given on the portal'}
                  {current.decided_at && ` · ${formatDateTime(current.decided_at)}`}
                </span>
              </div>
              {current.note && <p className="mt-1 text-sm">"{current.note}"</p>}
              {current.decision === 'declined' && (
                <p className="mt-2 text-xs text-status-danger-text">
                  This placement cannot be deployed unless the applicant changes their mind and the office records it.
                </p>
              )}
            </>
          ) : (
            <p className="mt-1 text-sm text-muted-foreground">No answer recorded.</p>
          )}
        </div>

        {decisions.length > 1 && (
          <div>
            <p className="text-xs font-medium text-muted-foreground">Answers for this placement</p>
            <ol className="mt-2 space-y-1.5">
              {decisions.map((decision) => (
                <li key={decision.id} className="text-xs text-muted-foreground">
                  <span className="font-medium text-foreground">{humanise(decision.decision)}</span>
                  {decision.decided_at && ` · ${formatDateTime(decision.decided_at)}`}
                  {decision.source === 'staff' ? ` · recorded by ${decision.recorded_by ?? 'the office'}` : ' · on the portal'}
                  {decision.correction_reason && ` · correction: ${decision.correction_reason}`}
                </li>
              ))}
            </ol>
          </div>
        )}

        {approvalRequired && (
          <p className="text-xs text-muted-foreground">
            CDE requires the client's approval to be recorded before this applicant can be deployed.
          </p>
        )}

        {canCorrect && current && (
          <Button variant="outline" size="sm" onClick={() => setCorrecting(true)}>
            Correct the answer
          </Button>
        )}
      </CardContent>

      <CorrectionDialog
        applicantId={applicant.id}
        current={current}
        open={correcting}
        onOpenChange={setCorrecting}
        onCorrected={onChanged}
      />
    </Card>
  )
}

function PlacementFact({ label, children }) {
  return (
    <div className="rounded-row bg-inset p-3">
      <p className="text-xs font-medium text-muted-foreground">{label}</p>
      <p className="mt-1 text-sm">{children}</p>
    </div>
  )
}

/**
 * Records a change of mind the applicant reported to the office. The first
 * answer stays theirs to give; a correction needs a reason and never replaces
 * the answer it corrects.
 */
function CorrectionDialog({ applicantId, current, open, onOpenChange, onCorrected }) {
  const toast = useToast()
  const [decision, setDecision] = React.useState('accepted')
  const [reason, setReason] = React.useState('')
  const [note, setNote] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)

  React.useEffect(() => {
    if (open) {
      setDecision(current?.decision === 'accepted' ? 'declined' : 'accepted')
      setReason('')
      setNote('')
      setErrors({})
    }
  }, [open, current])

  async function submit(event) {
    event.preventDefault()
    setSubmitting(true)
    setErrors({})

    try {
      const response = await post(`/applicants/${applicantId}/placement-decisions`, {
        decision,
        correction_reason: reason,
        ...(note.trim() ? { note: note.trim() } : {}),
      })
      toast.success('Answer corrected', 'The earlier answer stays on record.')
      onCorrected(response.data)
      onOpenChange(false)
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Not corrected', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Correct the applicant's answer</DialogTitle>
          <DialogDescription>
            For a change of mind the applicant told the office about. The answer on record now is "
            {current?.decision === 'accepted' ? 'accepted' : 'declined'}"; it stays in the history.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-4" noValidate>
          <div role="radiogroup" aria-label="New answer" className="flex gap-2">
            {['accepted', 'declined'].map((option) => (
              <Button
                key={option}
                type="button"
                role="radio"
                aria-checked={decision === option}
                variant={decision === option ? 'default' : 'outline'}
                size="sm"
                onClick={() => setDecision(option)}
              >
                {option === 'accepted' ? 'Accepts the placement' : 'Declines the placement'}
              </Button>
            ))}
          </div>
          {errors.decision?.[0] && <p className="text-xs font-medium text-destructive">{errors.decision[0]}</p>}

          <Field label="Reason for the correction" htmlFor="correction_reason" required error={errors.correction_reason?.[0]}>
            <Textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. Called the office on 16 October to say transport is now arranged."
              rows={3}
            />
          </Field>

          <Field label="The applicant's own words (optional)" htmlFor="correction_note" error={errors.note?.[0]}>
            <Input value={note} onChange={(e) => setNote(e.target.value)} />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting || !reason.trim()}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Record correction
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

export default PlacementPanel
