import { Link } from 'react-router-dom'
import { GraduationCap } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { formatDate } from '@/lib/utils'

const ATTENDANCE = { present: 'Attended', absent: 'Absent', pending: 'Attendance not recorded' }

/**
 * The applicant's training as the endorsement gate sees it (audit R-06).
 *
 * "Requirement met" is the server's own check - a completion recorded in a
 * session that was not cancelled - so this card and the gate cannot disagree.
 * Each session says only what was recorded: being enrolled is not attending,
 * and attending is not completing.
 */
export default function TrainingSummary({ training, canManage }) {
  if (!training?.relevant) return null

  const { requirement_met: met, required_before_endorsement: required, sessions = [] } = training

  return (
    <Card className="mt-5">
      <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0">
        <div>
          <CardTitle className="flex items-center gap-2">
            <GraduationCap className="h-4 w-4" aria-hidden="true" />
            Training
          </CardTitle>
          <CardDescription>
            {required
              ? 'A recorded completion is needed before this applicant can be endorsed to a client.'
              : 'Training is not currently required before endorsement (Settings).'}
          </CardDescription>
        </div>
        {met ? (
          <Badge tone="success">Completion recorded</Badge>
        ) : (
          <Badge tone={required ? 'warning' : 'muted'}>No completed training on record</Badge>
        )}
      </CardHeader>
      <CardContent>
        {sessions.length === 0 ? (
          <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
            <p>Not enrolled in any training session.</p>
            {canManage && (
              <Button variant="outline" size="sm" asChild>
                <Link to="/trainings">Find a session</Link>
              </Button>
            )}
          </div>
        ) : (
          <ul className="divide-y">
            {sessions.map((session) => (
              <li key={session.training_id} className="flex flex-wrap items-start justify-between gap-2 py-2.5">
                <div className="min-w-0">
                  <Link to={`/trainings/${session.training_id}`} className="text-sm font-medium hover:text-brand">
                    {session.title}
                  </Link>
                  <p className="text-xs text-muted-foreground">
                    <span className="font-mono">{session.training_code}</span> · {formatDate(session.date)}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                  <StatusBadge status={session.session_status} />
                  <Badge tone={session.attendance_status === 'present' ? 'info' : 'muted'}>
                    {ATTENDANCE[session.attendance_status] ?? session.attendance_status}
                  </Badge>
                  {session.completion_status === 'completed' ? (
                    <Badge tone={session.counts_towards_requirement ? 'success' : 'muted'}>
                      {session.counts_towards_requirement ? 'Completed' : 'Completed — session cancelled, does not count'}
                    </Badge>
                  ) : (
                    <Badge tone="muted">Not completed</Badge>
                  )}
                </div>
                {session.completion_recorded_by && (
                  <p className="w-full text-[11px] text-muted-foreground">
                    Completion recorded by {session.completion_recorded_by}
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
