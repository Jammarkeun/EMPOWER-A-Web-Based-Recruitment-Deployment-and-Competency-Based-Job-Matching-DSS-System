import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { IdCard, AlertTriangle, ChevronRight } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import PersonAvatar from '@/components/PersonAvatar'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { Button } from '@/components/ui/button'
import { FilterToolbar } from '@/components/FilterToolbar'
import { formatDate } from '@/lib/utils'
import { Pagination, useClampPage } from '@/components/Pagination'

export default function Employees() {
  const [searchParams, setSearchParams] = useSearchParams()

  const search = searchParams.get('search') ?? ''
  const status = searchParams.get('employment_status') ?? ''
  const page = Number(searchParams.get('page') ?? 1)
  const debouncedSearch = useDebounced(search)

  const { data, meta, loading, error, refetch } = useApi('/employees', {
    search: debouncedSearch || undefined,
    employment_status: status || undefined,
    page,
    per_page: 20,
  })

  function updateFilter(key, value) {
    const next = new URLSearchParams(searchParams)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setSearchParams(next)
  }

  function clearFilters() {
    setSearchParams(new URLSearchParams())
  }

  const filtered = Boolean(search || status)
  useClampPage(meta, (p) => updateFilter('page', String(p)))

  return (
    <div className="space-y-4">
      <PageHeader
        title="Employees"
        description="Deployed workforce, active assignments, and records."
      />

      <FilterToolbar
        search={search}
        onSearchChange={(val) => updateFilter('search', val)}
        searchPlaceholder="Search by name or employee number…"
        filters={[
          {
            id: 'status',
            label: 'All Statuses',
            value: status,
            onChange: (val) => updateFilter('employment_status', val),
            options: [
              { value: '', label: 'All Statuses' },
              { value: 'active', label: 'Active' },
              { value: 'resigned', label: 'Resigned' },
              { value: 'terminated', label: 'Terminated' },
              // No "Archived": archiving an employee keeps a snapshot in
              // Archives and leaves the status as it was, so it never matched.
            ],
          },
        ]}
        resultCount={meta?.total}
        resultLabel="employees"
        onClearFilters={clearFilters}
      />

      {loading ? (
        // A loading placeholder, not table rows, so it sits in the table's
        // panel rather than inside a <table>.
        <div className="w-full rounded-panel bg-card p-3 sm:p-4">
          <SkeletonRows rows={8} columns={5} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={IdCard}
          title={filtered ? 'No employees match these filters' : 'No employees recorded yet'}
          description={
            filtered
              ? 'Try another status, or search for part of the name or employee number.'
              : 'Employees will appear here once an applicant is deployed to a client company.'
          }
          action={
            filtered ? (
              <Button size="sm" variant="outline" onClick={clearFilters}>
                Clear filters
              </Button>
            ) : null
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Employee</TableHead>
                <TableHead>Current Assignment</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Date Hired</TableHead>
                <TableHead>Violations</TableHead>
                <TableHead className="w-8"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((employee) => (
                <TableRow key={employee.id} className="group cursor-pointer">
                  {/* Identity Block: Name + Employee Number */}
                  <TableCell>
                    <Link to={`/employees/${employee.id}`} className="flex items-center gap-3">
                      <PersonAvatar src={employee.photo_url} name={employee.full_name} size="sm" />
                      <div className="min-w-0">
                        <p className="font-semibold text-foreground group-hover:text-brand transition-colors">
                          {employee.full_name}
                        </p>
                        <p className="font-mono text-xs text-muted-foreground tabular-nums">
                          {employee.employee_number || `EMP-${employee.id}`}
                        </p>
                      </div>
                    </Link>
                  </TableCell>

                  {/* Assignment: Position title primary, Client · Department secondary */}
                  <TableCell>
                    <p className="text-sm font-medium text-foreground">
                      {employee.current_position_title || 'Unassigned'}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {employee.current_company || 'No active client'}
                      {employee.current_department && ` · ${employee.current_department}`}
                    </p>
                  </TableCell>

                  {/* Status Badge */}
                  <TableCell>
                    <StatusBadge status={employee.employment_status} />
                  </TableCell>

                  {/* Hired Date */}
                  <TableCell className="whitespace-nowrap font-mono text-xs text-muted-foreground tabular-nums">
                    {formatDate(employee.hire_date)}
                  </TableCell>

                  {/* Violations Column */}
                  <TableCell>
                    {employee.violations_count > 0 ? (
                      <span className="inline-flex items-center gap-1 text-xs font-medium text-status-warning-text bg-status-warning-bg px-2 py-0.5 rounded-tag">
                        <AlertTriangle className="h-3 w-3" />
                        {employee.violations_count} {employee.violations_count === 1 ? 'record' : 'records'}
                      </span>
                    ) : (
                      <span className="text-xs text-muted-foreground">None</span>
                    )}
                  </TableCell>

                  {/* Action Link */}
                  <TableCell className="text-right">
                    <Link to={`/employees/${employee.id}`} className="text-muted-foreground group-hover:text-brand">
                      <ChevronRight className="h-4 w-4" />
                    </Link>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          <Pagination meta={meta} onPage={(p) => updateFilter('page', String(p))} />
        </div>
      )}
    </div>
  )
}
