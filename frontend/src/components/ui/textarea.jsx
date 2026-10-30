import * as React from 'react'
import { cn } from '@/lib/utils'

const Textarea = React.forwardRef(({ className, ...props }, ref) => (
  <textarea
    ref={ref}
    className={cn(
      'flex min-h-[88px] w-full rounded-control border border-transparent bg-inset px-4 py-3 text-sm transition-colors',
      'placeholder:text-muted-foreground focus-visible:outline-none focus-visible:border-brand focus-visible:bg-card focus-visible:ring-1 focus-visible:ring-brand',
      'disabled:cursor-not-allowed disabled:opacity-50',
      'aria-[invalid=true]:border-destructive',
      className
    )}
    {...props}
  />
))
Textarea.displayName = 'Textarea'

export { Textarea }
