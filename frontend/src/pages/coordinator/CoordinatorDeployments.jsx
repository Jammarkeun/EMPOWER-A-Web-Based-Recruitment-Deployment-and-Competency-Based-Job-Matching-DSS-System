import * as React from 'react'
import { Link } from 'react-router-dom'
import { Truck } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { formatDate } from '@/lib/utils'
import { Pagination } from '@/components/Pagination'

/**
 * Placements at the coordinator's client, as CDE recorded them.
 *
 * Read-only. Deployment is recorded by HR from the details the client supplies;
 * the coordinator sees the result.
 */
export default function CoordinatorDeployments() {
  const [status, setStatus] = React.useState('active')
  const [page, setPage] = React.useState(1)

  React.useEffect(() => setPage(1), [status])

  const { data, meta, loading, error, refetch } = useApi('/coordinator/deployments', { status, page, per_page: 25 })

  return (
    <div className="space-y-4">
      <PageHeader title="Deployments" description="Placements at your assigned client. Recorded by CDE's HR." />

      <FilterToolbar
        filters={[
          {
            id: 'status',
            label: 'Status',
            value: status,
            onChange: setStatus,
            options: [
              { value: 'active', label: 'Current' },
              { value: 'ended', label: 'Ended' },
              { value: 'all', label: 'All' },
            ],
          },
        ]}
        resultCount={meta?.total}
        resultLabel="deployments"
      />

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={6} columns={6} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState icon={Truck} title="No deployments" />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Employee</TableHead>
                <TableHead>Department</TableHead>
                <TableHead>Position</TableHead>
                <TableHead>Supervisor / OIC</TableHead>
                <TableHead>Deployed</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((d) => (
                <TableRow key={d.id}>
                  <TableCell>
                    <Link to={`/coordinator/employees/${d.employee.id}`} className="font-medium hover:text-brand">
                      {d.employee.full_name}
                    </Link>
                    <p className="font-mono text-xs text-muted-foreground">{d.employee.employee_number}</p>
                  </TableCell>
                  <TableCell className="text-sm">{d.department ?? '—'}</TableCell>
                  <TableCell className="text-sm">{d.position}</TableCell>
                  <TableCell className="text-sm">{d.supervisor ?? '—'}</TableCell>
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                    {formatDate(d.deployment_date)}
                    {d.end_date && ` – ${formatDate(d.end_date)}`}
                  </TableCell>
                  <TableCell>
                    <StatusBadge status={d.deployment_status} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <Pagination meta={meta} onPage={setPage} />
        </div>
      )}
    </div>
  )
}
