import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Plus, ClipboardList } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Select } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { formatDate } from '@/lib/utils'
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

  return (
    <>
      <PageHeader
        title="Manpower requests"
        description="Staffing requests received from client companies."
        actions={
          can('job_requests.create') && (
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Record request
            </Button>
          )
        }
      />

      <Card className="mb-4">
        <CardContent className="flex flex-col gap-3 pt-5 sm:flex-row">
          <Select
            value={status}
            onChange={(e) => updateFilter('request_status', e.target.value)}
            placeholder="All statuses"
            aria-label="Filter by status"
            className="sm:w-56"
          >
            <option value="open">Open</option>
            <option value="in_progress">In progress</option>
            <option value="partially_fulfilled">Partially fulfilled</option>
            <option value="fulfilled">Fulfilled</option>
            <option value="closed">Closed</option>
            <option value="cancelled">Cancelled</option>
          </Select>

          <Button
            variant={onlyOpen ? 'default' : 'outline'}
            onClick={() => updateFilter('only_open', onlyOpen ? '' : '1')}
          >
            Still needing workers
          </Button>
        </CardContent>
      </Card>

      <Card>
        {loading ? (
          <SkeletonRows rows={6} columns={6} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={ClipboardList}
            title="No manpower requests"
            description="Record a request when a client company asks for workers."
            action={
              can('job_requests.create') ? (
                <Button size="sm" onClick={() => setDialogOpen(true)}>
                  <Plus className="h-4 w-4" />
                  Record request
                </Button>
              ) : null
            }
          />
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Position</TableHead>
                  <TableHead>Client</TableHead>
                  <TableHead>Progress</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Deadline</TableHead>
                  <TableHead>Criteria</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((request) => (
                  <TableRow key={request.id}>
                    <TableCell>
                      <Link
                        to={`/job-requests/${request.id}`}
                        className="font-medium text-primary hover:underline"
                      >
                        {request.position_title}
                      </Link>
                      <p className="font-mono text-xs text-muted-foreground">{request.request_code}</p>
                    </TableCell>
                    <TableCell className="text-sm">
                      {request.company_name}
                      <p className="text-xs text-muted-foreground">{request.department_name}</p>
                    </TableCell>
                    <TableCell>
                      <FillBar filled={request.workers_fulfilled} needed={request.workers_needed} />
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={request.request_status} />
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm">
                      {request.deployment_deadline ? (
                        <span className={request.is_overdue ? 'font-medium text-destructive' : ''}>
                          {formatDate(request.deployment_deadline)}
                          {request.is_overdue && ' (overdue)'}
                        </span>
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </TableCell>
                    <TableCell>
                      {request.criteria_count > 0 ? (
                        <Badge tone="info">{request.criteria_count} configured</Badge>
                      ) : (
                        <Badge tone="warning">Not configured</Badge>
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

      <NewJobRequestDialog open={dialogOpen} onOpenChange={setDialogOpen} onCreated={refetch} />
    </>
  )
}

/** Shows fill progress as a bar plus the raw numbers, since the bar alone is imprecise. */
export function FillBar({ filled, needed }) {
  const percentage = needed > 0 ? Math.min(100, (filled / needed) * 100) : 0
  const complete = filled >= needed

  return (
    <div className="min-w-[100px] space-y-1">
      <div className="flex items-center justify-between gap-2 text-xs">
        <span className="font-medium tabular-nums">
          {filled}/{needed}
        </span>
        <span className="text-muted-foreground">{Math.round(percentage)}%</span>
      </div>
      <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
        <div
          className={complete ? 'h-full rounded-full bg-success' : 'h-full rounded-full bg-primary'}
          style={{ width: `${percentage}%` }}
        />
      </div>
    </div>
  )
}
