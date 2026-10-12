import * as React from 'react'
import { cn } from '@/lib/utils'

/**
 * Single bordered surface container for data tables (10px radius).
 * Handles internal horizontal scrolling gracefully for wide columns.
 */
const Table = React.forwardRef(({ className, ...props }, ref) => (
  <div className="w-full overflow-hidden rounded-panel border border-border bg-card shadow-xs">
    <div className="table-scroll w-full overflow-x-auto">
      <table ref={ref} className={cn('w-full caption-bottom text-sm', className)} {...props} />
    </div>
  </div>
))
Table.displayName = 'Table'

const TableHeader = React.forwardRef(({ className, ...props }, ref) => (
  <thead ref={ref} className={cn('bg-muted/40 border-b border-border [&_tr]:border-b-0', className)} {...props} />
))
TableHeader.displayName = 'TableHeader'

const TableBody = React.forwardRef(({ className, ...props }, ref) => (
  <tbody ref={ref} className={cn('[&_tr:last-child]:border-0 divide-y divide-border/60', className)} {...props} />
))
TableBody.displayName = 'TableBody'

const TableRow = React.forwardRef(({ className, ...props }, ref) => (
  <tr
    ref={ref}
    className={cn('h-11 transition-colors hover:bg-muted/40 data-[state=selected]:bg-muted/60', className)}
    {...props}
  />
))
TableRow.displayName = 'TableRow'

const TableHead = React.forwardRef(({ className, ...props }, ref) => (
  <th
    ref={ref}
    className={cn('h-10 px-3.5 py-2.5 text-left align-middle text-xs font-semibold text-muted-foreground tracking-normal', className)}
    {...props}
  />
))
TableHead.displayName = 'TableHead'

const TableCell = React.forwardRef(({ className, ...props }, ref) => (
  <td ref={ref} className={cn('px-3.5 py-2.5 align-middle text-sm text-foreground', className)} {...props} />
))
TableCell.displayName = 'TableCell'

const TableCaption = React.forwardRef(({ className, ...props }, ref) => (
  <caption ref={ref} className={cn('my-3 text-xs text-muted-foreground', className)} {...props} />
))
TableCaption.displayName = 'TableCaption'

export { Table, TableHeader, TableBody, TableHead, TableRow, TableCell, TableCaption }
