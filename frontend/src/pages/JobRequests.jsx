import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Plus, ClipboardList, ChevronRight } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { SortableHead, Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useTableSort } from '@/hooks/useTableSort'
import { Button } from '@/components/ui/button'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { formatDate } from '@/lib/utils'
import { ProgressTrack } from '@/components/ui/panel'
import { Pagination, useClampPage } from '@/components/Pagination'
import NewJobRequestDialog from '@/components/dialogs/NewJobRequestDialog'

export default function JobRequests() {
  const { can } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const [dialogOpen, setDialogOpen] = React.useState(false)

  const status = searchParams.get('request_status') ?? ''
  const onlyOpen = searchParams.get('only_open') === '1'
  // Still short of workers after the deadline: the dashboard figure's list.
  const overdue = searchParams.get('overdue') === '1'
  const client = searchParams.get('client_company_id') ?? ''
  const page = Number(searchParams.get('page') ?? 1)
  // Newest request first; the Deadline header sorts soonest first instead.
  const sort = useTableSort(searchParams, setSearchParams, {
    by: 'date_requested',
    dir: 'desc',
    firstDirection: { deployment_deadline: 'asc' },
  })
  const { data: clients } = useApi('/clients', { per_page: 100 }, { cacheKey: 'clients-all' })

  const { data, meta, loading, error, refetch } = useApi('/job-requests', {
    request_status: status || undefined,
    only_open: onlyOpen ? 1 : undefined,
    overdue: overdue ? 1 : undefined,
    client_company_id: client || undefined,
    ...sort.params,
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

  const filtered = Boolean(status || client || onlyOpen || overdue)
  useClampPage(meta, (p) => updateFilter('page', String(p)))

  return (
    <div className="space-y-4">
      <PageHeader
        title="Manpower Requests"
        description="Client staffing requests, open headcount, and fulfillment progress. Approved coordinator requisitions appear here automatically."
        actions={
          can('job_requests.create') && (
            <Button variant="outline" onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Create Direct Request
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
        viewToggles={[
          {
            id: 'only_open',
            label: 'Needing workers only',
            active: onlyOpen,
            onToggle: () => updateFilter('only_open', onlyOpen ? '' : '1'),
          },
          {
            id: 'overdue',
            label: 'Past deadline',
            title: 'Still needing workers after the deployment deadline.',
            active: overdue,
            onToggle: () => updateFilter('overdue', overdue ? '' : '1'),
          },
        ]}
        resultCount={meta?.total}
        resultLabel="requests"
        onClearFilters={clearFilters}
      />

      {loading ? (
        // A loading placeholder, not table rows, so it sits in the table's
        // panel rather than inside a <table>.
        <div className="w-full rounded-panel bg-card p-3 sm:p-4">
          <SkeletonRows rows={6} columns={6} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={ClipboardList}
          title={filtered ? 'No manpower requests match these filters' : 'No manpower requests recorded yet'}
          description={
            filtered
              ? 'Try another status, or show every request.'
              : 'Approved coordinator requisitions appear here. Create a direct request only when a client asks without one.'
          }
          action={
            filtered ? (
              <Button size="sm" variant="outline" onClick={clearFilters}>
                Clear filters
              </Button>
            ) : can('job_requests.create') ? (
              <Button size="sm" onClick={() => setDialogOpen(true)}>
                <Plus className="h-4 w-4" />
                Create Direct Request
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
                <SortableHead column="deployment_deadline" {...sort.head}>Deadline</SortableHead>
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
                    <p className="text-xs text-muted-foreground">
                      {request.source_requisition ? (
                        <>
                          From{' '}
                          <Link
                            to={`/requisitions/${request.source_requisition.id}`}
                            className="font-mono text-primary hover:underline"
                          >
                            {request.source_requisition.requisition_code}
                          </Link>
                        </>
                      ) : (
                        'Direct request'
                      )}
                    </p>
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
                    <Link to={`/job-requests/${request.id}`} className="text-muted-foreground group-hover:text-brand" aria-label={`Open request ${request.request_code}`}>
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

/** Shows fill progress: "X of Y filled" and the percentage above a striped track. */
export function FillBar({ filled, needed }) {
  const percentage = needed > 0 ? Math.min(100, (filled / needed) * 100) : 0
  const complete = filled >= needed

  return (
    <div className="min-w-[140px] space-y-2">
      <div className="flex items-center justify-between gap-3 text-xs tabular-nums">
        <span className="font-medium text-foreground">
          {filled} of {needed} filled
        </span>
        <span className="text-muted-foreground">{Math.round(percentage)}%</span>
      </div>
      <ProgressTrack value={percentage} tone={complete ? 'success' : 'default'} className="h-2" />
    </div>
  )
}
