import * as React from 'react'
import { Loader2 } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { patch } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Select } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Field } from '@/components/ui/form'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

/**
 * Puts the applicant forward to a client. Naming the request is optional -
 * the officer may not know yet - but when named, the applicant's answer on
 * their portal is about that placement.
 */
function EndorseDialog({ applicant, open, onOpenChange, onEndorsed }) {
  const toast = useToast()
  const { data: requests, loading } = useApi('/job-requests', { only_open: 1, per_page: 100 }, { enabled: open })
  const [requestId, setRequestId] = React.useState('none')
  const [reason, setReason] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)

  React.useEffect(() => {
    if (open) {
      setRequestId('none')
      setReason('')
      setErrors({})
    }
  }, [open])

  const options = [
    { value: 'none', label: 'No specific request' },
    ...(requests ?? []).map((request) => ({
      value: String(request.id),
      label: `${request.request_code} · ${request.position_title}${request.company_name ? ` · ${request.company_name}` : ''}`,
    })),
  ]

  async function submit(event) {
    event.preventDefault()
    setSubmitting(true)
    setErrors({})

    try {
      const response = await patch(`/applicants/${applicant.id}/status`, {
        to_status: 'client_evaluation',
        ...(requestId !== 'none' ? { job_request_id: Number(requestId) } : {}),
        ...(reason.trim() ? { reason: reason.trim() } : {}),
      })
      onEndorsed(response.data)
      toast.success('Endorsed to the client', 'The applicant can now give their answer on their portal.')
      onOpenChange(false)
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error(error.isConflict ? 'This move is not allowed' : 'Not endorsed', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Endorse {applicant.full_name} to a client</DialogTitle>
          <DialogDescription>
            Moves the applicant to Client Evaluation. Training must already be recorded as completed.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-4" noValidate>
          <Field
            label="For which request"
            htmlFor="endorse_request"
            hint={loading ? 'Loading open requests…' : 'Only open requests are listed.'}
            error={errors.job_request_id?.[0]}
          >
            <Select options={options} value={requestId} onValueChange={setRequestId} disabled={loading} />
          </Field>

          <Field label="Note (optional)" htmlFor="endorse_reason" error={errors.reason?.[0]}>
            <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Sent with the batch for the night shift." />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Endorse
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

export default EndorseDialog
