import * as React from 'react'
import { Link } from 'react-router-dom'
import { ArrowUpRight, ChevronRight } from 'lucide-react'
import { cn } from '@/lib/utils'
import { StatusDot } from '@/components/ui/panel'

/**
 * `leading` sits before the title, for a record that is a person: their
 * picture beside their name.
 */
export function PageHeader({ title, description, breadcrumbs, actions, summary, leading, className }) {
  return (
    <div className={cn('mb-6 space-y-2.5', className)}>
      {breadcrumbs?.length > 0 && (
        <nav aria-label="Breadcrumb">
          <ol className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
            {breadcrumbs.map((crumb, index) => (
              <li key={crumb.label} className="flex items-center gap-1.5">
                {index > 0 && <ChevronRight className="h-3 w-3 text-muted-foreground/50" aria-hidden="true" />}
                {crumb.to ? (
                  <Link to={crumb.to} className="transition-colors hover:text-foreground">
                    {crumb.label}
                  </Link>
                ) : (
                  <span aria-current="page" className="font-medium text-foreground">
                    {crumb.label}
                  </span>
                )}
              </li>
            ))}
          </ol>
        </nav>
      )}

      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 items-center gap-4">
          {leading}
          <div className="min-w-0 space-y-1.5">
            <h1 className="font-heading text-[26px] font-normal leading-tight tracking-[-0.01em] text-foreground sm:text-[32px]">
              {title}
            </h1>
            {description && <p className="text-sm leading-relaxed text-muted-foreground">{description}</p>}
            {summary && <p className="pt-0.5 font-mono text-xs font-medium text-muted-foreground/80">{summary}</p>}
          </div>
        </div>
        {actions && <div className="no-print flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </div>
  )
}

/**
 * A single headline figure.
 *
 * The optional tone draws attention to counts that represent work waiting to be
 * done - outstanding requirements, overdue requests - with a coloured dot in the
 * corner, rather than colouring every number the same and leaving the user to
 * find the urgent one.
 */
export function StatTile({ label, value, hint, icon: Icon, tone = 'default', to, className, children }) {
  const content = (
    <div
      className={cn(
        'group flex h-full flex-col rounded-panel bg-card p-5 transition-shadow sm:p-6',
        to && 'hover:shadow-float',
        className
      )}
    >
      <div className="flex items-center gap-2">
        {Icon && <Icon className="h-4 w-4 shrink-0 text-text-subtle" aria-hidden="true" />}
        <p className="min-w-0 truncate text-sm font-medium text-foreground">{label}</p>
        <span className="ml-auto flex items-center gap-2">
          {tone !== 'default' && <StatusDot tone={tone} />}
          {to && (
            <ArrowUpRight
              className="h-4 w-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
              aria-hidden="true"
            />
          )}
        </span>
      </div>

      {/*
        Proportional figures, not tabular. Equal-width digits are for columns of
        numbers that have to line up vertically; at display size in a tile they
        make a value like 121 read loose and gappy.
      */}
      <div className="mt-6 flex flex-wrap items-end justify-between gap-x-3 gap-y-1">
        <p className="text-[44px] font-normal leading-none tracking-tight text-foreground">{value}</p>
        {hint && <p className="pb-1 text-right text-sm text-muted-foreground">{hint}</p>}
      </div>

      {children && <div className="mt-auto pt-4">{children}</div>}
    </div>
  )

  return to ? (
    <Link to={to} className="block h-full rounded-panel focus-visible:rounded-panel">
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
