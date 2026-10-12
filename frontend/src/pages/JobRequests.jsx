import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Plus, ClipboardList, ChevronRight } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { cn, formatDate } from '@/lib/utils'
import { Pagination } from './Applicants'
import NewJobRequestDialog from '@/components/dialogs/NewJobRequestDialog'

export default function JobRequests() {
  const { can } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const [dialogOpen, setDialogOpen] = React.useState(false)

  const status = searchParams.get('request_status') ?? ''
  const onlyOpen = searchParams.get('only_open') === '1'
  const page = Number(searchParams.get('page') ?? 1)

  const { data, meta, loading, error, refetch } = useApi('/job-requests', {
    request_status: status || undefined,
    only_open: onlyOpen || undefined,
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

  return (
    <div className="space-y-4">
      <PageHeader
        title="Manpower Requests"
        description="Client staffing requests, open headcount, and fulfillment progress."
        actions={
          can('job_requests.create') && (
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Record Request
            </Button>
          )
        }
      />

      <FilterToolbar
        filters={[
          {
            id: 'request_status',
            label: 'All Statuses',
            value: status,
            onChange: (val) => updateFilter('request_status', val),
            options: [
              { value: '', label: 'All Statuses' },
              { value: 'open', label: 'Open' },
              { value: 'in_progress', label: 'In progress' },
              { value: 'partially_fulfilled', label: 'Partially fulfilled' },
              { value: 'fulfilled', label: 'Fulfilled' },
              { value: 'closed', label: 'Closed' },
              { value: 'cancelled', label: 'Cancelled' },
            ],
          },
        ]}
        viewToggles={[
          {
            id: 'only_open',
            label: 'Needing workers only',
            active: onlyOpen,
            onToggle: () => updateFilter('only_open', onlyOpen ? '' : '1'),
          },
        ]}
        resultCount={meta?.total}
        resultLabel="requests"
        onClearFilters={clearFilters}
      />

      {loading ? (
        <Table>
          <SkeletonRows rows={6} columns={6} />
        </Table>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={ClipboardList}
          title="No manpower requests found"
          description="Record a request when a client company asks for workers."
          action={
            can('job_requests.create') ? (
              <Button size="sm" onClick={() => setDialogOpen(true)}>
                <Plus className="h-4 w-4" />
                Record Request
              </Button>
            ) : null
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Position & Code</TableHead>
                <TableHead>Client & Department</TableHead>
                <TableHead>Fulfillment Progress</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Deadline</TableHead>
                <TableHead>Matching Criteria</TableHead>
                <TableHead className="w-8"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((request) => (
                <TableRow key={request.id} className="group cursor-pointer">
                  {/* Position title primary, request code muted mono secondary */}
                  <TableCell>
                    <Link to={`/job-requests/${request.id}`} className="block">
                      <p className="font-semibold text-foreground group-hover:text-brand transition-colors">
                        {request.position_title}
                      </p>
                      <p className="font-mono text-xs text-muted-foreground tabular-nums">
                        {request.request_code}
                      </p>
                    </Link>
                  </TableCell>

                  {/* Client & Department */}
                  <TableCell>
                    <p className="text-sm font-medium text-foreground">{request.company_name}</p>
                    <p className="text-xs text-muted-foreground">{request.department_name || 'All Departments'}</p>
                  </TableCell>

                  {/* Fulfillment Progress Column */}
                  <TableCell>
                    <FillBar filled={request.workers_fulfilled} needed={request.workers_needed} />
                  </TableCell>

                  {/* Status Badge */}
                  <TableCell>
                    <StatusBadge status={request.request_status} />
                  </TableCell>

                  {/* Deadline Column */}
                  <TableCell className="whitespace-nowrap font-mono text-xs tabular-nums">
                    {request.deployment_deadline ? (
                      <span className={request.is_overdue ? 'font-semibold text-status-danger-text' : 'text-muted-foreground'}>
                        {formatDate(request.deployment_deadline)}
                        {request.is_overdue && ' (Overdue)'}
                      </span>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </TableCell>

                  {/* Criteria Tag (4px radius rounded-tag, NOT pill) */}
                  <TableCell>
                    {request.criteria_count > 0 ? (
                      <span className="inline-flex items-center text-xs font-medium bg-status-info-bg text-status-info-text border border-status-info-border px-2 py-0.5 rounded-tag tabular-nums">
                        {request.criteria_count} configured
                      </span>
                    ) : (
                      <span className="inline-flex items-center text-xs font-medium bg-status-warning-bg text-status-warning-text border border-status-warning-border px-2 py-0.5 rounded-tag">
                        Not configured
                      </span>
                    )}
                  </TableCell>

                  {/* Action Link */}
                  <TableCell className="text-right">
                    <Link to={`/job-requests/${request.id}`} className="text-muted-foreground group-hover:text-brand">
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

      <NewJobRequestDialog open={dialogOpen} onOpenChange={setDialogOpen} onCreated={refetch} />
    </div>
  )
}

/** Shows fill progress: primary text "X of Y filled", small 4px progress bar below. */
export function FillBar({ filled, needed }) {
  const percentage = needed > 0 ? Math.min(100, (filled / needed) * 100) : 0
  const complete = filled >= needed

  return (
    <div className="min-w-[120px] space-y-1">
      <div className="flex items-center justify-between text-xs font-mono tabular-nums">
        <span className="font-semibold text-foreground">
          {filled} of {needed} filled
        </span>
        <span className="text-muted-foreground font-sans">{Math.round(percentage)}%</span>
      </div>
      <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
        <div
          className={cn('h-full rounded-full transition-all duration-300', complete ? 'bg-status-success-text' : 'bg-brand')}
          style={{ width: `${percentage}%` }}
        />
      </div>
    </div>
  )
}
