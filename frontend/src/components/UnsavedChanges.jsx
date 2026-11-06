import * as React from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'

/**
 * Asks before a form with unsaved changes is thrown away.
 *
 * The shared dialog closes on the X, on Escape and on a click outside it, and
 * any of those used to discard eleven typed fields without a word. Wrap the
 * dialog's onOpenChange with `guard` and use the result for the Cancel button
 * too; closing then asks first, but only when something was actually changed.
 * A successful save closes through the caller's own onOpenChange, so it never
 * asks.
 *
 *     const { guard, prompt } = useUnsavedChanges(dirty)
 *     const close = guard(onOpenChange)
 *     <Dialog open={open} onOpenChange={close}> ... {prompt} ... </Dialog>
 *
 * While there are changes, leaving or reloading the page asks the browser's
 * own question as well. Moving to another screen inside the app is not
 * intercepted: the app uses BrowserRouter, which offers no navigation block,
 * and a dialog's overlay already stands between the user and the sidebar.
 */
export function useUnsavedChanges(dirty) {
  const [pending, setPending] = React.useState(null)
  useLeavePageWarning(dirty)

  const confirmDiscard = React.useCallback(
    (discard) => {
      if (dirty) setPending(() => discard)
      else discard()
    },
    [dirty]
  )

  const guard = (onOpenChange) => (next) => {
    if (next) onOpenChange(next)
    else confirmDiscard(() => onOpenChange(false))
  }

  const prompt = (
    <DiscardChangesDialog
      open={pending !== null}
      onKeepEditing={() => setPending(null)}
      onDiscard={() => {
        const discard = pending
        setPending(null)
        discard?.()
      }}
    />
  )

  return { guard, confirmDiscard, prompt }
}

/** The browser's "Leave site?" question, while `when` holds. */
export function useLeavePageWarning(when) {
  React.useEffect(() => {
    if (!when) return undefined

    function warn(event) {
      event.preventDefault()
      // Still required by some browsers to show the prompt at all.
      event.returnValue = ''
    }

    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [when])
}

function DiscardChangesDialog({ open, onKeepEditing, onDiscard }) {
  return (
    <Dialog open={open} onOpenChange={(next) => !next && onKeepEditing()}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>Discard your changes?</DialogTitle>
          <DialogDescription>What you entered in this form has not been saved.</DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onDiscard}>
            Discard changes
          </Button>
          {/* Focused first, so Enter or a second Escape keeps the work. */}
          <Button type="button" onClick={onKeepEditing} autoFocus>
            Keep editing
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
