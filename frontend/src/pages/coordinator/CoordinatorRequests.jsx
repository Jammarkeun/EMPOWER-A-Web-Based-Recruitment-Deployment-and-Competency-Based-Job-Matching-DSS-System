import * as React from 'react'
import { ClipboardList } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { formatDate } from '@/lib/utils'
import { FillBar } from '@/pages/JobRequests'
import { Pagination } from '@/pages/Applicants'

/**
 * The client's manpower requests and how far each has been filled.
 *
 * Status only. Who is being considered, and how they ranked, is CDE's
 * recruitment work and is not shown to the coordinator.
 */
export default function CoordinatorRequests() {
  const [openOnly, setOpenOnly] = React.useState(true)
  const [page, setPage] = React.useState(1)

  React.useEffect(() => setPage(1), [openOnly])

  const { data, meta, loading, error, refetch } = useApi('/coordinator/manpower-requests', {
    only_open: openOnly ? 1 : undefined,
    page,
    per_page: 25,
  })

  return (
    <div className="space-y-4">
      <PageHeader title="Manpower Requests" description="What your client has asked CDE for, and how far each request is filled." />

      <FilterToolbar
        viewToggles={[{ id: 'open', label: 'Open only', active: openOnly, onToggle: () => setOpenOnly(!openOnly) }]}
        resultCount={meta?.total}
        resultLabel="requests"
      />

      {loading ? (
        <div className="rounded-panel border bg-card">
          <SkeletonRows rows={5} columns={5} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState icon={ClipboardList} title={openOnly ? 'No open requests' : 'No requests yet'} />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Position</TableHead>
                <TableHead>Department</TableHead>
                <TableHead>Filled</TableHead>
                <TableHead>Deadline</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((r) => (
                <TableRow key={r.id}>
                  <TableCell>
                    <p className="font-medium">{r.position_title}</p>
                    <p className="font-mono text-xs text-muted-foreground">
                      {r.request_code} · {formatDate(r.date_requested)}
                    </p>
                  </TableCell>
                  <TableCell className="text-sm">{r.department ?? '—'}</TableCell>
                  <TableCell className="min-w-[8rem]">
                    <FillBar filled={r.workers_fulfilled} needed={r.workers_needed} />
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                    {formatDate(r.deployment_deadline, 'None set')}
                  </TableCell>
                  <TableCell>
                    <StatusBadge status={r.request_status} />
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
