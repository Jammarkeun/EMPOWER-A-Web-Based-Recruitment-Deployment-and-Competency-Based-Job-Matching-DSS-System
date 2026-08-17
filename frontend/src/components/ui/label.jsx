import * as React from 'react'
import * as LabelPrimitive from '@radix-ui/react-label'
import { cn } from '@/lib/utils'

const Label = React.forwardRef(({ className, required, children, ...props }, ref) => (
  <LabelPrimitive.Root
    ref={ref}
    className={cn('text-sm font-medium leading-none text-foreground', className)}
    {...props}
  >
    {children}
    {required && <span className="ml-0.5 text-destructive" aria-hidden="true">*</span>}
  </LabelPrimitive.Root>
))
Label.displayName = 'Label'

export { Label }
