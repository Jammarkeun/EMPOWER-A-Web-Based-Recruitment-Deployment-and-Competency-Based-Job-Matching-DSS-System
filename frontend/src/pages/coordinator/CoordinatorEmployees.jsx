import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { IdCard, ChevronRight, AlertTriangle } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import PersonAvatar from '@/components/PersonAvatar'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { formatDate } from '@/lib/utils'
import { Pagination } from '@/components/Pagination'

/**
 * The workers CDE has placed at the coordinator's client.
 *
 * Only this client's people: the API scopes the list from the coordinator's
 * assignment, and a department filter naming another client's department is
 * refused rather than quietly ignored.
 */
export default function CoordinatorEmployees() {
  const [searchParams, setSearchParams] = useSearchParams()
  const search = searchParams.get('search') ?? ''
  const status = searchParams.get('status') ?? 'active'
  const department = searchParams.get('department') ?? ''
  const page = Number(searchParams.get('page') ?? 1)
  const debounced = useDebounced(search)

  const company = useApi('/coordinator/company', undefined, { cacheKey: 'coordinator-company' })
  const { data, meta, loading, error, refetch } = useApi('/coordinator/employees', {
    search: debounced || undefined,
    status,
    client_department_id: department || undefined,
    page,
    per_page: 25,
  })

  function update(key, value) {
    const next = new URLSearchParams(searchParams)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setSearchParams(next)
  }

  return (
    <div className="space-y-4">
      <PageHeader title="Employees" description="Workers CDE has placed at your assigned client." />

      <FilterToolbar
        search={search}
        onSearchChange={(value) => update('search', value)}
        searchPlaceholder="Search by name or employee number…"
        filters={[
          {
            id: 'status',
            label: 'Status',
            value: status,
            onChange: (value) => update('status', value),
            options: [
              { value: 'active', label: 'Active' },
              { value: 'former', label: 'Former' },
              { value: 'all', label: 'Everyone' },
            ],
          },
          {
            id: 'department',
            label: 'All departments',
            value: department,
            onChange: (value) => update('department', value),
            options: [
              { value: '', label: 'All departments' },
              ...(company.data?.departments ?? []).map((d) => ({ value: String(d.id), label: d.department_name })),
            ],
          },
        ]}
        resultCount={meta?.total}
        resultLabel="employees"
      />

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={6} columns={5} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState icon={IdCard} title="No employees found" />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Employee</TableHead>
                <TableHead>Position · department</TableHead>
                <TableHead>Supervisor / OIC</TableHead>
                <TableHead>Hired</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-8" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((employee) => (
                <TableRow key={employee.id} className="group">
                  <TableCell>
                    <Link to={`/coordinator/employees/${employee.id}`} className="flex items-center gap-3">
                      <PersonAvatar src={employee.photo_url} name={employee.full_name} size="sm" />
                      <div className="min-w-0">
                        <p className="font-semibold text-foreground group-hover:text-brand">{employee.full_name}</p>
                        <p className="font-mono text-xs text-muted-foreground">
                          {employee.employee_number}
                          {employee.biometric_number && ` · BIO ${employee.biometric_number}`}
                        </p>
                      </div>
                    </Link>
                  </TableCell>
                  <TableCell className="text-sm">
                    {employee.position ?? '—'}
                    <p className="text-xs text-muted-foreground">{employee.department ?? '—'}</p>
                  </TableCell>
                  <TableCell className="text-sm">{employee.supervisor ?? '—'}</TableCell>
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">{formatDate(employee.hire_date)}</TableCell>
                  <TableCell>
                    <div className="flex flex-wrap gap-1.5">
                      <StatusBadge status={employee.employment_status} />
                      {employee.flagged ? (
                        <Badge tone="warning">
                          <AlertTriangle className="mr-1 h-3 w-3" />
                          At review threshold
                        </Badge>
                      ) : employee.active_violations > 0 ? (
                        <Badge tone="muted">{employee.active_violations} violation{employee.active_violations === 1 ? '' : 's'}</Badge>
                      ) : null}
                    </div>
                  </TableCell>
                  <TableCell className="text-right">
                    <Link to={`/coordinator/employees/${employee.id}`} className="text-muted-foreground group-hover:text-brand">
                      <ChevronRight className="h-4 w-4" />
                    </Link>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <Pagination meta={meta} onPage={(p) => update('page', String(p))} />
        </div>
      )}
    </div>
  )
}
