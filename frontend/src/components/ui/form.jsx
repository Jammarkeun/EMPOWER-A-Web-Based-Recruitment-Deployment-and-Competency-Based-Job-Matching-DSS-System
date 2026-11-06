import * as React from 'react'
import { cn } from '@/lib/utils'
import { Label } from './label'

/**
 * A labelled field with inline validation messaging.
 *
 * The error is tied to the input through aria-describedby and aria-invalid, so
 * a screen reader announces the problem rather than leaving it as colour alone.
 */
function Field({ label, htmlFor, error, hint, required, className, children }) {
  const describedBy = error ? `${htmlFor}-error` : hint ? `${htmlFor}-hint` : undefined

  return (
    <div className={cn('space-y-1.5', className)}>
      {label && (
        <Label htmlFor={htmlFor} required={required}>
          {label}
        </Label>
      )}

      {React.isValidElement(children)
        ? React.cloneElement(children, {
            id: htmlFor,
            'aria-invalid': error ? 'true' : undefined,
            'aria-describedby': describedBy,
          })
        : children}

      {hint && !error && (
        <p id={`${htmlFor}-hint`} className="text-xs text-muted-foreground">
          {hint}
        </p>
      )}

      {error && (
        <p id={`${htmlFor}-error`} className="text-xs font-medium text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}

/**
 * Brings the first field with an error into view and focus after a refused
 * submit, so on a long form - registration on a phone, above all - the
 * person sees what to fix instead of a toast and an unchanged screen.
 *
 *     const [formRef, focusFirstError] = useFocusFirstError()
 *     <form ref={formRef}> … setErrors(problems); focusFirstError()
 *
 * Called by the submit handler rather than fired whenever the errors change:
 * some forms clear one field's error as it is typed in, or work errors out
 * live, and focus must never jump while somebody is typing. The move happens
 * after the render that shows the errors, since that is what marks the
 * fields aria-invalid (see Field).
 */
function useFocusFirstError() {
  const ref = React.useRef(null)
  const [requested, setRequested] = React.useState(0)

  React.useEffect(() => {
    if (!requested) return
    const first = ref.current?.querySelector('[aria-invalid="true"]')
    if (!first) return
    first.focus({ preventScroll: true })
    // Centred, so the message beneath the field is in view as well.
    first.scrollIntoView({ block: 'center' })
  }, [requested])

  const focusFirstError = React.useCallback(() => setRequested((n) => n + 1), [])

  return [ref, focusFirstError]
}

function FormGrid({ className, children }) {
  return <div className={cn('grid gap-4 sm:grid-cols-2', className)}>{children}</div>
}

function FormSection({ title, description, children }) {
  return (
    <section className="space-y-4">
      <div>
        <h3 className="text-sm font-semibold text-foreground">{title}</h3>
        {description && <p className="text-xs text-muted-foreground">{description}</p>}
      </div>
      {children}
    </section>
  )
}

export { Field, FormGrid, FormSection, useFocusFirstError }
