import * as React from 'react'
import { Loader2, Inbox, AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Button } from './button'

export function LoadingState({ label = 'Loading…', className }) {
  return (
    <div className={cn('flex items-center justify-center gap-2 py-12 text-sm text-muted-foreground', className)}>
      <Loader2 className="h-4 w-4 animate-spin" />
      {label}
    </div>
  )
}

/**
 * An empty state should say what to do next, not merely that nothing is here.
 * The action is what turns a dead end into a starting point.
 */
export function EmptyState({ title, description, action, icon: Icon = Inbox }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
      <div className="rounded-full bg-muted p-3">
        <Icon className="h-6 w-6 text-muted-foreground" />
      </div>
      <div className="space-y-1">
        <p className="text-sm font-medium">{title}</p>
        {description && <p className="max-w-sm text-sm text-muted-foreground">{description}</p>}
      </div>
      {action}
    </div>
  )
}

export function ErrorState({ message, onRetry }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center">
      <div className="rounded-full bg-destructive/10 p-3">
        <AlertTriangle className="h-6 w-6 text-destructive" />
      </div>
      <div className="space-y-1">
        <p className="text-sm font-medium">Could not load this data</p>
        <p className="max-w-sm text-sm text-muted-foreground">{message}</p>
      </div>
      {onRetry && (
        <Button variant="outline" size="sm" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  )
}

export function Skeleton({ className, ...props }) {
  return (
    <div
      className={cn('animate-pulse rounded-md bg-muted/70', className)}
      {...props}
    />
  )
}

export function SkeletonRows({ rows = 5, columns = 4 }) {
  return (
    <div className="space-y-3 p-4">
      {Array.from({ length: rows }).map((_, r) => (
        <div key={r} className="flex items-center gap-3">
          {Array.from({ length: columns }).map((_, c) => (
            <Skeleton key={c} className={cn('h-4 flex-1', c === 0 && 'max-w-[120px]')} />
          ))}
        </div>
      ))}
    </div>
  )
}

export function CardSkeleton({ className }) {
  return (
    <div className={cn('space-y-4 rounded-xl border p-5 shadow-sm', className)}>
      <div className="flex items-center justify-between">
        <Skeleton className="h-5 w-1/3" />
        <Skeleton className="h-4 w-12" />
      </div>
      <Skeleton className="h-4 w-3/4" />
      <div className="space-y-2 pt-2">
        <Skeleton className="h-3 w-full" />
        <Skeleton className="h-3 w-5/6" />
      </div>
    </div>
  )
}

export function StatCardSkeleton({ count = 4 }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="rounded-xl border p-4 space-y-3">
          <div className="flex items-center justify-between">
            <Skeleton className="h-4 w-24" />
            <Skeleton className="h-8 w-8 rounded-lg" />
          </div>
          <Skeleton className="h-7 w-16" />
          <Skeleton className="h-3 w-32" />
        </div>
      ))}
    </div>
  )
}

/**
 * Renders only the visible part of a large fixed-height collection. Normal
 * paginated responses stay untouched; this activates automatically when a
 * collection is large enough for DOM size to become noticeable.
 */
export function VirtualizedList({ items, itemHeight = 64, renderItem, className, virtualize = true }) {
  const [scrollTop, setScrollTop] = React.useState(0)
  const viewportHeight = 560
  const overscan = 5

  if (!virtualize || items.length <= 100) {
    return <div className={className}>{items.map(renderItem)}</div>
  }

  const first = Math.max(0, Math.floor(scrollTop / itemHeight) - overscan)
  const last = Math.min(items.length, Math.ceil((scrollTop + viewportHeight) / itemHeight) + overscan)

  return (
    <div
      className={cn('max-h-[35rem] overflow-y-auto', className)}
      onScroll={(event) => setScrollTop(event.currentTarget.scrollTop)}
    >
      <div style={{ height: items.length * itemHeight, position: 'relative' }}>
        {items.slice(first, last).map((item, index) => (
          <div
            key={item.id ?? first + index}
            className="absolute inset-x-0"
            style={{ top: (first + index) * itemHeight, minHeight: itemHeight }}
          >
            {renderItem(item, first + index)}
          </div>
        ))}
      </div>
    </div>
  )
}
