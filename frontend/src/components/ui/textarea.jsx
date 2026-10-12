import * as React from 'react'
import { cn } from '@/lib/utils'

const Textarea = React.forwardRef(({ className, ...props }, ref) => (
  <textarea
    ref={ref}
    className={cn(
      'flex min-h-[80px] w-full rounded-control border border-border bg-card px-3 py-2 text-sm transition-colors',
      'placeholder:text-muted-foreground focus-visible:outline-none focus-visible:border-brand focus-visible:ring-1 focus-visible:ring-brand',
      'disabled:cursor-not-allowed disabled:opacity-50',
      'aria-[invalid=true]:border-destructive',
      className
    )}
    {...props}
  />
))
Textarea.displayName = 'Textarea'

export { Textarea }
