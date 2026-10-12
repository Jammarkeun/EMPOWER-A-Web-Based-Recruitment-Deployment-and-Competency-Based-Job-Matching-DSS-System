import * as React from 'react'
import { AlertTriangle, Loader2 } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

/**
 * The confirmation step in front of an action that cannot be taken back.
 *
 * The review panel asked for a confirmation box on termination, and the reason
 * generalises: ending somebody's employment, completing a resignation, or
 * deactivating a client should never be one stray click. This dialog states
 * who is affected and what will happen, and for the gravest actions asks the
 * person to type a short phrase - the employee number - so the button cannot be
 * pressed by habit.
 *
 * It only gates the request. The server separately refuses the same actions
 * unless the request says it was confirmed, so skipping this screen does not
 * skip the rule.
 */
export default function ConfirmActionDialog({
  open,
  onOpenChange,
  title,
  description,
  details = [],
  consequences = [],
  confirmPhrase,
  confirmPhraseLabel,
  confirmLabel = 'Confirm',
  tone = 'destructive',
  onConfirm,
  children,
}) {
  const [typed, setTyped] = React.useState('')
  const [busy, setBusy] = React.useState(false)

  // Every opening starts unconfirmed, so a previous attempt's typing never
  // carries over to a different record.
  React.useEffect(() => {
    if (open) setTyped('')
  }, [open])

  const phraseMatches = !confirmPhrase || typed.trim().toLowerCase() === String(confirmPhrase).trim().toLowerCase()

  async function handleConfirm() {
    if (!phraseMatches || busy) return

    setBusy(true)
    try {
      await onConfirm()
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !busy && onOpenChange(next)}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <div className="flex items-start gap-3">
            <span
              className={cn(
                'mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full',
                tone === 'destructive'
                  ? 'bg-status-danger-bg text-status-danger-text'
                  : 'bg-status-warning-bg text-status-warning-text'
              )}
            >
              <AlertTriangle className="h-4 w-4" />
            </span>
            <div className="space-y-1">
              <DialogTitle>{title}</DialogTitle>
              {description && <DialogDescription>{description}</DialogDescription>}
            </div>
          </div>
        </DialogHeader>

        {details.length > 0 && (
          <dl className="grid gap-x-4 gap-y-2 rounded-md border bg-muted/30 p-3 text-sm sm:grid-cols-[auto_1fr]">
            {details
              .filter(([, value]) => value)
              .map(([label, value]) => (
                <React.Fragment key={label}>
                  <dt className="text-xs text-muted-foreground sm:pt-0.5">{label}</dt>
                  <dd className="font-medium">{value}</dd>
                </React.Fragment>
              ))}
          </dl>
        )}

        {consequences.length > 0 && (
          <div className="space-y-1.5">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              What happens next
            </p>
            <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
              {consequences.map((line) => (
                <li key={line}>{line}</li>
              ))}
            </ul>
          </div>
        )}

        {children}

        {confirmPhrase && (
          <div className="space-y-1.5">
            <label htmlFor="confirm-phrase" className="text-sm">
              {confirmPhraseLabel ?? 'Type'}{' '}
              <span className="font-mono font-semibold">{confirmPhrase}</span> to confirm
            </label>
            <Input
              id="confirm-phrase"
              value={typed}
              onChange={(event) => setTyped(event.target.value)}
              autoComplete="off"
              autoFocus
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault()
                  handleConfirm()
                }
              }}
            />
          </div>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
            Cancel
          </Button>
          <Button
            type="button"
            variant={tone === 'destructive' ? 'destructive' : 'default'}
            onClick={handleConfirm}
            disabled={!phraseMatches || busy}
          >
            {busy && <Loader2 className="h-4 w-4 animate-spin" />}
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
