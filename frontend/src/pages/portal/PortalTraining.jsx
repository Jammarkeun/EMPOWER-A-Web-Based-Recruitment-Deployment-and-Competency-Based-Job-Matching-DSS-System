import { GraduationCap } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import ApplicantSession, { TRAINING_TONES, isOpenSession } from '@/components/training/ApplicantSession'

/**
 * The applicant's own training: where the step stands and every session they
 * are on.
 *
 * Read-only. Attendance and completion are recorded by HR on the staff
 * Training screen, and only a recorded completion finishes the step - so this
 * page reports the record rather than offering anything to tick. Opening it
 * marks no notification read; the bell is the only place that does that.
 */
export default function PortalTraining() {
  const { data, loading, error, refetch } = useApi('/portal/training')

  if (loading) return <LoadingState label="Loading your training…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  // The next session first: it is the one to turn up for.
  const open = data.sessions.filter(isOpenSession).sort((a, b) => (a.date ?? '').localeCompare(b.date ?? ''))
  const past = data.sessions.filter((session) => !isOpenSession(session))

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-[26px] font-normal leading-tight tracking-[-0.01em] sm:text-[32px]">My training</h1>
        <p className="text-sm text-muted-foreground">Your training sessions with CDE Manpower Services.</p>
      </div>

      <Card className="border-primary/30 bg-primary/[0.04]">
        <CardContent className="pt-5">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Training status</p>
            <Badge tone={TRAINING_TONES[data.status] ?? 'muted'}>{data.label}</Badge>
          </div>
          <p className="mt-1.5 text-sm text-muted-foreground">{data.explanation}</p>
        </CardContent>
      </Card>

      {open.length > 0 && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">{open.length === 1 ? 'Your session' : 'Your sessions'}</CardTitle>
            <CardDescription>Please attend as scheduled.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-2.5">
            {open.map((session) => (
              <ApplicantSession key={session.enrollment_id} session={session} />
            ))}
            {/* Sessions are recorded by date; a start time is not stored, so
                none is shown. Saying where to ask beats leaving it unsaid. */}
            <p className="text-xs text-muted-foreground">
              Please contact the office if you have not been told what time to arrive.
            </p>
          </CardContent>
        </Card>
      )}

      {past.length > 0 && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Training history</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2.5">
            {past.map((session) => (
              <ApplicantSession key={session.enrollment_id} session={session} />
            ))}
          </CardContent>
        </Card>
      )}

      {data.sessions.length === 0 && (
        <EmptyState
          icon={GraduationCap}
          title="No training sessions yet"
          description="When CDE Manpower Services enrolls you in a training session, its date and location will appear here."
        />
      )}
    </div>
  )
}
