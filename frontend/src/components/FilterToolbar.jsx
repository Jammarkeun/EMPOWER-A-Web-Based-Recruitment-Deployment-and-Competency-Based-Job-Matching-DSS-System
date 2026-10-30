import * as React from 'react'
import { Search, X, SlidersHorizontal, ChevronDown } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

/**
 * FilterToolbar — standardized filter bar that sits directly above data tables.
 *
 * No outer bordered card container per design spec. Uses inline spacing.
 */
export function FilterToolbar({
  search,
  onSearchChange,
  searchPlaceholder = 'Search records…',
  filters = [],
  viewToggles = [],
  resultCount,
  resultLabel = 'records',
  onClearFilters,
  className,
}) {
  const hasActiveFilters = React.useMemo(() => {
    const hasSearch = Boolean(search && search.trim().length > 0)
    const hasFilterValue = filters.some((f) => f.value && f.value !== '' && f.value !== 'all')
    const hasActiveToggle = viewToggles.some((vt) => vt.active)
    return hasSearch || hasFilterValue || hasActiveToggle
  }, [search, filters, viewToggles])

  return (
    <div className={cn('space-y-2 pb-1', className)}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        {/* Search & Dropdown Filters Group */}
        <div className="flex flex-wrap items-center gap-2.5 flex-1 min-w-[280px]">
          {/* Search Input */}
          {onSearchChange && (
            <div className="relative w-full max-w-xs sm:w-80">
              <Search className="absolute left-4 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-muted-foreground" />
              <Input
                value={search ?? ''}
                onChange={(e) => onSearchChange(e.target.value)}
                placeholder={searchPlaceholder}
                aria-label={searchPlaceholder ?? 'Search'}
                className="h-11 rounded-2xl bg-card pl-11 pr-9 text-sm"
              />
              {search && search.length > 0 && (
                <button
                  type="button"
                  onClick={() => onSearchChange('')}
                  className="absolute right-3.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  aria-label="Clear search"
                >
                  <X className="h-3.5 w-3.5" />
                </button>
              )}
            </div>
          )}

          {/* Dropdown Filters — native selects, drawn as white pills */}
          {filters.map((filter) => (
            <div key={filter.id || filter.label} className="relative">
              <select
                value={filter.value ?? ''}
                onChange={(e) => filter.onChange && filter.onChange(e.target.value)}
                aria-label={filter.label}
                className="h-11 w-full appearance-none rounded-full bg-card pl-4 pr-10 text-sm text-foreground transition-colors focus:outline-none focus:ring-1 focus:ring-brand sm:w-48"
              >
                {filter.options.map((opt) => (
                  <option key={opt.value} value={opt.value}>
                    {opt.label}
                  </option>
                ))}
              </select>
              <ChevronDown
                className="pointer-events-none absolute right-4 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
              />
            </div>
          ))}

          {/* View Toggles (Segmented Controls) */}
          {viewToggles.length > 0 && (
            <div className="flex h-11 items-center rounded-full bg-card p-1 text-sm">
              {viewToggles.map((toggle) => (
                <button
                  key={toggle.id || toggle.label}
                  type="button"
                  onClick={toggle.onToggle}
                  className={cn(
                    'h-full rounded-full px-4 transition-colors',
                    toggle.active
                      ? 'bg-inset-selected font-medium text-foreground'
                      : 'text-muted-foreground hover:text-foreground'
                  )}
                >
                  {toggle.label}
                </button>
              ))}
            </div>
          )}
        </div>

        {/* Right side: Result Count */}
        {resultCount !== undefined && (
          <div className="whitespace-nowrap text-sm text-muted-foreground tabular-nums">
            <span className="font-medium text-foreground">{resultCount}</span> {resultLabel}
          </div>
        )}
      </div>

      {/* Active Filter Summary Bar */}
      {hasActiveFilters && onClearFilters && (
        <div className="flex items-center gap-2 pt-1 text-xs text-muted-foreground">
          <span className="flex items-center gap-1 font-medium text-foreground">
            <SlidersHorizontal className="h-3 w-3" /> Filters active
          </span>
          <span>·</span>
          <Button
            variant="ghost"
            size="sm"
            onClick={onClearFilters}
            className="h-6 px-2 text-xs text-brand hover:text-brand-hover hover:bg-brand/10"
          >
            Clear all filters
          </Button>
        </div>
      )}
    </div>
  )
}

