import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Truck } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { useTableSort } from '@/hooks/useTableSort'
import { PageHeader } from '@/components/PageHeader'
import { FilterToolbar } from '@/components/FilterToolbar'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { SortableHead, Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { formatDate } from '@/lib/utils'
import { Pagination, useClampPage } from '@/components/Pagination'

export default function Deployments() {
  const [searchParams, setSearchParams] = useSearchParams()

  const search = searchParams.get('search') ?? ''
  const status = searchParams.get('deployment_status') ?? ''
  const client = searchParams.get('client_company_id') ?? ''
  const page = Number(searchParams.get('page') ?? 1)
  const debouncedSearch = useDebounced(search)
  // Newest placement first unless the Deployed header says otherwise.
  const sort = useTableSort(searchParams, setSearchParams, {
    by: 'deployment_date',
    dir: 'desc',
    firstDirection: { deployment_date: 'desc' },
  })
  const { data: clients } = useApi('/clients', { per_page: 100 }, { cacheKey: 'clients-all' })

  const { data, meta, loading, error, refetch } = useApi('/deployments', {
    search: debouncedSearch || undefined,
    deployment_status: status || undefined,
    client_company_id: client || undefined,
    // The date is the only order the list has, so only its direction is sent.
    sort_dir: sort.params.sort_dir,
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
    setSearchParams(sort.keep(new URLSearchParams()))
  }

  const filtered = Boolean(search || status || client)
  useClampPage(meta, (p) => updateFilter('page', String(p)))

  return (
    <>
      <PageHeader
        title="Deployments"
        description="Every placement recorded against a client company."
      />

      <FilterToolbar
        className="mb-4"
        search={search}
        onSearchChange={(val) => updateFilter('search', val)}
        searchPlaceholder="Search by employee, employee number or code…"
        filters={[
          {
            id: 'deployment_status',
            label: 'All statuses',
            value: status,
            onChange: (val) => updateFilter('deployment_status', val),
            // The two a deployment is ever given: recorded active, ended at
            // separation. A reassignment keeps the deployment active and
            // writes a history row, so "completed" and "transferred" - in
            // the original schema - never matched anything.
            options: [
              { value: '', label: 'All statuses' },
              { value: 'active', label: 'Active' },
              { value: 'ended', label: 'Ended' },
            ],
          },
          {
            id: 'client_company_id',
            label: 'All clients',
            value: client,
            onChange: (val) => updateFilter('client_company_id', val),
            options: [
              { value: '', label: 'All clients' },
              ...(clients ?? []).map((c) => ({ value: String(c.id), label: c.company_name })),
            ],
          },
        ]}
        resultCount={meta?.total}
        resultLabel="deployments"
        onClearFilters={clearFilters}
      />

      <Card>
        {loading ? (
          <SkeletonRows rows={6} columns={6} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={Truck}
            title={filtered ? 'No deployments match these filters' : 'No deployments recorded'}
            description={
              filtered
                ? 'Try another status or client, or search for part of the name or number.'
                : 'Deployments are recorded from a manpower request once HR selects a candidate.'
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
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Employee</TableHead>
                  <TableHead>Code</TableHead>
                  <TableHead>Client</TableHead>
                  <TableHead>Position</TableHead>
                  <SortableHead column="deployment_date" {...sort.head}>Deployed</SortableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((deployment) => (
                  <TableRow key={deployment.id}>
                    <TableCell>
                      <Link
                        to={`/employees/${deployment.employee_id}`}
                        className="font-medium text-primary hover:underline"
                      >
                        {deployment.employee_name}
                      </Link>
                      <p className="font-mono text-xs text-muted-foreground">{deployment.employee_number}</p>
                    </TableCell>
                    <TableCell className="font-mono text-xs text-muted-foreground">
                      {deployment.deployment_code}
                    </TableCell>
                    <TableCell className="text-sm">
                      {deployment.company_name}
                      <p className="text-xs text-muted-foreground">{deployment.department_name}</p>
                    </TableCell>
                    <TableCell className="text-sm">
                      {deployment.position_title}
                      {deployment.supervisor_name && (
                        <p className="text-xs text-muted-foreground">under {deployment.supervisor_name}</p>
                      )}
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                      {formatDate(deployment.deployment_date)}
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={deployment.deployment_status} />
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
