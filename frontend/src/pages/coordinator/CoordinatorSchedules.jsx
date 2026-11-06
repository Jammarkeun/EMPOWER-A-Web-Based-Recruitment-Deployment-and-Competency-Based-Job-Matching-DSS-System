import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { CalendarClock, ChevronRight, Plus } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { Pagination, useClampPage } from '@/components/Pagination'
import { ScheduleStatusBadge } from '@/components/attendance/ScheduleSubmissionParts'
import { SCHEDULE_STATUS } from '@/lib/attendance'
import { formatDate, formatDateTime } from '@/lib/utils'

/**
 * The work schedules the coordinator has written for workers at their client.
 * Each goes to HR; only an approved one is compared with the DTR. Returned
 * ones come first, because they are waiting on the coordinator.
 */
export default function CoordinatorSchedules() {
  const [searchParams, setSearchParams] = useSearchParams()
  const status = searchParams.get('status') ?? ''
  const search = searchParams.get('search') ?? ''
  const page = Number(searchParams.get('page') ?? 1)
  const debouncedSearch = useDebounced(search)

  const { data, meta, loading, error, refetch } = useApi('/coordinator/schedules', {
    status: status || 'open',
    search: debouncedSearch || undefined,
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

  useClampPage(meta, (p) => update('page', String(p)))
  const counts = meta?.status_counts ?? {}

  return (
    <div className="space-y-4">
      <PageHeader
        title="Work Schedules"
        description="Write down the hours your client gave each worker and send them to HR. A schedule is compared with the DTR only after HR approves it."
        actions={
          <Button asChild>
            <Link to="/coordinator/schedules/new">
              <Plus className="h-4 w-4" />
              New schedule
            </Link>
          </Button>
        }
      />

      <FilterToolbar
        search={search}
        onSearchChange={(value) => update('search', value)}
        searchPlaceholder="Search by name, employee no. or WS code…"
        filters={[
          {
            id: 'status',
            label: 'Not yet decided',
            value: status,
            onChange: (value) => update('status', value),
            options: [
              { value: '', label: 'Not yet decided' },
              ...Object.entries(SCHEDULE_STATUS).map(([value, s]) => ({
                value,
                label: counts[value] ? `${s.label} (${counts[value]})` : s.label,
              })),
              { value: 'all', label: 'All schedules' },
            ],
          },
        ]}
        resultCount={meta?.total}
        resultLabel="schedules"
        onClearFilters={() => setSearchParams(new URLSearchParams())}
      />

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={5} columns={5} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={CalendarClock}
          title={status ? 'No schedules in this view' : 'Nothing waiting'}
          description="When the client gives a worker their hours or changes them, write the schedule here for HR to approve."
          action={
            <Button size="sm" asChild>
              <Link to="/coordinator/schedules/new">New schedule</Link>
            </Button>
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Schedule</TableHead>
                <TableHead>Employee</TableHead>
                <TableHead>Hours</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-8" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((s) => (
                <TableRow key={s.id} className="group">
                  <TableCell>
                    <Link to={`/coordinator/schedules/${s.id}`} className="block">
                      <p className="font-mono text-sm font-semibold group-hover:text-brand">{s.submission_code}</p>
                      <p className="text-xs text-muted-foreground">
                        {s.created_by} · {formatDateTime(s.submitted_at ?? s.created_at)}
                      </p>
                    </Link>
                  </TableCell>
                  <TableCell>
                    <p className="text-sm font-medium">{s.employee.full_name}</p>
                    <p className="text-xs text-muted-foreground">
                      <span className="font-mono">{s.employee.employee_number}</span>
                      {s.department && ` · ${s.department}`}
                    </p>
                  </TableCell>
                  <TableCell className="text-sm">
                    {s.description}
                    <span className="block text-xs text-muted-foreground">
                      From {formatDate(s.effective_from)}
                      {s.effective_to && ` to ${formatDate(s.effective_to)}`}
                    </span>
                  </TableCell>
                  <TableCell>
                    <ScheduleStatusBadge status={s.status} />
                    {s.status === 'returned' && s.review_note && (
                      <p className="mt-1 max-w-xs truncate text-xs text-muted-foreground" title={s.review_note}>
                        {s.review_note}
                      </p>
                    )}
                  </TableCell>
                  <TableCell className="text-right">
                    <Link to={`/coordinator/schedules/${s.id}`} className="text-muted-foreground group-hover:text-brand" aria-label={`Open schedule ${s.submission_code}`}>
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
