import * as React from 'react'
import { Loader2 } from 'lucide-react'
import { patch } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
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
 * The two causes CDE named for an application not proceeding, plus "other".
 * Recorded as a category so the agency can count what it loses to each.
 */
export const DISPOSITIONS = [
  { value: 'incomplete_requirements', label: 'Requirements were never completed' },
  { value: 'not_suitable', label: 'Not suitable for the work' },
  { value: 'other', label: 'Other' },
]

/**
 * Closing an application by archiving it, with the reason chosen rather than
 * typed.
 *
 * Replaces a browser prompt that asked the officer to type 1, 2 or 3. The move
 * itself is the ordinary status change: the server checks the archive
 * permission and the transition map, writes the history row and the archive
 * snapshot, and records the disposition in the same transaction. Nothing is
 * deleted; the record stays readable in Archives.
 */
export default function ArchiveApplicantDialog({ open, onOpenChange, applicant, reason, onArchived }) {
  const toast = useToast()
  const [disposition, setDisposition] = React.useState('')
  const [note, setNote] = React.useState('')
  const [error, setError] = React.useState(null)
  const [submitting, setSubmitting] = React.useState(false)

  React.useEffect(() => {
    if (open) {
      setDisposition('')
      setNote('')
      setError(null)
    }
  }, [open])

  async function submit(event) {
    event.preventDefault()
    if (!disposition) {
      setError('Choose why the application is not proceeding.')
      return
    }

    setSubmitting(true)
    try {
      const response = await patch(`/applicants/${applicant.id}/status`, {
        to_status: 'archived',
        disposition_reason: disposition,
        disposition_note: note.trim() || null,
        reason: reason ?? null,
      })
      toast.success('Application archived', `${applicant.full_name}'s record is now in Archives.`)
      onOpenChange(false)
      onArchived?.(response.data)
    } catch (err) {
      toast.error(err.isConflict ? 'This move is not allowed' : 'Not archived', err.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Archive {applicant?.full_name}?</DialogTitle>
          <DialogDescription>
            The application stops here. The record is kept and stays readable in Archives; nothing is deleted.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-4" noValidate>
          <fieldset className="space-y-2">
            <legend className="mb-1 text-sm font-medium">
              Why is this application not proceeding? <span className="text-destructive">*</span>
            </legend>
            {DISPOSITIONS.map((option) => (
              <label key={option.value} className="flex items-center gap-2 text-sm">
                <input
                  type="radio"
                  name="disposition"
                  value={option.value}
                  checked={disposition === option.value}
                  onChange={() => {
                    setDisposition(option.value)
                    setError(null)
                  }}
                />
                {option.label}
              </label>
            ))}
            {error && (
              <p role="alert" className="text-xs font-medium text-destructive">
                {error}
              </p>
            )}
          </fieldset>

          <Field label="Note" htmlFor="disposition_note" hint="Optional. What made this case particular.">
            <Input value={note} maxLength={255} onChange={(e) => setNote(e.target.value)} />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" variant="destructive" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Archive
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
