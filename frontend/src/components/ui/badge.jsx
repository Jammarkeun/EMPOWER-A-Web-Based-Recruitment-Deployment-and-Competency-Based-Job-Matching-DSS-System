import * as React from 'react'
import { cva } from 'class-variance-authority'
import { cn } from '@/lib/utils'
import { humanise, statusTone } from '@/lib/utils'

const badgeVariants = cva(
  'inline-flex items-center rounded-badge border px-2.5 py-0.5 text-xs font-semibold transition-colors',
  {
    variants: {
      tone: {
        muted: 'bg-status-neutral-bg text-status-neutral-text border-status-neutral-border',
        neutral: 'bg-status-neutral-bg text-status-neutral-text border-status-neutral-border',
        info: 'bg-status-info-bg text-status-info-text border-status-info-border',
        success: 'bg-status-success-bg text-status-success-text border-status-success-border',
        warning: 'bg-status-warning-bg text-status-warning-text border-status-warning-border',
        destructive: 'bg-status-danger-bg text-status-danger-text border-status-danger-border',
        danger: 'bg-status-danger-bg text-status-danger-text border-status-danger-border',
        outline: 'border-border text-foreground bg-transparent',
      },
    },
    defaultVariants: { tone: 'muted' },
  }
)

function Badge({ className, tone, ...props }) {
  return <span className={cn(badgeVariants({ tone }), className)} {...props} />
}

/**
 * A status badge that derives its tone from the workflow status string.
 *
 * Status badges are always pill-shaped (999px / rounded-badge) and use
 * high-contrast accessible background and text pairs.
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
