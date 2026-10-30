import { Badge } from '@/components/ui/badge'
import { formatDate } from '@/lib/utils'

/**
 * One training session as the applicant on it is shown it.
 *
 * Every value comes from the session record and the applicant's own roster
 * entry (PortalController -> TrainingProgressService); nothing is composed. A
 * session has a date, a place and a trainer but no start time, so no time is
 * shown - a guessed one would send somebody to the wrong door at the wrong hour.
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

export default function ApplicantSession({ session }) {
  const facts = [
    ['Date', session.date ? formatDate(session.date) : null],
    ['Location', session.location],
    ['Trainer', session.trainer_name],
  ].filter(([, value]) => value)
  const note = recordNote(session)

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

      <dl className="mt-2.5 grid gap-2.5 sm:grid-cols-3">
        {facts.map(([label, value]) => (
          <div key={label} className="min-w-0">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{value}</dd>
          </div>
        ))}
      </dl>

      {note && <p className="mt-2 text-xs text-muted-foreground">{note}</p>}
    </div>
  )
}
