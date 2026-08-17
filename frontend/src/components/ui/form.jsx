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

export { Field, FormGrid, FormSection }
