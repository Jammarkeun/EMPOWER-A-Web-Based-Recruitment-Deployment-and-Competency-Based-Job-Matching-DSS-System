import * as React from 'react'
import {
  CheckCircle2,
  Circle,
  Clock,
  AlertTriangle,
  Briefcase,
  MapPin,
  Loader2,
  PartyPopper,
  Phone,
  MessageCircle,
} from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
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

/**
 * Deployment, confirmed.
 *
 * The agency's process ends when the client's completed deployment details are
 * entered and the applicant becomes an employee. This is the moment someone has
 * been waiting weeks for, so it is the first thing on the page and it says the
 * plain thing — but it is still an agency record rather than a celebration, so
 * the details sit right beneath it: where to report, to which department, under
 * whom, from when, and the employee number they will be asked for.
 *
 * Every value is read from the deployment; nothing here is composed. A missing
 * supervisor shows as absent rather than as a guess.
 */
function DeployedCard({ name, deployment }) {
  const details = [
    ['Company', deployment.company],
    ['Department', deployment.department],
    ['Position', deployment.position],
    ['Supervisor', deployment.supervisor],
    ['Start date', deployment.deployment_date ? formatDate(deployment.deployment_date) : null],
    ['Employee number', deployment.employee_number],
    ['Biometric number', deployment.biometric_number],
  ].filter(([, value]) => value)

  return (
    <Card className="border-success/50 bg-success/[0.06]">
      <CardHeader className="pb-3">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-success/15">
            <PartyPopper className="h-5 w-5 text-success" aria-hidden="true" />
          </span>
          <div className="min-w-0">
            <CardTitle className="text-base">Congratulations, {name}!</CardTitle>
            <CardDescription>
              You have been deployed. Your placement details are below — bring your employee number
              on your first day.
            </CardDescription>
          </div>
        </div>
      </CardHeader>

      <CardContent>
        <dl className="grid gap-3 sm:grid-cols-2">
          {details.map(([label, value]) => (
            <div key={label}>
              <dt className="text-xs text-muted-foreground">{label}</dt>
              <dd className="text-sm font-medium">{value}</dd>
            </div>
          ))}
        </dl>
      </CardContent>
    </Card>
  )
}

/**
 * The offer of work, and the applicant's answer to it.
 *
 * Deliberately a decision rather than an acknowledgement: both answers are
 * offered with equal weight, because the agency's process treats declining as a
 * normal outcome rather than a failure. Someone who has now seen the site, the
 * shift, and the journey is entitled to say no, and it is far better for
 * everyone that they say it here than by not turning up.
 *
 * Answering changes no status. The client's decision and the agency's are
 * separate acts and both still have to happen; this only records what the
 * applicant said, so that it stops living in whoever took the phone call.
 */
