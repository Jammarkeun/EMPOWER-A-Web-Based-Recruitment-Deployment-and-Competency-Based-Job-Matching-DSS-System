import * as React from 'react'
import { CheckCircle2, Circle, Clock, AlertTriangle, Briefcase, MapPin } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { LoadingState, ErrorState } from '@/components/ui/states'
import { cn, formatDate, formatDateTime } from '@/lib/utils'

const STAGES = [
  'Application received',
  'Screening',
  'Documents',
  'Ready for placement',
  'Client evaluation',
  'Deployed',
]

/**
 * The one screen a fresh online registrant sees.
 *
 * Written as an instruction rather than a status. The person has an account and
 * a reference number but no application yet, and the only thing that changes
 * that is walking into the office — so the address, the checklist, and the
 * reference number are the whole card, in that order.
 */
function VisitOfficeCard({ office, bringNow, neededLater, code }) {
  return (
    <Card className="border-warning/50 bg-warning/[0.06]">
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <MapPin className="h-4 w-4 text-warning" />
          Visit our office to complete your application
        </CardTitle>
        <CardDescription>
          Your account is ready, but your application does not start until our staff have seen you
          in person and checked your documents.
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        {office && (
          <div className="rounded-md border bg-card px-3 py-2.5">
            <p className="text-sm font-medium">{office.name}</p>
            {office.address && <p className="text-sm text-muted-foreground">{office.address}</p>}
            {office.contact && (
              <p className="mt-0.5 text-sm text-muted-foreground">{office.contact}</p>
            )}
          </div>
        )}

        <div>
          <p className="text-sm font-medium">Bring these with you</p>
          {bringNow?.length > 0 ? (
            <ul className="mt-1.5 space-y-1">
              {bringNow.map((item) => (
                <li key={item} className="text-sm text-muted-foreground">
                  · {item}
                </li>
              ))}
            </ul>
          ) : (
            <p className="mt-1 text-sm text-muted-foreground">
              Your resume. Our staff will tell you if anything else is needed.
            </p>
          )}
          <p className="mt-2 text-sm text-muted-foreground">
            Bring a valid ID as well — our staff need it to confirm your identity.
          </p>
        </div>

        {/* Named but set aside. Knowing these exist prevents a surprise later,
            while making clear they are not part of this visit. */}
        {neededLater?.length > 0 && (
          <p className="text-xs text-muted-foreground">
            You do not need your medical requirements yet ({neededLater.join(', ')}). Those come
            later, only once a client company accepts your application.
          </p>
        )}

        <div className="rounded-md border border-dashed px-3 py-2">
          <p className="text-xs text-muted-foreground">Quote this reference number</p>
          <p className="font-mono text-base font-semibold">{code}</p>
        </div>
      </CardContent>
    </Card>
  )
}

export default function PortalOverview() {
  const { data, loading, error, refetch } = useApi('/portal')

  if (loading) return <LoadingState label="Loading your application…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const { person, application, documents, employment, timeline } = data

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Hello, {person.full_name.split(' ')[0]}</h1>
        <p className="text-sm text-muted-foreground">
          Reference number <span className="font-mono">{person.applicant_code}</span>
        </p>
      </div>

      {/*
        Someone who registered online is waiting on exactly one thing, and it is
        not us. Saying so first and unmistakably is what prevents them sitting at
        home for a month expecting a call, which is the worst outcome this page
        can produce.
      */}
      {application.awaiting_identity_check ? (
        <VisitOfficeCard
          office={application.office}
          bringNow={documents.bring_now}
          neededLater={documents.needed_later}
          code={person.applicant_code}
        />
      ) : (
        /* The status explanation is the single most important thing on this
           page: it is the answer to the question that otherwise becomes a phone
           call to the office. */
        <Card className="border-primary/30 bg-primary/[0.04]">
          <CardContent className="pt-5">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              Current status
            </p>
            <p className="mt-1 text-lg font-semibold">{application.status_label}</p>
            <p className="mt-1.5 text-sm text-muted-foreground">{application.explanation}</p>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Your progress</CardTitle>
          <CardDescription>
            Stage {application.stage_number} of {application.total_stages}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <ol className="space-y-2.5">
            {STAGES.map((stage, index) => {
              const stageNumber = index + 1
              const done = stageNumber < application.stage_number
              const current = stageNumber === application.stage_number

              return (
                <li key={stage} className="flex items-center gap-3">
                  {done ? (
                    <CheckCircle2 className="h-5 w-5 shrink-0 text-success" />
                  ) : current ? (
                    <Clock className="h-5 w-5 shrink-0 text-primary" />
                  ) : (
                    <Circle className="h-5 w-5 shrink-0 text-muted-foreground/40" />
                  )}
                  <span
                    className={cn(
                      'text-sm',
                      current && 'font-medium',
                      !done && !current && 'text-muted-foreground'
                    )}
                  >
                    {stage}
                  </span>
                  {current && <Badge tone="info">You are here</Badge>}
                </li>
              )
            })}
          </ol>
        </CardContent>
      </Card>

      {/* The visit card above already lists what to bring, so repeating it here
          would only make the list look twice as long. */}
      <Card
        className={cn(
          documents.is_complete ? 'border-success/40' : 'border-warning/40',
          application.awaiting_identity_check && 'hidden'
        )}
      >
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Your documents</CardTitle>
          <CardDescription>
            {documents.verified_count} of {documents.total_count} verified
          </CardDescription>
        </CardHeader>
        <CardContent>
          {documents.is_complete ? (
            <div className="flex items-start gap-2.5">
              <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-success" />
              <p className="text-sm">
                All your requirements are complete. There is nothing further to submit.
              </p>
            </div>
          ) : (
            <div className="flex items-start gap-2.5">
              <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-warning" />
              <div className="min-w-0">
                <p className="text-sm">Please bring the following to the office:</p>
                <ul className="mt-1.5 space-y-1">
                  {documents.outstanding.map((item) => (
                    <li key={item} className="text-sm text-muted-foreground">
                      · {item}
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {employment && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <Briefcase className="h-4 w-4" />
              Your employment
            </CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="grid gap-3 sm:grid-cols-2">
              {[
                ['Employee number', employment.employee_number],
                ['Position', employment.position],
                ['Company', employment.company],
                ['Department', employment.department],
                ['Supervisor', employment.supervisor],
                ['Start date', formatDate(employment.hire_date)],
              ]
                .filter(([, value]) => value)
                .map(([label, value]) => (
                  <div key={label}>
                    <dt className="text-xs text-muted-foreground">{label}</dt>
                    <dd className="text-sm font-medium">{value}</dd>
                  </div>
                ))}
            </dl>
          </CardContent>
        </Card>
      )}

      {timeline?.length > 0 && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Recent activity</CardTitle>
          </CardHeader>
          <CardContent>
            <ol className="relative space-y-3 border-l pl-5">
              {timeline.map((event, index) => (
                <li key={index} className="relative">
                  <span className="absolute -left-[27px] mt-1 h-2.5 w-2.5 rounded-full border-2 border-card bg-primary" />
                  <p className="text-sm font-medium">{event.status}</p>
                  {event.reason && <p className="text-xs text-muted-foreground">{event.reason}</p>}
                  <p className="text-xs text-muted-foreground">{formatDateTime(event.occurred_at)}</p>
                </li>
              ))}
            </ol>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
