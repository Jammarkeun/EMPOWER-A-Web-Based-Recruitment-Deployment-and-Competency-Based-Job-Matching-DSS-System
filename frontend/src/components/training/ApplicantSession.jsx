import * as React from 'react'
import { CalendarCheck, Loader2 } from 'lucide-react'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { formatDate, formatDateTime, formatTimeRange } from '@/lib/utils'

/**
 * One training session as the applicant on it is shown it.
 *
 * Every value comes from the session record and the applicant's own roster
 * entry (PortalController -> TrainingProgressService); nothing is composed.
 * The time is the one HR set when scheduling; a session scheduled before
 * times were kept has none, and no time is shown rather than a guessed one.
 *
 * While the session is still ahead, the applicant can tell the office they
 * will attend. That is their word, not attendance - HR still marks who came.
 */

/** The Training step as a whole. */
export const TRAINING_TONES = {
  not_reached: 'muted',
  not_required: 'muted',
  pending: 'warning',
  scheduled: 'info',
  in_progress: 'info',
  completed: 'success',
  missed: 'destructive',
  not_recorded: 'muted',
}

const SESSION_TONES = {
  scheduled: 'info',
  in_progress: 'info',
  completed: 'success',
  missed: 'destructive',
  cancelled: 'muted',
}

/** Sessions the applicant still has to attend or finish. */
export const isOpenSession = (session) => ['scheduled', 'in_progress'].includes(session.state)

/** What the office has recorded for this applicant, in their terms. */
function recordNote(session) {
  switch (session.state) {
    case 'completed':
      return session.completed_on
        ? `CDE recorded your completion on ${formatDate(session.completed_on)}.`
        : 'CDE recorded your completion.'
    case 'missed':
      return 'You were marked absent from this session.'
    case 'cancelled':
      return 'This session was cancelled. It does not count towards your training.'
    case 'in_progress':
      return session.attendance_status === 'present'
        ? 'You attended. Your training is complete once CDE records your completion.'
        : 'Waiting for CDE to record your attendance and completion.'
    default:
      return null
  }
}

/**
 * @param onConfirmed called with the refreshed training block once the
 *                    office has been told, so the page can show it at once
 */
export default function ApplicantSession({ session, onConfirmed }) {
  const toast = useToast()
  const [confirming, setConfirming] = React.useState(false)

  const facts = [
    ['Date', session.date ? formatDate(session.date) : null],
    ['Time', session.start_time ? formatTimeRange(session.start_time, session.end_time) : null],
    ['Location', session.location],
    ['Trainer', session.trainer_name],
  ].filter(([, value]) => value)
  const note = recordNote(session)
  const confirmed = Boolean(session.confirmed_at) && session.state === 'scheduled'

  async function confirm() {
    setConfirming(true)
    try {
      const response = await post(`/portal/training/${session.enrollment_id}/confirm`)
      toast.success(response.message ?? 'The office has been told you will attend.')
      onConfirmed?.(response.data)
    } catch (error) {
      toast.error('Not sent', error.message)
    } finally {
      setConfirming(false)
    }
  }

  return (
    <div className="rounded-md border bg-card px-3 py-2.5">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-sm font-medium">{session.title}</p>
          {session.training_code && (
            <p className="font-mono text-xs text-muted-foreground">{session.training_code}</p>
          )}
        </div>
        <Badge tone={SESSION_TONES[session.state] ?? 'muted'}>{session.state_label}</Badge>
      </div>

      <dl className="mt-2.5 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        {facts.map(([label, value]) => (
          <div key={label} className="min-w-0">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{value}</dd>
          </div>
        ))}
      </dl>

      {note && <p className="mt-2 text-xs text-muted-foreground">{note}</p>}

      {confirmed && (
        <p className="mt-2.5 flex items-center gap-1.5 text-xs text-status-success-text">
          <CalendarCheck className="h-3.5 w-3.5 shrink-0" />
          You told the office you will attend ({formatDateTime(session.confirmed_at)}).
        </p>
      )}

      {session.can_confirm && (
        <div className="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5">
          <Button size="sm" onClick={confirm} disabled={confirming}>
            {confirming ? <Loader2 className="h-4 w-4 animate-spin" /> : <CalendarCheck className="h-4 w-4" />}
            I will attend
          </Button>
          <span className="text-xs text-muted-foreground">Lets CDE know to expect you.</span>
        </div>
      )}
    </div>
  )
}