function PlacementDecisionCard({ position, company, onAnswered }) {
  const toast = useToast()
  const [choice, setChoice] = React.useState(null)
  const [note, setNote] = React.useState('')
  const [busy, setBusy] = React.useState(false)

  async function answer(response) {
    if (busy) return

    setBusy(true)
    setChoice(response)

    try {
      const result = await post('/portal/placement-response', {
        response,
        note: note.trim() || null,
      })

      toast.success(
        response === 'accepted' ? 'Thank you' : 'Thank you for telling us',
        result.message
      )
      onAnswered(result.data)
    } catch (err) {
      toast.error('Could not send your answer', err.message)
      setChoice(null)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card className="border-primary/40 bg-primary/[0.04]">
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <Briefcase className="h-4 w-4 text-primary" />
          Do you still want this placement?
        </CardTitle>
        <CardDescription>
          {position
            ? `A client company is considering you for ${position}${company ? ` at ${company}` : ''}.`
            : 'A client company is considering you for a placement.'}{' '}
          Before we go further, please tell us whether you still want the job.
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-3">
        <label className="block text-sm" htmlFor="placement_note">
          <span className="text-muted-foreground">
            Anything you would like us to know (optional)
          </span>
          <textarea
            id="placement_note"
            rows={2}
            value={note}
            disabled={busy}
            onChange={(event) => setNote(event.target.value)}
            maxLength={255}
            className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-sm disabled:opacity-50"
            placeholder="For example, the shift or the travel."
          />
        </label>

        <div className="flex flex-col gap-2 sm:flex-row">
          <Button className="sm:flex-1" onClick={() => answer('accepted')} disabled={busy}>
            {busy && choice === 'accepted' && <Loader2 className="h-4 w-4 animate-spin" />}
            {busy && choice === 'accepted' ? 'Sending…' : 'Yes, I want this job'}
          </Button>
          <Button
            variant="outline"
            className="sm:flex-1"
            onClick={() => answer('declined')}
            disabled={busy}
          >
            {busy && choice === 'declined' && <Loader2 className="h-4 w-4 animate-spin" />}
            {busy && choice === 'declined' ? 'Sending…' : 'No, not this one'}
          </Button>
        </div>

        <p className="text-xs text-muted-foreground">
          Saying no does not remove you from our records. We will keep looking for work that suits
          you better.
        </p>
      </CardContent>
    </Card>
  )
}

export default function PortalOverview() {
  const { data, loading, error, refetch, setData } = useApi('/portal')

  if (loading) return <LoadingState label="Loading your application…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const { person, application, documents, employment, timeline } = data

  return (
    <div className="space-y-4">
      {/* The greeting names the applicant from their own record, and the avatar
          beside it is the same identity the sidebar and header show. */}
      <div className="flex items-center gap-3">
        <span
          className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground"
          aria-hidden="true"
        >
          {person.initials}
        </span>
        <div className="min-w-0">
          <h1 className="truncate text-xl font-semibold tracking-tight">
            Hello, {person.first_name || person.full_name.split(' ')[0]}
          </h1>
          <p className="text-sm text-muted-foreground">
            Reference number <span className="font-mono">{person.applicant_code}</span>
            {application.position ? ` · Applying for ${application.position}` : ''}
          </p>
        </div>
      </div>

      {/*
        The end of the process, and the only point at which it is true.
        Rendered from the deployment record itself, so it survives a refresh, a
        new session, and a different device.
      */}
      {data.deployment && (
        <DeployedCard name={person.first_name || person.full_name.split(' ')[0]} deployment={data.deployment} />
      )}

      {/*
        The one decision in this process that belongs to the applicant. The
        agency's own account of how it works is explicit that after training the
        candidate decides whether they still want the job — and until now there
        was nowhere for them to say so, and no record of it when they did.
      */}
      {application.placement_decision_due && (
        <PlacementDecisionCard
          position={application.position}
          company={application.position_company}
          onAnswered={(answer) =>
            setData((current) => ({
              ...current,
              application: {
                ...current.application,
                placement_decision_due: false,
                placement_response: answer.placement_response,
                placement_responded_at: answer.placement_responded_at,
              },
            }))
          }
        />
      )}

      {application.placement_response && (
        <Card className="border-dashed">
          <CardContent className="flex items-start gap-2.5 pt-5">
            {application.placement_response === 'accepted' ? (
              <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-success" />
            ) : (
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
            )}
            <p className="text-sm text-muted-foreground">
              {application.placement_response === 'accepted'
                ? 'You told us you want to go ahead with this placement. Our staff will be in touch with the details.'
                : 'You told us you did not want this placement. Our staff will contact you about other work.'}
            </p>
          </CardContent>
        </Card>
      )}

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

      {application.office && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <MessageCircle className="h-4 w-4 text-primary" />
              Need help?
            </CardTitle>
            <CardDescription>Contact the CDE office about your application.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p className="font-medium">{application.office.name}</p>
            {application.office.address && <p className="text-muted-foreground">{application.office.address}</p>}
            {application.office.contact && (
              <a
                href={`tel:${application.office.contact}`}
                className="inline-flex items-center gap-2 text-primary hover:underline"
              >
                <Phone className="h-3.5 w-3.5" />
                {application.office.contact}
              </a>
            )}
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
