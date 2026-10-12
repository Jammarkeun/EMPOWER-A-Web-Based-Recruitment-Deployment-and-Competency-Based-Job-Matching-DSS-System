import * as React from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { cn } from '@/lib/utils'

const Input = React.forwardRef(({ className, type, ...props }, ref) => (
  <input
    type={type}
    ref={ref}
    className={cn(
      'flex h-9 w-full rounded-control border border-border bg-card px-3 py-1.5 text-sm transition-colors',
      'placeholder:text-muted-foreground focus-visible:outline-none focus-visible:border-brand focus-visible:ring-1 focus-visible:ring-brand',
      'disabled:cursor-not-allowed disabled:opacity-50',
      'aria-[invalid=true]:border-destructive',
      className
    )}
    {...props}
  />
))
Input.displayName = 'Input'

/**
 * A password input with a show/hide toggle.
 *
 * Everything passed in - including the id, aria-invalid, and aria-describedby
 * that Field adds - lands on the <input> itself, so the label and any error
 * belong to the field a screen reader is on rather than to a wrapper around it.
 * Pass visible and onVisibleChange when another field should follow the same
 * toggle; otherwise it keeps its own.
 */
const PasswordInput = React.forwardRef(({ className, visible, onVisibleChange, ...props }, ref) => {
  const [ownVisible, setOwnVisible] = React.useState(false)
  const shown = visible ?? ownVisible

  const toggle = () => {
    setOwnVisible(!shown)
    onVisibleChange?.(!shown)
  }

  return (
    <div className="relative">
      <Input ref={ref} {...props} type={shown ? 'text' : 'password'} className={cn('pr-10', className)} />
      {/*
        Lets the user check a mistyped password rather than clearing the field
        and starting again. Kept out of the tab order so it never sits between
        the password field and the submit button.
      */}
      <button
        type="button"
        onClick={toggle}
        className="absolute right-0 top-0 flex h-9 w-10 items-center justify-center rounded-r-md text-muted-foreground transition-colors hover:text-foreground"
        aria-label={shown ? 'Hide password' : 'Show password'}
        aria-pressed={shown}
        tabIndex={-1}
      >
        {shown ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
      </button>
    </div>
  )
})
PasswordInput.displayName = 'PasswordInput'

export { Input, PasswordInput }
