import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Search, IdCard } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { formatDate } from '@/lib/utils'
import { Pagination } from './Applicants'

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

  return (
    <>
      <PageHeader
        title="Employees"
        description="Deployed workers and their current assignments."
      />

      <Card className="mb-4">
        <CardContent className="flex flex-col gap-3 pt-5 sm:flex-row">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => updateFilter('search', e.target.value)}
              placeholder="Search by name or employee number"
              className="pl-9"
              aria-label="Search employees"
            />
          </div>

          <Select
            value={status}
            onChange={(e) => updateFilter('employment_status', e.target.value)}
            placeholder="All statuses"
            aria-label="Filter by employment status"
            className="sm:w-52"
          >
            <option value="active">Active</option>
            <option value="resigned">Resigned</option>
            <option value="terminated">Terminated</option>
            <option value="archived">Archived</option>
          </Select>
        </CardContent>
      </Card>

      <Card>
        {loading ? (
          <SkeletonRows rows={8} columns={6} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={IdCard}
            title="No employees found"
            description="Employees appear here once an applicant is deployed to a client company."
          />
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Employee</TableHead>
                  <TableHead>Number</TableHead>
                  <TableHead>Assignment</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Hired</TableHead>
                  <TableHead>Violations</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((employee) => (
                  <TableRow key={employee.id}>
                    <TableCell>
                      <Link to={`/employees/${employee.id}`} className="font-medium text-primary hover:underline">
                        {employee.full_name}
                      </Link>
                    </TableCell>
                    <TableCell className="font-mono text-xs text-muted-foreground">
                      {employee.employee_number}
                    </TableCell>
                    <TableCell className="text-sm">
                      {employee.current_position_title || '—'}
                      <p className="text-xs text-muted-foreground">
                        {employee.current_company}
                        {employee.current_department && ` · ${employee.current_department}`}
                      </p>
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={employee.employment_status} />
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                      {formatDate(employee.hire_date)}
                    </TableCell>
                    <TableCell>
                      {employee.violations_count > 0 ? (
                        <Badge tone="warning">{employee.violations_count}</Badge>
                      ) : (
                        <span className="text-xs text-muted-foreground">None</span>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            <Pagination meta={meta} onPage={(p) => updateFilter('page', String(p))} />
          </>
        )}
      </Card>
    </>
  )
}
