import * as React from 'react'
import { cn } from '@/lib/utils'

/**
 * Wide tables scroll within their own container rather than pushing the page
 * sideways; the employee and applicant lists carry a lot of columns.
 */
const Table = React.forwardRef(({ className, ...props }, ref) => (
  <div className="table-scroll rounded-md border">
    <table ref={ref} className={cn('w-full caption-bottom text-sm', className)} {...props} />
  </div>
))
Table.displayName = 'Table'

const TableHeader = React.forwardRef(({ className, ...props }, ref) => (
  <thead ref={ref} className={cn('sticky top-0 z-10 bg-muted/95 backdrop-blur-sm [&_tr]:border-b', className)} {...props} />
))
TableHeader.displayName = 'TableHeader'

const TableBody = React.forwardRef(({ className, ...props }, ref) => (
  <tbody ref={ref} className={cn('[&_tr:last-child]:border-0', className)} {...props} />
))
TableBody.displayName = 'TableBody'

const TableRow = React.forwardRef(({ className, ...props }, ref) => (
  <tr ref={ref} className={cn('border-b transition-colors hover:bg-muted/40 data-[state=selected]:bg-muted', className)} {...props} />
))
TableRow.displayName = 'TableRow'

const TableHead = React.forwardRef(({ className, ...props }, ref) => (
  <th
    ref={ref}
    className={cn('h-10 px-3 text-left align-middle text-xs font-semibold uppercase tracking-wide text-muted-foreground', className)}
    {...props}
  />
))
TableHead.displayName = 'TableHead'

const TableCell = React.forwardRef(({ className, ...props }, ref) => (
  <td ref={ref} className={cn('px-3 py-2.5 align-middle', className)} {...props} />
))
TableCell.displayName = 'TableCell'

const TableCaption = React.forwardRef(({ className, ...props }, ref) => (
  <caption ref={ref} className={cn('mt-3 text-sm text-muted-foreground', className)} {...props} />
))
TableCaption.displayName = 'TableCaption'

export { Table, TableHeader, TableBody, TableHead, TableRow, TableCell, TableCaption }
