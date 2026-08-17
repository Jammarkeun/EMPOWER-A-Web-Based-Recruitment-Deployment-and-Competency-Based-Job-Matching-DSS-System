import * as React from 'react'
import { Link } from 'react-router-dom'
import { ChevronRight } from 'lucide-react'
import { cn } from '@/lib/utils'

export function PageHeader({ title, description, breadcrumbs, actions, className }) {
  return (
    <div className={cn('mb-6 space-y-3', className)}>
      {breadcrumbs?.length > 0 && (
        <nav aria-label="Breadcrumb">
          <ol className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
            {breadcrumbs.map((crumb, index) => (
              <li key={crumb.label} className="flex items-center gap-1">
                {index > 0 && <ChevronRight className="h-3 w-3" aria-hidden="true" />}
                {crumb.to ? (
                  <Link to={crumb.to} className="hover:text-foreground hover:underline">
                    {crumb.label}
                  </Link>
                ) : (
                  <span aria-current="page" className="text-foreground">
                    {crumb.label}
                  </span>
                )}
              </li>
            ))}
          </ol>
        </nav>
      )}

      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 space-y-1">
          <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
          {description && <p className="text-sm text-muted-foreground">{description}</p>}
        </div>
        {actions && <div className="flex shrink-0 flex-wrap items-center gap-2 no-print">{actions}</div>}
      </div>
    </div>
  )
}

/**
 * A single headline figure.
 *
 * The optional tone draws attention to counts that represent work waiting to be
 * done - outstanding requirements, overdue requests - rather than colouring
 * every number the same and leaving the user to find the urgent one.
 */
export function StatTile({ label, value, hint, icon: Icon, tone = 'default', to, className, children }) {
  const tones = {
    default: 'text-foreground',
    warning: 'text-warning',
    destructive: 'text-destructive',
    success: 'text-success',
  }

  const iconTones = {
    default: 'bg-muted text-muted-foreground',
    warning: 'bg-warning/15 text-warning',
    destructive: 'bg-destructive/10 text-destructive',
    success: 'bg-success/15 text-success',
  }

  const content = (
    <div
      className={cn(
        'group flex h-full flex-col rounded-xl border bg-card p-4 shadow-sm transition-all',
        to && 'hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md',
        className
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        {Icon && (
          <span className={cn('flex h-7 w-7 shrink-0 items-center justify-center rounded-lg', iconTones[tone])}>
            <Icon className="h-3.5 w-3.5" />
          </span>
        )}
      </div>

      {/*
        Proportional figures, not tabular. Equal-width digits are for columns of
        numbers that have to line up vertically; at display size in a tile they
        make a value like 121 read loose and gappy.
      */}
      <p className={cn('mt-2 text-3xl font-semibold tracking-tight', tones[tone])}>{value}</p>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}

      {children && <div className="mt-auto pt-3">{children}</div>}
    </div>
  )

  return to ? (
    <Link to={to} className="block h-full rounded-xl focus-visible:rounded-xl">
      {content}
    </Link>
  ) : (
    content
  )
}

/**
 * A cell in a bento grid.
 *
 * The grid is twelve columns on a wide screen and collapses to six and then one.
 * Spans are passed per breakpoint rather than computed, because the right size
 * for a panel depends on what is in it — a trend needs width to be readable, a
 * list of pending work needs height — and that judgement does not survive being
 * turned into a rule.
 */
export function Bento({ className, children }) {
  return (
    <div className={cn('grid grid-cols-1 gap-4 sm:grid-cols-6 xl:grid-cols-12', className)}>
      {children}
    </div>
  )
}
