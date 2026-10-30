import * as React from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  ArrowLeft,
  CalendarClock,
  CheckCircle2,
  GraduationCap,
  Loader2,
  Pencil,
  RotateCcw,
  Search,
  UserMinus,
  UserPlus,
  Users,
  XCircle,
} from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { del, patch, post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader, StatTile } from '@/components/PageHeader'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import TrainingSessionDialog from '@/components/training/TrainingSessionDialog'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn, formatDate, formatDateTime } from '@/lib/utils'

/**
 * One training session and its roster (audit R-06).
 *
 * Training used to be a session record and nothing else, so the endorsement
 * gate - which needs a recorded completion - could only be satisfied by typing
 * a status. Here an officer enrols applicants, marks who attended, and records
 * who completed, each against their own name and time. The applicant's status
 * follows the roster; nothing on this screen sets a status directly, and the
 * server enforces every rule shown here whether or not the screen does.
 */
export default function TrainingDetail() {
  const { id } = useParams()
  const { can } = useAuth()
  const toast = useToast()
  const { data: session, loading, error, refetch } = useApi(`/trainings/${id}`)

  const [enrolOpen, setEnrolOpen] = React.useState(false)
  const [editOpen, setEditOpen] = React.useState(false)
  const [confirm, setConfirm] = React.useState(null)
  const [busyId, setBusyId] = React.useState(null)

  const canEdit = can('training.update')
  const breadcrumbs = [{ label: 'Training', to: '/trainings' }, { label: session?.training_code ?? 'Session' }]

  async function record(enrollment, values, success) {
    setBusyId(enrollment.id)
    try {
      const response = await patch(`/trainings/${id}/enrollments/${enrollment.id}`, values)
      toast.success(success ?? response.message ?? 'Saved')
      refetch()
    } catch (err) {
      toast.error('Not saved', err.message)
    } finally {
      setBusyId(null)
    }
  }

  async function changeSessionStatus(status) {
    try {
      await patch(`/trainings/${id}`, { status })
      toast.success(
        { cancelled: 'Session cancelled', completed: 'Session marked completed', scheduled: 'Session reopened' }[status] ??
          'Session updated'
      )
      refetch()
    } catch (err) {
      toast.error('Session not updated', err.message)
    }
  }

  if (loading) {
    return (
      <>
        <PageHeader breadcrumbs={breadcrumbs} title="Training session" />
        <LoadingState label="Loading the roster…" />
      </>
    )
  }

  if (error || !session) {
    return (
      <>
        <PageHeader breadcrumbs={breadcrumbs} title="Training session" actions={<BackButton />} />
        <Card>
          <ErrorState
            message={error?.status === 404 ? 'This training session does not exist.' : error?.message}
            onRetry={error?.status === 404 ? undefined : refetch}
          />
        </Card>
      </>
    )
  }

  const cancelled = session.status === 'cancelled'
  const notYet = !cancelled && !session.can_record_outcomes

  return (
    <>
      <PageHeader
        breadcrumbs={breadcrumbs}
        title={session.training_title}
        description={`${formatDate(session.training_date)} · ${session.location} · ${session.trainer_name}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={session.status} />
            {canEdit && session.accepts_enrolment && (
              <Button onClick={() => setEnrolOpen(true)}>
                <UserPlus className="h-4 w-4" />
                Enrol applicants
              </Button>
            )}
            {canEdit && !cancelled && (
              <Button variant="outline" onClick={() => setEditOpen(true)}>
                <Pencil className="h-4 w-4" />
                Edit details
              </Button>
            )}
            {canEdit && ['scheduled', 'ongoing'].includes(session.status) && session.can_record_outcomes && (
              <Button variant="outline" onClick={() => changeSessionStatus('completed')}>
                Mark session completed
              </Button>
            )}
            {canEdit && session.status === 'completed' && (
              <Button
                variant="ghost"
                onClick={() =>
                  setConfirm({
                    title: 'Reopen this session?',
                    description: `${session.training_title} goes back to scheduled so a late attendee can be enrolled.`,
                    consequences: ['Everything already recorded on the roster stays as it is.'],
                    confirmLabel: 'Reopen session',
                    tone: 'warning',
                    onConfirm: () => changeSessionStatus('scheduled'),
                  })
                }
              >
                <RotateCcw className="h-4 w-4" />
                Reopen
              </Button>
            )}
            {canEdit && !cancelled && session.counts.completed === 0 && (
              <Button
                variant="ghost"
                onClick={() =>
                  setConfirm({
                    title: 'Cancel this session?',
                    description: `${session.training_title} on ${formatDate(session.training_date)} will be marked cancelled.`,
                    consequences: [
                      'Applicants scheduled only for this session go back to Ready for Deployment.',
                      'Nobody can be enrolled in it, and no attendance can be recorded for it.',
                    ],
                    confirmLabel: 'Cancel session',
                    onConfirm: () => changeSessionStatus('cancelled'),
                  })
                }
              >
                Cancel session
              </Button>
            )}
          </div>
        }
      />

      <div className="mb-5 grid gap-3 sm:grid-cols-4">
        <StatTile label="Enrolled" value={session.counts.enrolled} icon={Users} />
        <StatTile label="Present" value={session.counts.present} icon={CheckCircle2} tone="success" />
        <StatTile label="Absent" value={session.counts.absent} icon={XCircle} />
        <StatTile label="Completed" value={session.counts.completed} icon={GraduationCap} tone="success" />
      </div>

      {session.remarks && (
        <p className="mb-4 rounded-row bg-inset px-4 py-3 text-sm text-muted-foreground">
          <span className="font-medium text-foreground">Remarks: </span>
          {session.remarks}
        </p>
      )}

      {(notYet || cancelled) && (
        <div className="mb-4 flex items-start gap-3 rounded-row bg-status-info-bg px-4 py-3 text-sm text-status-info-text">
          <CalendarClock className="mt-0.5 h-4 w-4 shrink-0" />
          {cancelled
            ? 'This session was cancelled. Its roster is kept as a record; nothing more can be recorded against it.'
            : `This session has not taken place yet. Attendance and completion can be recorded from ${formatDate(session.training_date)}.`}
        </div>
      )}

      <Card>
        {session.enrollments.length === 0 ? (
          <EmptyState
            icon={Users}
            title="Nobody enrolled yet"
            description="Enrol applicants who are ready for deployment. Enrolling schedules them for this session."
            action={
              canEdit && session.accepts_enrolment ? (
                <Button size="sm" onClick={() => setEnrolOpen(true)}>
                  <UserPlus className="h-4 w-4" />
                  Enrol applicants
                </Button>
              ) : null
            }
          />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Applicant</TableHead>
                <TableHead>Attendance</TableHead>
                <TableHead>Completion</TableHead>
                <TableHead className="text-right">
                  <span className="sr-only">Actions</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {session.enrollments.map((enrollment) => {
                const busy = busyId === enrollment.id
                const locked = !canEdit || cancelled || notYet || busy
                const completed = enrollment.completion_status === 'completed'

                return (
                  <TableRow key={enrollment.id}>
                    <TableCell>
                      <Link to={`/applicants/${enrollment.applicant.id}`} className="font-medium hover:text-brand">
                        {enrollment.applicant.full_name}
                      </Link>
                      <div className="mt-1 flex items-center gap-2">
                        <span className="font-mono text-xs text-muted-foreground">{enrollment.applicant.applicant_code}</span>
                        <StatusBadge status={enrollment.applicant.current_status} />
                      </div>
                    </TableCell>

                    <TableCell>
                      <AttendanceToggle
                        value={enrollment.attendance_status}
                        disabled={locked || completed}
                        onChange={(value) => record(enrollment, { attendance_status: value }, 'Attendance updated')}
                      />
                      <RecordedBy name={enrollment.attendance_recorded_by} at={enrollment.attendance_recorded_at} />
                    </TableCell>

                    <TableCell>
                      {completed ? (
                        <div className="space-y-1">
                          <Badge tone="success">Completed</Badge>
                          <RecordedBy name={enrollment.completion_recorded_by} at={enrollment.completion_recorded_at} />
                          {canEdit && !cancelled && (
                            <Button
                              size="sm"
                              variant="link"
                              className="h-auto px-0"
                              disabled={busy}
                              onClick={() =>
                                setConfirm({
                                  title: 'Withdraw this completion?',
                                  description: `Only for a completion entered in error. ${enrollment.applicant.full_name} goes back to Training Scheduled.`,
                                  consequences: [
                                    'Refused if the applicant has already been endorsed on the strength of this training.',
                                  ],
                                  confirmLabel: 'Withdraw completion',
                                  tone: 'warning',
                                  onConfirm: () => record(enrollment, { completion_status: 'not_completed' }, 'Completion withdrawn'),
                                })
                              }
                            >
                              Withdraw
                            </Button>
                          )}
                        </div>
                      ) : (
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={locked || enrollment.attendance_status !== 'present'}
                          title={enrollment.attendance_status !== 'present' ? 'Mark the applicant present first.' : undefined}
                          onClick={() => record(enrollment, { completion_status: 'completed' }, 'Completion recorded')}
                        >
                          {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <GraduationCap className="h-4 w-4" />}
                          Record completion
                        </Button>
                      )}
                    </TableCell>

                    <TableCell className="text-right">
                      {canEdit && enrollment.can_remove && !cancelled && (
                        <Button
                          size="icon"
                          variant="ghost"
                          aria-label={`Remove ${enrollment.applicant.full_name} from the roster`}
                          title="Remove from roster"
                          disabled={busy}
                          onClick={() =>
                            setConfirm({
                              title: 'Remove from the roster?',
                              description: `${enrollment.applicant.full_name} was enrolled by mistake and nothing has been recorded for them.`,
                              consequences: ['If no other session is lined up for them, they go back to Ready for Deployment.'],
                              confirmLabel: 'Remove',
                              onConfirm: async () => {
                                try {
                                  await del(`/trainings/${id}/enrollments/${enrollment.id}`)
                                  toast.success('Removed from the roster')
                                  refetch()
                                } catch (err) {
                                  toast.error('Not removed', err.message)
                                }
                              },
                            })
                          }
                        >
                          <UserMinus className="h-4 w-4" />
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        )}
      </Card>

      <EnrolDialog open={enrolOpen} onOpenChange={setEnrolOpen} session={session} onEnrolled={refetch} />

      <TrainingSessionDialog open={editOpen} onOpenChange={setEditOpen} session={session} onSaved={refetch} />

      <ConfirmActionDialog
        open={Boolean(confirm)}
        onOpenChange={(open) => !open && setConfirm(null)}
        title={confirm?.title}
        description={confirm?.description}
        consequences={confirm?.consequences ?? []}
        confirmLabel={confirm?.confirmLabel}
        tone={confirm?.tone ?? 'destructive'}
        onConfirm={async () => {
          await confirm?.onConfirm()
          setConfirm(null)
        }}
      />
    </>
  )
}

function BackButton() {
  return (
    <Button variant="outline" asChild>
      <Link to="/trainings">
        <ArrowLeft className="h-4 w-4" />
        All sessions
      </Link>
    </Button>
  )
}

const ATTENDANCE = [
  { value: 'pending', label: 'Not recorded' },
  { value: 'present', label: 'Present' },
  { value: 'absent', label: 'Absent' },
]

/** Present and absent are recorded facts; neither implies completion. */
function AttendanceToggle({ value, disabled, onChange }) {
  return (
    <div role="radiogroup" aria-label="Attendance" className="inline-flex rounded-full bg-inset p-1">
      {ATTENDANCE.map((option) => (
        <button
          key={option.value}
          type="button"
          role="radio"
          aria-checked={value === option.value}
          disabled={disabled || value === option.value}
          onClick={() => onChange(option.value)}
          className={cn(
            'rounded-full px-3 py-1 text-xs font-medium transition-colors disabled:cursor-not-allowed',
            value === option.value ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground',
            disabled && value !== option.value && 'opacity-50'
          )}
        >
          {option.label}
        </button>
      ))}
    </div>
  )
}

function RecordedBy({ name, at }) {
  if (!name) return null
  return (
    <p className="mt-1 text-[11px] text-muted-foreground">
      Recorded by {name}
      {at ? ` · ${formatDateTime(at)}` : ''}
    </p>
  )
}

/**
 * Choose who to enrol. Everyone listed is at a stage where training applies;
 * each says whether they have already completed a session or are lined up for
 * another, so the officer can see who actually needs this one.
 */
function EnrolDialog({ open, onOpenChange, session, onEnrolled }) {
  const toast = useToast()
  const [search, setSearch] = React.useState('')
  const [awaitingOnly, setAwaitingOnly] = React.useState(true)
  const [selected, setSelected] = React.useState(() => new Set())
  const [submitting, setSubmitting] = React.useState(false)
  const debounced = useDebounced(search)

  const { data, loading, error, refetch } = useApi(
    '/trainings/candidates',
    { search: debounced || undefined, training_id: session.id, awaiting_only: awaitingOnly ? 1 : undefined },
    { enabled: open }
  )

  React.useEffect(() => {
    if (open) setSelected(new Set())
  }, [open])

  function toggle(applicantId) {
    setSelected((current) => {
      const next = new Set(current)
      next.has(applicantId) ? next.delete(applicantId) : next.add(applicantId)
      return next
    })
  }

  async function submit() {
    setSubmitting(true)
    try {
      const response = await post(`/trainings/${session.id}/enrollments`, { applicant_ids: [...selected] })
      const skipped = response.data?.skipped ?? []
      const reasons = skipped.map((s) => `${s.full_name}: ${s.reason}`).join(' ')
      if (!response.data?.enrolled_count) {
        toast.warning(response.message, reasons)
      } else {
        toast.success(response.message, reasons || undefined)
      }
      onOpenChange(false)
      onEnrolled()
    } catch (err) {
      toast.error('Nobody was enrolled', err.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Enrol applicants</DialogTitle>
          <DialogDescription>
            Enrolling schedules each applicant for {session.training_title}. Completion is recorded separately, after the session.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-wrap items-center gap-3">
          <div className="relative min-w-[220px] flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search by name or code"
              className="pl-9"
              aria-label="Search applicants"
            />
          </div>
          <label className="flex items-center gap-2 text-sm text-muted-foreground">
            <input type="checkbox" checked={awaitingOnly} onChange={(e) => setAwaitingOnly(e.target.checked)} />
            Only those still waiting for training
          </label>
        </div>

        <div className="max-h-[50vh] space-y-2 overflow-y-auto">
          {loading ? (
            <LoadingState label="Finding applicants…" />
          ) : error ? (
            <ErrorState message={error.message} onRetry={refetch} />
          ) : !data?.length ? (
            <EmptyState
              icon={Users}
              title="Nobody to enrol"
              description="Applicants appear here once they are ready for deployment."
            />
          ) : (
            data.map((candidate) => {
              const already = candidate.in_this_session
              return (
                <label
                  key={candidate.id}
                  className={cn(
                    'flex cursor-pointer items-start gap-3 rounded-row bg-inset p-3',
                    already && 'cursor-not-allowed opacity-60'
                  )}
                >
                  <input
                    type="checkbox"
                    className="mt-1"
                    disabled={already}
                    checked={selected.has(candidate.id)}
                    onChange={() => toggle(candidate.id)}
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm font-medium">{candidate.full_name}</span>
                    <span className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                      <span className="font-mono">{candidate.applicant_code}</span>
                      <StatusBadge status={candidate.current_status} />
                      {already && <Badge tone="info">Already on this roster</Badge>}
                      {candidate.completed_training && (
                        <Badge tone="success">Completed {candidate.completed_training.title}</Badge>
                      )}
                      {!already && candidate.scheduled_training && (
                        <Badge tone="warning">Scheduled for {candidate.scheduled_training.title}</Badge>
                      )}
                    </span>
                  </span>
                </label>
              )
            })
          )}
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={submit} disabled={submitting || selected.size === 0}>
            {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
            Enrol {selected.size > 0 ? selected.size : ''}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
