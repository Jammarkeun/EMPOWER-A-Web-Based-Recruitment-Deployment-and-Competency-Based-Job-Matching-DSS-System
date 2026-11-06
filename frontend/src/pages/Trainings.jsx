import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Plus, GraduationCap, Hourglass } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { FilterToolbar } from '@/components/FilterToolbar'
import { useAuth } from '@/contexts/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import TrainingSessionDialog from '@/components/training/TrainingSessionDialog'
import { Pagination, useClampPage } from '@/components/Pagination'
import { formatDate, formatTimeRange, humanise } from '@/lib/utils'

const SESSION_STATUSES = ['scheduled', 'ongoing', 'completed', 'cancelled']

export default function Trainings() {
  const { can } = useAuth()
  const [dialogOpen, setDialogOpen] = React.useState(false)
  // In the address bar, like every other list, so a filtered list survives a
  // refresh and the back button.
  const [searchParams, setSearchParams] = useSearchParams()
  const search = searchParams.get('search') ?? ''
  const status = searchParams.get('status') ?? ''
  const page = Number(searchParams.get('page') ?? 1)
  const debouncedSearch = useDebounced(search)
  const filtered = Boolean(search || status)

  const { data, meta, loading, error, refetch } = useApi('/trainings', {
    search: debouncedSearch || undefined,
    status: status || undefined,
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

  useClampPage(meta, (p) => updateFilter('page', String(p)))

  return (
    <>
      <PageHeader
        title="Training"
        description="Orientation and skills sessions run before deployment."
        actions={
          can('training.create') && (
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Schedule training
            </Button>
          )
        }
      />

      {can('applicants.view') && <AwaitingTraining />}

      <FilterToolbar
        className="mb-3"
        search={search}
        onSearchChange={(val) => updateFilter('search', val)}
        searchPlaceholder="Search by title, code, location or trainer…"
        filters={[
          {
            id: 'status',
            label: 'All sessions',
            value: status,
            onChange: (val) => updateFilter('status', val),
            options: [
              { value: '', label: 'All sessions' },
              ...SESSION_STATUSES.map((value) => ({ value, label: humanise(value) })),
            ],
          },
        ]}
        resultCount={meta?.total}
        resultLabel={`session${meta?.total === 1 ? '' : 's'}, by title A–Z`}
        onClearFilters={() => setSearchParams(new URLSearchParams())}
      />

      <Card>
        {loading ? (
          <SkeletonRows rows={5} columns={5} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={GraduationCap}
            title={
              search
                ? 'No sessions match this search'
                : status
                  ? `No ${humanise(status).toLowerCase()} sessions`
                  : 'No training scheduled'
            }
            description={
              filtered
                ? 'Search for part of the title, code, location or trainer, or show all sessions.'
                : 'Schedule a session and enrol applicants who are ready for deployment.'
            }
            action={
              filtered ? (
                <Button size="sm" variant="outline" onClick={() => setSearchParams(new URLSearchParams())}>
                  Clear filters
                </Button>
              ) : can('training.create') ? (
                <Button size="sm" onClick={() => setDialogOpen(true)}>
                  <Plus className="h-4 w-4" />
                  Schedule training
                </Button>
              ) : null
            }
          />
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Session</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Location</TableHead>
                  <TableHead>Trainer</TableHead>
                  <TableHead>Attendance</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((training) => (
                  <TableRow key={training.id}>
                    <TableCell>
                      <Link to={`/trainings/${training.id}`} className="font-medium hover:text-brand">
                        {training.training_title}
                      </Link>
                      <p className="font-mono text-xs text-muted-foreground">{training.training_code}</p>
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm">
                      {formatDate(training.training_date)}
                      {training.start_time && (
                        <p className="text-xs text-muted-foreground">
                          {formatTimeRange(training.start_time, training.end_time)}
                        </p>
                      )}
                    </TableCell>
                    <TableCell className="text-sm">{training.location}</TableCell>
                    <TableCell className="text-sm">{training.trainer_name}</TableCell>
                    <TableCell className="text-sm tabular-nums">
                      {training.attended_count ?? 0} present of {training.enrollments_count ?? 0} enrolled
                      <p className="text-xs text-muted-foreground">{training.completed_count ?? 0} completed</p>
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={training.status} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <div className="px-4 pb-3">
              <Pagination meta={meta} onPage={(p) => updateFilter('page', String(p))} />
            </div>
          </>
        )}
      </Card>

      <TrainingSessionDialog open={dialogOpen} onOpenChange={setDialogOpen} onSaved={refetch} />
    </>
  )
}

/**
 * Who still needs training before they can be endorsed to a client.
 *
 * The endorsement gate refuses anyone without a recorded completion, so this
 * is the list an officer works from when filling a session.
 */
function AwaitingTraining() {
  const { data, loading } = useApi('/trainings/candidates', { awaiting_only: 1 })

  if (loading || !data?.length) return null

  const shown = data.slice(0, 6)

  return (
    <div className="mb-5 rounded-panel bg-card p-5">
      <div className="flex items-start gap-3">
        <Hourglass className="mt-0.5 h-4 w-4 shrink-0 text-text-subtle" aria-hidden="true" />
        <div className="min-w-0 flex-1">
          <p className="text-[15px] font-medium">
            {data.length} applicant{data.length === 1 ? '' : 's'} waiting for training
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            None of them can be endorsed to a client until a completed session is recorded. Enrol them from a session's page.
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            {shown.map((applicant) => (
              <Link
                key={applicant.id}
                to={`/applicants/${applicant.id}`}
                className="rounded-full bg-inset px-3 py-1 text-xs font-medium hover:text-brand"
              >
                {applicant.full_name}
                {applicant.scheduled_training ? ' · scheduled' : ''}
              </Link>
            ))}
            {data.length > shown.length && (
              <span className="px-1 py-1 text-xs text-muted-foreground">and {data.length - shown.length} more</span>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
