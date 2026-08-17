import * as React from 'react'
import { cva } from 'class-variance-authority'
import { cn } from '@/lib/utils'
import { humanise, statusTone } from '@/lib/utils'

const badgeVariants = cva(
  'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
  {
    variants: {
      tone: {
        muted: 'border-transparent bg-secondary text-secondary-foreground',
        info: 'border-transparent bg-primary/10 text-primary',
        // Opacity modifiers must land on Tailwind's scale, which moves in
        // steps of five. A value like /12 silently compiles to nothing at all,
        // leaving the badge with no tint and no error to notice.
        success: 'border-transparent bg-success/15 text-success',
        warning: 'border-transparent bg-warning/15 text-warning',
        destructive: 'border-transparent bg-destructive/10 text-destructive',
        outline: 'text-foreground',
      },
    },
    defaultVariants: { tone: 'muted' },
  }
)

function Badge({ className, tone, ...props }) {
  return <span className={cn(badgeVariants({ tone }), className)} {...props} />
}

/**
 * A status badge that derives its colour from the workflow value.
 *
 * The label is always rendered as text as well as colour, so the meaning
 * survives for colour-blind users and in black-and-white printouts, which HR
 * produces for client meetings.
 */
function StatusBadge({ status, label, className }) {
  if (!status) return <span className="text-muted-foreground">—</span>
  return (
    <Badge tone={statusTone(status)} className={className}>
      {label ?? humanise(status)}
    </Badge>
  )
}

export { Badge, StatusBadge, badgeVariants }
