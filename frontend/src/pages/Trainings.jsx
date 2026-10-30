import * as React from 'react'
import { Link } from 'react-router-dom'
import { Plus, GraduationCap, Hourglass } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Select } from '@/components/ui/select'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import TrainingSessionDialog from '@/components/training/TrainingSessionDialog'
import { Pagination } from '@/components/Pagination'
import { formatDate, humanise } from '@/lib/utils'

const SESSION_STATUSES = ['scheduled', 'ongoing', 'completed', 'cancelled']

export default function Trainings() {
  const { can } = useAuth()
  const [dialogOpen, setDialogOpen] = React.useState(false)
  const [status, setStatus] = React.useState('')
  const [page, setPage] = React.useState(1)
  const { data, meta, loading, error, refetch } = useApi('/trainings', {
    status: status || undefined,
    page,
    per_page: 20,
  })

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

      <div className="mb-3 flex items-center gap-3">
        <Select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value)
            setPage(1)
          }}
          placeholder="All sessions"
          aria-label="Filter sessions by status"
          className="sm:w-48"
        >
          {SESSION_STATUSES.map((value) => (
            <option key={value} value={value}>
              {humanise(value)}
            </option>
          ))}
        </Select>
        {meta?.total !== undefined && (
          <span className="text-xs text-muted-foreground">
            {meta.total} session{meta.total === 1 ? '' : 's'}
          </span>
        )}
      </div>

      <Card>
        {loading ? (
          <SkeletonRows rows={5} columns={5} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={GraduationCap}
            title={status ? `No ${humanise(status).toLowerCase()} sessions` : 'No training scheduled'}
            description={
              status
                ? 'Choose another status, or show all sessions.'
                : 'Schedule a session and enrol applicants who are ready for deployment.'
            }
            action={
              can('training.create') && !status ? (
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
                    <TableCell className="whitespace-nowrap text-sm">{formatDate(training.training_date)}</TableCell>
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
              <Pagination meta={meta} onPage={setPage} />
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
