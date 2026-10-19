import * as React from 'react'
import { cva } from 'class-variance-authority'
import { cn } from '@/lib/utils'
import { humanise, statusTone } from '@/lib/utils'

/*
 * A tinted chip led by a dot in the same status colour. The status is always
 * written out beside the dot, so the colour only groups like with like and
 * never carries the meaning on its own.
 */
const badgeVariants = cva(
  'inline-flex items-center gap-1.5 whitespace-nowrap rounded-badge border px-2.5 py-1 text-xs font-medium leading-4 transition-colors',
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
        outline: 'border-border bg-card text-foreground',
      },
    },
    defaultVariants: { tone: 'muted' },
  }
)

const DOTS = {
  muted: 'bg-status-neutral-dot',
  neutral: 'bg-status-neutral-dot',
  info: 'bg-status-info-dot',
  success: 'bg-status-success-dot',
  warning: 'bg-status-warning-dot',
  destructive: 'bg-status-danger-dot',
  danger: 'bg-status-danger-dot',
}

function Badge({ className, tone = 'muted', children, ...props }) {
  const dot = DOTS[tone]

  return (
    <span className={cn(badgeVariants({ tone }), className)} {...props}>
      {dot && <span aria-hidden="true" className={cn('h-1.5 w-1.5 shrink-0 rounded-full', dot)} />}
      {children}
    </span>
  )
}

/**
 * A status badge that derives its tone from the workflow status string.
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
