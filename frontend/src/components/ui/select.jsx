import * as React from 'react'
import { cn } from '@/lib/utils'

/**
 * A native select. Chosen over a custom listbox because HR staff enter records
 * at a counter using the keyboard, and the native control gives type-ahead and
 * mobile pickers for free.
 */
const Select = React.forwardRef(({ className, children, placeholder, ...props }, ref) => (
  <select
    ref={ref}
    className={cn(
      'flex h-9 w-full rounded-md border border-input bg-card px-3 py-1 text-sm shadow-sm transition-colors',
      'disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive',
      className
    )}
    {...props}
  >
    {placeholder && <option value="">{placeholder}</option>}
    {children}
  </select>
))
Select.displayName = 'Select'

export { Select }
