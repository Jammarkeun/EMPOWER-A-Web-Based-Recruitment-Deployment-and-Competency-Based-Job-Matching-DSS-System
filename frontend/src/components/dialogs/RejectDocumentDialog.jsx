import * as React from 'react'
import { Loader2, XCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Field } from '@/components/ui/form'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useUnsavedChanges } from '@/components/UnsavedChanges'
import { cn } from '@/lib/utils'

/**
 * Asks why a document is being rejected before it is.
 *
 * The reason is shown to the applicant word for word in their portal and
 * notification, and it is the only thing standing between them and a wasted
 * trip to the office - so the form makes a specific reason quick to give
 * rather than leaving a blank box. The common ones are a click away and can be
 * edited after picking.
 */

const MAX_LENGTH = 255

const COMMON_REASONS = [
  { label: 'Blurry or unreadable', text: 'The file is blurry or unreadable. Please upload a clear, well-lit copy.' },
  { label: 'Wrong document', text: 'This is not the requested document. Please upload the correct one.' },
  { label: 'Expired', text: 'This document has expired. Please submit a current copy.' },
  { label: 'Incomplete or cut off', text: 'Part of the document is missing or cut off. Please upload the whole page.' },
  { label: 'Name does not match', text: 'The name on this document does not match your application.' },
]

export default function RejectDocumentDialog({ open, onOpenChange, requirement, applicantName, onConfirm }) {
  const [reason, setReason] = React.useState('')
  const [error, setError] = React.useState(null)
  const [submitting, setSubmitting] = React.useState(false)
  // A reason picked from the list is one click to pick again; only writing
  // is worth asking about before it is thrown away.
  const written = reason.trim() !== '' && !COMMON_REASONS.some((option) => option.text === reason)
  const { guard, prompt } = useUnsavedChanges(open && written)
  const close = guard(onOpenChange)

  React.useEffect(() => {
    if (open) {
      setReason('')
      setError(null)
    }
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()
    const trimmed = reason.trim()

    if (!trimmed) {
      setError('Give the applicant a reason, so they know what to fix.')
      return
    }

    setSubmitting(true)
    const done = await onConfirm(trimmed)
    setSubmitting(false)

    if (done) onOpenChange(false)
  }

  const documentName = requirement?.requirement_name ?? 'this document'

  return (
    <Dialog open={open} onOpenChange={(next) => !submitting && close(next)}>
      <DialogContent>
        {prompt}
        <DialogHeader>
          <DialogTitle>Reject {documentName}</DialogTitle>
          <DialogDescription>
            {applicantName ? `${applicantName} will` : 'The applicant will'} see this reason in their portal and
            be asked to upload a replacement.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <div className="space-y-1.5">
            <p className="text-sm font-medium">Common reasons</p>
            <div className="flex flex-wrap gap-2">
              {COMMON_REASONS.map((option) => {
                const active = reason === option.text

                return (
                  <button
                    key={option.label}
                    type="button"
                    aria-pressed={active}
                    onClick={() => {
                      setReason(option.text)
                      setError(null)
                    }}
                    className={cn(
                      'rounded-badge border px-3 py-1.5 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                      active
                        ? 'border-destructive bg-status-danger-bg text-status-danger-text'
                        : 'border-border bg-card text-foreground hover:bg-inset-selected'
                    )}
                  >
                    {option.label}
                  </button>
                )
              })}
            </div>
          </div>

          <Field
            label="Reason for rejection"
            htmlFor="rejection_reason"
            required
            error={error}
            hint="Pick a reason above or write your own. Be specific about what needs fixing."
          >
            <Textarea
              value={reason}
              onChange={(e) => {
                setReason(e.target.value)
                setError(null)
              }}
              maxLength={MAX_LENGTH}
              rows={4}
              placeholder="e.g. The photo is too dark to read the issue date."
              autoFocus
            />
          </Field>
          <p className="-mt-3 text-right text-xs tabular-nums text-muted-foreground">
            {reason.length}/{MAX_LENGTH}
          </p>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => close(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button type="submit" variant="destructive" disabled={submitting}>
              {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <XCircle className="h-4 w-4" />}
              Reject document
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
