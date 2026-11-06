import * as React from 'react'
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react'
import { cn } from '@/lib/utils'

/**
 * Data tables, drawn as a stack of recessed rounded rows on a white panel.
 *
 * Standing alone on the canvas the table brings its own panel; placed inside a
 * card it uses that card's. Wide tables scroll inside their own container so
 * the page body never scrolls sideways on a laptop screen.
 */
const Table = React.forwardRef(({ className, ...props }, ref) => (
  <div className="w-full rounded-panel bg-card p-3 sm:p-4 [[data-surface=card]_&]:rounded-none [[data-surface=card]_&]:bg-transparent [[data-surface=card]_&]:p-0">
    <div className="table-scroll w-full overflow-x-auto">
      <table
        ref={ref}
        className={cn('-my-2 w-full caption-bottom border-separate border-spacing-x-0 border-spacing-y-2 text-sm', className)}
        {...props}
      />
    </div>
  </div>
))
Table.displayName = 'Table'

const TableHeader = React.forwardRef(({ className, ...props }, ref) => (
  <thead ref={ref} className={cn(className)} {...props} />
))
TableHeader.displayName = 'TableHeader'

const TableBody = React.forwardRef(({ className, ...props }, ref) => (
  <tbody ref={ref} className={cn(className)} {...props} />
))
TableBody.displayName = 'TableBody'

const TableRow = React.forwardRef(({ className, ...props }, ref) => (
  <tr
    ref={ref}
    className={cn(
      '[&>td:first-child]:rounded-l-row [&>td:last-child]:rounded-r-row',
      'hover:[&>td]:bg-inset-selected data-[state=selected]:[&>td]:bg-inset-selected',
      className
    )}
    {...props}
  />
))
TableRow.displayName = 'TableRow'

const TableHead = React.forwardRef(({ className, ...props }, ref) => (
  <th
    ref={ref}
    className={cn('h-8 whitespace-nowrap px-4 pb-0 text-left align-middle text-xs font-normal text-muted-foreground', className)}
    {...props}
  />
))
TableHead.displayName = 'TableHead'

const TableCell = React.forwardRef(({ className, ...props }, ref) => (
  <td
    ref={ref}
    className={cn('bg-inset px-4 py-3.5 align-middle text-sm text-foreground transition-colors', className)}
    {...props}
  />
))
TableCell.displayName = 'TableCell'

const TableCaption = React.forwardRef(({ className, ...props }, ref) => (
  <caption ref={ref} className={cn('my-3 text-xs text-muted-foreground', className)} {...props} />
))
TableCaption.displayName = 'TableCaption'

/**
 * A column header that sorts the list (see useTableSort).
 *
 * The heading becomes a button, and the cell carries aria-sort, so a screen
 * reader hears which column the list is in order by and which way. The arrow
 * says the same to everyone else; an unsorted column shows a faint two-way
 * arrow, so it is clear the column can be sorted at all.
 */
const SortableHead = React.forwardRef(({ column, sortBy, sortDir, onSort, className, children, ...props }, ref) => {
  const active = sortBy === column
  const Icon = !active ? ChevronsUpDown : sortDir === 'asc' ? ArrowUp : ArrowDown

  return (
    <TableHead
      ref={ref}
      aria-sort={active ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'}
      className={className}
      {...props}
    >
      <button
        type="button"
        onClick={() => onSort(column)}
        className={cn(
          '-mx-1 inline-flex items-center gap-1 rounded px-1 transition-colors hover:text-foreground',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
          active && 'font-medium text-foreground'
        )}
      >
        {children}
        <Icon className={cn('h-3 w-3 shrink-0', !active && 'opacity-50')} aria-hidden="true" />
      </button>
    </TableHead>
  )
})
SortableHead.displayName = 'SortableHead'

export { Table, TableHeader, TableBody, TableHead, TableRow, TableCell, TableCaption, SortableHead }
