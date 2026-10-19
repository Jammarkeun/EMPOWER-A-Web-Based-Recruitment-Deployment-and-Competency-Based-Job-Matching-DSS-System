import * as React from 'react'
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

export { Table, TableHeader, TableBody, TableHead, TableRow, TableCell, TableCaption }
