import * as React from 'react'
import { Link } from 'react-router-dom'
import { ArrowUpRight } from 'lucide-react'
import { cn } from '@/lib/utils'

/**
 * Building blocks for the content inside a white panel: its title row, the
 * recessed rows it lists, and the small marks those rows carry.
 */

/** A panel's title row: a quiet icon, the title, and an optional action on the right. */
export function PanelTitle({ icon: Icon, title, description, action, className }) {
  return (
    <div className={cn('flex items-start justify-between gap-3', className)}>
      <div className="min-w-0">
        <h3 className="flex items-center gap-2 text-[15px] font-medium leading-tight text-foreground">
          {Icon && <Icon className="h-4 w-4 shrink-0 text-text-subtle" aria-hidden="true" />}
          {title}
        </h3>
        {description && <p className="mt-1 text-xs leading-relaxed text-muted-foreground">{description}</p>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}

/**
 * A recessed row inside a panel. Renders a link when given `to`, so the whole
 * row is the click target rather than a small arrow at its end.
 */
export function InsetRow({ to, selected = false, className, children, ...props }) {
  const classes = cn(
    'flex items-center gap-3 rounded-row border-2 border-transparent bg-inset p-3 transition-colors',
    to && 'hover:bg-inset-selected',
    selected && 'border-inset-selected-border bg-inset-selected',
    className
  )

  return to ? (
    <Link to={to} className={classes} {...props}>
      {children}
    </Link>
  ) : (
    <div className={classes} {...props}>
      {children}
    </div>
  )
}

const ICON_TONES = {
  default: 'text-muted-foreground',
  info: 'text-status-info-dot',
  success: 'text-status-success-dot',
  warning: 'text-status-warning-dot',
  destructive: 'text-status-danger-dot',
}

/** The white rounded square an inset row leads with. */
export function RowIcon({ icon: Icon, tone = 'default', className }) {
  return (
    <span
      className={cn('flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-card', ICON_TONES[tone], className)}
      aria-hidden="true"
    >
      <Icon className="h-[18px] w-[18px]" />
    </span>
  )
}

const DOT_TONES = {
  default: 'bg-status-neutral-dot',
  info: 'bg-status-info-dot',
  success: 'bg-status-success-dot',
  warning: 'bg-status-warning-dot',
  destructive: 'bg-status-danger-dot',
}

/** A small coloured dot, always beside words that say what it means. */
export function StatusDot({ tone = 'default', className }) {
  return <span aria-hidden="true" className={cn('inline-block h-1.5 w-1.5 shrink-0 rounded-full', DOT_TONES[tone], className)} />
}

const FILL_TONES = {
  default: 'bg-brand',
  success: 'bg-status-success-text',
  warning: 'bg-status-warning-text',
  destructive: 'bg-status-danger-text',
  light: 'bg-white',
}

/**
 * A progress track: a solid rounded fill over fine diagonal stripes.
 * `value` is a percentage from 0 to 100.
 */
export function ProgressTrack({ value, tone = 'default', className, label }) {
  const width = Math.max(0, Math.min(100, Number(value) || 0))

  return (
    <div
      className={cn('h-2.5 w-full overflow-hidden rounded-full', tone === 'light' ? 'track-hatch-light' : 'track-hatch', className)}
      role="progressbar"
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={Math.round(width)}
      aria-label={label}
    >
      <div className={cn('h-full rounded-full transition-all duration-300', FILL_TONES[tone])} style={{ width: `${width}%` }} />
    </div>
  )
}

/** The round white button with a diagonal arrow that ends a row. */
export function RoundLink({ to, label, className }) {
  return (
    <Link
      to={to}
      aria-label={label}
      title={label}
      className={cn(
        'flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-card text-muted-foreground transition-colors hover:text-foreground',
        className
      )}
    >
      <ArrowUpRight className="h-4 w-4" />
    </Link>
  )
}
