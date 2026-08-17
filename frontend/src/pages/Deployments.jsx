import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Truck } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Select } from '@/components/ui/select'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { formatDate } from '@/lib/utils'
import { Pagination } from './Applicants'

export default function Deployments() {
  const [searchParams, setSearchParams] = useSearchParams()

  const status = searchParams.get('deployment_status') ?? ''
  const page = Number(searchParams.get('page') ?? 1)

  const { data, meta, loading, error, refetch } = useApi('/deployments', {
    deployment_status: status || undefined,
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
        title="Deployments"
        description="Every placement recorded against a client company."
      />

      <Card className="mb-4">
        <CardContent className="pt-5">
          <Select
            value={status}
            onChange={(e) => updateFilter('deployment_status', e.target.value)}
            placeholder="All statuses"
            aria-label="Filter by deployment status"
            className="sm:w-56"
          >
            <option value="active">Active</option>
            <option value="completed">Completed</option>
            <option value="transferred">Transferred</option>
            <option value="ended">Ended</option>
          </Select>
        </CardContent>
      </Card>

      <Card>
        {loading ? (
          <SkeletonRows rows={6} columns={6} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={Truck}
            title="No deployments recorded"
            description="Deployments are recorded from a manpower request once HR selects a candidate."
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
                  <TableHead>Deployed</TableHead>
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
