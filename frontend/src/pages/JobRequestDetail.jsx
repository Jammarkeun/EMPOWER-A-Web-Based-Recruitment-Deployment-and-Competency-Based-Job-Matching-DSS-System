import * as React from 'react'
import { useParams, Link } from 'react-router-dom'
import {
  Sparkles,
  SlidersHorizontal,
  Loader2,
  ChevronDown,
  ChevronRight,
  CheckCircle2,
  XCircle,
  Info,
  UserPlus,
  AlertTriangle,
  Target,
  History,
} from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useApplicantStatuses } from '@/hooks/useApplicantStatuses'
import { useAuth } from '@/contexts/AuthContext'
import { patch, post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import PersonAvatar from '@/components/PersonAvatar'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import { Field } from '@/components/ui/form'
import { Textarea } from '@/components/ui/textarea'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { formatDate, formatDateTime, humanise } from '@/lib/utils'
import CriterionDetails from '@/components/CriterionDetails'
import { FillBar } from './JobRequests'
import CriteriaDialog from '@/components/dialogs/CriteriaDialog'
import DeployDialog from '@/components/dialogs/DeployDialog'
import MatchBreakdownDialog from '@/components/dialogs/MatchBreakdownDialog'

export default function JobRequestDetail() {
  const { id } = useParams()
  const { can } = useAuth()
  const toast = useToast()

  const request = useApi(`/job-requests/${id}`)
  const rankings = useApi(`/job-requests/${id}/rankings`)

  const [evaluating, setEvaluating] = React.useState(false)
  const [criteriaOpen, setCriteriaOpen] = React.useState(false)
  const [deployTarget, setDeployTarget] = React.useState(null)
  const [breakdownTarget, setBreakdownTarget] = React.useState(null)
  const [statusOpen, setStatusOpen] = React.useState(false)

  async function handleEvaluate() {
    setEvaluating(true)

    try {
      const response = await post(`/job-requests/${id}/evaluate`)
      toast.success(
        'Evaluation complete',
        `${response.data.qualified_candidates} of ${response.data.evaluated_candidates} candidates met every mandatory requirement.`
      )
      rankings.refetch()
      request.refetch()
    } catch (error) {
      toast.error('Could not run evaluation', error.message)
    } finally {
      setEvaluating(false)
    }
  }

  if (request.loading) return <LoadingState label="Loading request…" />
  if (request.error) return <ErrorState message={request.error.message} onRetry={request.refetch} />
  if (!request.data) return null

  const job = request.data
  const hasCriteria = job.criteria?.length > 0

  return (
    <>
      <PageHeader
        breadcrumbs={[{ label: 'Manpower requests', to: '/job-requests' }, { label: job.request_code }]}
        title={job.position_title}
        description={
          <>
            {job.company_name} · {job.department_name} · requested {formatDate(job.date_requested)}
          </>
        }
        actions={
          <>
            {can('job_requests.configure_criteria') && (
              <Button variant="outline" onClick={() => setCriteriaOpen(true)}>
                <SlidersHorizontal className="h-4 w-4" />
                {hasCriteria ? 'Adjust criteria' : 'Set criteria'}
              </Button>
            )}
            {can('matching.evaluate') && (
              <Button onClick={handleEvaluate} disabled={evaluating || !hasCriteria}>
                {evaluating ? <Loader2 className="h-4 w-4 animate-spin" /> : <Sparkles className="h-4 w-4" />}
                {evaluating ? 'Evaluating…' : 'Run evaluation'}
              </Button>
            )}
          </>
        }
      />

      <div className="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardContent className="pt-5">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Positions filled</p>
            <div className="mt-2">
              <FillBar filled={job.workers_fulfilled} needed={job.workers_needed} />
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-5">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Status</p>
            <div className="mt-2 flex flex-wrap items-center gap-2">
              <StatusBadge status={job.request_status} />
              {/* Only the changes the server would accept from here (R-16). */}
              {can('job_requests.close') && job.status_changes?.length > 0 && (
                <Button size="sm" variant="link" className="h-auto px-0" onClick={() => setStatusOpen(true)}>
                  Change
                </Button>
              )}
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-5">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Deadline</p>
            <p className={`mt-2 text-sm font-medium ${job.is_overdue ? 'text-destructive' : ''}`}>
              {job.deployment_deadline ? formatDate(job.deployment_deadline) : 'None set'}
              {job.is_overdue && ' · overdue'}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-5">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Still needed</p>
            <p className="mt-2 text-2xl font-semibold tabular-nums">{job.remaining_headcount}</p>
          </CardContent>
        </Card>
      </div>

      {!hasCriteria && (
        <Card className="mb-5 border-warning/40 bg-warning/5">
          <CardContent className="flex items-start gap-3 py-4">
            <Info className="mt-0.5 h-5 w-5 shrink-0 text-warning" />
            <div>
              <p className="text-sm font-medium">No criteria set for this position yet</p>
              <p className="text-sm text-muted-foreground">
                Set the criteria and their weights before running an evaluation, so the ranking
                reflects what this client actually needs.
              </p>
            </div>
          </CardContent>
        </Card>
      )}

      {/*
        Out of date: something a result was scored against has changed since.
        Provisional: the ranking does not cover the pool as it stands - some
        applicants not yet scored, results carried over from a narrower run,
        or people who have left the pool (S-01, S-02). Ranks are consistent
        either way; a run over the whole pool settles both.
      */}
      {(job.evaluation?.is_stale || job.evaluation?.is_provisional) && (
        <Card className="mb-5 border-warning/40 bg-warning/5">
          <CardContent className="flex flex-wrap items-start gap-3 py-4">
            <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-warning" />
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium">
                {job.evaluation.is_stale ? 'Part of this ranking is out of date' : 'This ranking is provisional'}
              </p>
              <ul className="mt-1 list-disc space-y-0.5 pl-5 text-sm text-muted-foreground">
                {(job.evaluation.provisional_reasons ?? []).map((reason) => (
                  <li key={reason}>{reason}</li>
                ))}
              </ul>
              <p className="mt-1 text-xs text-muted-foreground">
                Last evaluated {formatDateTime(job.evaluation.last_evaluated_at)}. Run the evaluation over the whole pool
                before relying on the order.
              </p>
            </div>
            {can('matching.evaluate') && (
              <Button size="sm" onClick={handleEvaluate} disabled={evaluating}>
                {evaluating ? <Loader2 className="h-4 w-4 animate-spin" /> : <Sparkles className="h-4 w-4" />}
                Re-run evaluation
              </Button>
            )}
          </CardContent>
        </Card>
      )}

      <Tabs defaultValue="rankings">
        <TabsList>
          <TabsTrigger value="rankings">Candidate rankings</TabsTrigger>
          <TabsTrigger value="criteria">Criteria &amp; weights</TabsTrigger>
          <TabsTrigger value="details">Request details</TabsTrigger>
        </TabsList>

        <TabsContent value="rankings">
          <RankingsPanel
            rankings={rankings}
            canDeploy={can('deployment.create') && job.can_accept_deployment}
            canShortlist={can('matching.shortlist')}
            jobRequestId={id}
            onDeploy={setDeployTarget}
            onBreakdown={setBreakdownTarget}
            onChanged={() => {
              rankings.refetch()
              request.refetch()
            }}
          />
        </TabsContent>

        <TabsContent value="criteria">
          <CriteriaPanel criteria={job.criteria} onEdit={() => setCriteriaOpen(true)} canEdit={can('job_requests.configure_criteria')} />
        </TabsContent>

        <TabsContent value="details">
          <DetailsPanel job={job} canEdit={can('job_requests.configure_criteria')} onEdit={() => setCriteriaOpen(true)} />
          <StatusHistory history={job.status_history} />
        </TabsContent>
      </Tabs>

      <CriteriaDialog
        open={criteriaOpen}
        onOpenChange={setCriteriaOpen}
        jobRequestId={id}
        onSaved={() => {
          request.refetch()
          rankings.refetch()
        }}
      />

      <DeployDialog
        applicant={deployTarget}
        jobRequest={job}
        onOpenChange={(open) => !open && setDeployTarget(null)}
        onDeployed={() => {
          setDeployTarget(null)
          rankings.refetch()
          request.refetch()
        }}
      />

      <MatchBreakdownDialog
        open={Boolean(breakdownTarget)}
        onOpenChange={(open) => !open && setBreakdownTarget(null)}
        candidate={breakdownTarget}
        jobPosition={job.position_title}
      />

      <RequestStatusDialog job={job} open={statusOpen} onOpenChange={setStatusOpen} onChanged={request.refetch} />
    </>
  )
}

/**
 * The ranked candidate list.
 *
 * Every row can be expanded into the full per-criterion trace. That is the point
 * of the whole feature: a score HR cannot interrogate is not decision support,
 * and a ranking they cannot explain is not defensible to the client or to the
 * applicant who was passed over.
 */
function RankingsPanel({ rankings, canDeploy, canShortlist, jobRequestId, onDeploy, onBreakdown, onChanged }) {
  const [expanded, setExpanded] = React.useState(null)
  const [shortlisting, setShortlisting] = React.useState(false)
  const [selected, setSelected] = React.useState([])
  const toast = useToast()
  // The statuses the server will deploy from today - narrower when Settings
  // require the client's approval first - rather than a list kept here.
  const { deployable } = useApplicantStatuses({ enabled: canDeploy })

  if (rankings.loading) return <LoadingState label="Loading rankings…" />
  if (rankings.error) return <ErrorState message={rankings.error.message} onRetry={rankings.refetch} />

  if (!rankings.data?.length) {
    return (
      <Card>
        <EmptyState
          icon={Sparkles}
          title="No evaluation has been run yet"
          description="Set the competency criteria, then run an evaluation to rank the applicant pool against this request."
        />
      </Card>
    )
  }

  async function handleShortlist() {
    setShortlisting(true)
    try {
      await post(`/job-requests/${jobRequestId}/shortlist`, { applicant_ids: selected })
      toast.success('Shortlist updated', 'Deployment still requires a separate, explicit action.')
      setSelected([])
      onChanged()
    } catch (error) {
      toast.error('Could not update shortlist', error.message)
    } finally {
      setShortlisting(false)
    }
  }

  return (
    <div className="space-y-3">
      <Card className="border-primary/30 bg-primary/5">
        <CardContent className="flex items-start gap-3 py-3.5">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
          <p className="text-sm text-muted-foreground">
            These are <span className="font-medium text-foreground">recommendations</span>. The system
            ranks and explains; the hiring decision stays with HR, and nobody is deployed until you
            record it explicitly.
          </p>
        </CardContent>
      </Card>

      {canShortlist && selected.length > 0 && (
        <Card className="no-print">
          <CardContent className="flex items-center justify-between gap-3 py-3">
            <p className="text-sm">{selected.length} selected for client endorsement</p>
            <div className="flex gap-2">
              <Button variant="ghost" size="sm" onClick={() => setSelected([])}>
                Clear
              </Button>
              <Button size="sm" onClick={handleShortlist} disabled={shortlisting}>
                {shortlisting && <Loader2 className="h-4 w-4 animate-spin" />}
                Shortlist
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      {rankings.data.map((match) => (
        <Card
          key={match.id}
          className={match.hard_filter_pass && match.in_current_pool !== false ? '' : 'opacity-75'}
        >
          <CardContent className="pt-4">
            <div className="flex flex-wrap items-start gap-3">
              {canShortlist && match.hard_filter_pass && match.in_current_pool !== false && (
                <input
                  type="checkbox"
                  className="mt-1.5 h-4 w-4 rounded border-input"
                  checked={selected.includes(match.applicant_id)}
                  onChange={(e) =>
                    setSelected((current) =>
                      e.target.checked
                        ? [...current, match.applicant_id]
                        : current.filter((v) => v !== match.applicant_id)
                    )
                  }
                  aria-label={`Select ${match.applicant_name}`}
                />
              )}

              <div
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold tabular-nums"
                title={match.rank_order ? `Rank ${match.rank_order}` : 'No longer ranked'}
              >
                {match.rank_order ?? '—'}
              </div>

              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <PersonAvatar src={match.photo_url} name={match.applicant_name} size="xs" />
                  <Link
                    to={`/applicants/${match.applicant_id}`}
                    className="font-medium text-primary hover:underline"
                  >
                    {match.applicant_name}
                  </Link>
                  <StatusBadge status={match.recommendation_level} />
                  {match.is_shortlisted && <Badge tone="info">Shortlisted</Badge>}
                  {/* Where the person stands now, which may have moved on since
                      the ranking was produced - deployed here, placed elsewhere,
                      or closed. */}
                  {['deployed', 'active', 'resigned', 'terminated', 'archived'].includes(match.current_status) && (
                    <StatusBadge status={match.current_status} label={`Now ${humanise(match.current_status).toLowerCase()}`} />
                  )}
                  {match.in_current_pool === false && (
                    <Badge tone="muted" title="Kept because they were shortlisted; they have since left the candidate pool.">
                      No longer in the pool
                    </Badge>
                  )}
                  {match.is_stale && (
                    <Badge tone="warning" title={(match.stale_reasons ?? []).map(staleReasonLabel).join('; ')}>
                      Out of date
                    </Badge>
                  )}
                  {match.from_earlier_run && (
                    <Badge tone="muted" title="Carried over from an earlier, narrower evaluation rather than rescored by the latest one.">
                      From an earlier run
                    </Badge>
                  )}
                  {match.applied_for_this_position ? (
                    <Badge tone="success">
                      <Target className="mr-1 h-3 w-3" />
                      Applied for this position
                    </Badge>
                  ) : (
                    match.preferred_position && (
                      <span className="text-xs text-muted-foreground">Applied for: {match.preferred_position}</span>
                    )
                  )}
                  {!match.hard_filter_pass && (
                    <Badge tone="destructive">
                      <XCircle className="mr-1 h-3 w-3" />
                      Did not meet a mandatory requirement
                    </Badge>
                  )}
                </div>
                <p className="mt-0.5 text-xs text-muted-foreground">{match.breakdown?.summary}</p>
              </div>

              <div className="flex shrink-0 items-center gap-3">
                {onBreakdown ? (
                  <button
                    type="button"
                    onClick={() => onBreakdown(match)}
                    className="group text-right transition-transform hover:scale-105"
                    title="Click to view full competency match breakdown"
                  >
                    <p className="text-xl font-semibold tabular-nums text-primary group-hover:underline">
                      {match.percentage_score}%
                    </p>
                    <p className="text-xs text-muted-foreground group-hover:text-primary">
                      View Trace Details ↗
                    </p>
                  </button>
                ) : (
                  <div className="text-right">
                    <p className="text-xl font-semibold tabular-nums">{match.percentage_score}%</p>
                    <p className="text-xs text-muted-foreground">
                      {match.raw_score} of {match.max_score}
                    </p>
                  </div>
                )}

                {canDeploy &&
                  match.hard_filter_pass &&
                  match.in_current_pool !== false &&
                  deployable.includes(match.current_status) && (
                  <Button size="sm" variant="outline" onClick={() => onDeploy(match)}>
                    <UserPlus className="h-3.5 w-3.5" />
                    Deploy
                  </Button>
                )}

                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setExpanded(expanded === match.id ? null : match.id)}
                  aria-expanded={expanded === match.id}
                >
                  {expanded === match.id ? (
                    <ChevronDown className="h-4 w-4" />
                  ) : (
                    <ChevronRight className="h-4 w-4" />
                  )}
                  Why?
                </Button>
              </div>
            </div>

            {expanded === match.id && <ScoreBreakdown breakdown={match.breakdown} />}
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

function ScoreBreakdown({ breakdown }) {
  if (!breakdown?.criteria) return null

  const gates = breakdown.criteria.filter((c) => c.is_gate)
  const scored = breakdown.criteria.filter((c) => !c.is_gate)

  return (
    <div className="mt-4 space-y-4 rounded-md border bg-muted/30 p-4">
      {gates.length > 0 && (
        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Mandatory requirements
          </p>
          <ul className="space-y-1.5">
            {gates.map((criterion) => (
              <li key={criterion.criteria} className="flex items-start gap-2 text-sm">
                {criterion.passed ? (
                  <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-success" />
                ) : (
                  <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-destructive" />
                )}
                <div className="min-w-0">
                  <span className="font-medium">{criterion.label}:</span>{' '}
                  <span className="text-muted-foreground">{criterion.explanation}</span>
                  <CriterionDetails details={criterion.details} />
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div>
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Scored criteria
        </p>
        <div className="space-y-2.5">
          {scored.map((criterion) => {
            const ratio = criterion.weight > 0 ? (criterion.score / criterion.weight) * 100 : 0
            return (
              <div key={criterion.criteria}>
                <div className="flex items-baseline justify-between gap-3 text-sm">
                  <span className="font-medium">{criterion.label}</span>
                  <span className="shrink-0 tabular-nums text-muted-foreground">
                    {criterion.score} / {criterion.weight}
                  </span>
                </div>
                <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                  <div
                    className="h-full rounded-full bg-primary"
                    style={{ width: `${Math.min(100, ratio)}%` }}
                  />
                </div>
                <p className="mt-1 text-xs text-muted-foreground">{criterion.explanation}</p>
                <CriterionDetails details={criterion.details} />
              </div>
            )
          })}
        </div>
      </div>

      <div className="flex items-baseline justify-between border-t pt-3 text-sm">
        <span className="font-medium">Total</span>
        <span className="tabular-nums">
          {breakdown.raw_score} of {breakdown.max_score} ({breakdown.percentage}%)
        </span>
      </div>
    </div>
  )
}

function CriteriaPanel({ criteria, onEdit, canEdit }) {
  if (!criteria?.length) {
    return (
      <Card>
        <EmptyState
          icon={SlidersHorizontal}
          title="No criteria configured"
          description="Set which factors matter for this position and how much each is worth."
          action={canEdit ? <Button size="sm" onClick={onEdit}>Set criteria</Button> : null}
        />
      </Card>
    )
  }

  const gates = criteria.filter((c) => c.mandatory_flag)
  const scored = criteria.filter((c) => !c.mandatory_flag)
  const totalWeight = scored.reduce((sum, c) => sum + Number(c.weight_score), 0)

  return (
    <div className="space-y-4">
      {gates.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Mandatory requirements</CardTitle>
            <CardDescription>
              Eligibility gates. Failing any one disqualifies the applicant regardless of their score
              elsewhere, so these carry no weight of their own.
            </CardDescription>
          </CardHeader>
          <CardContent className="divide-y">
            {gates.map((criterion) => (
              <div key={criterion.id} className="py-2.5">
                <p className="text-sm font-medium">{criterion.criteria_name}</p>
                <p className="text-xs text-muted-foreground">
                  {describeRule(criterion)} · {criterion.description}
                </p>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader className="flex-row items-start justify-between space-y-0">
          <div className="space-y-1">
            <CardTitle>Scored criteria</CardTitle>
            <CardDescription>Total weight: {totalWeight} points</CardDescription>
          </div>
          {canEdit && (
            <Button variant="outline" size="sm" onClick={onEdit}>
              Adjust
            </Button>
          )}
        </CardHeader>
        <CardContent className="space-y-3">
          {scored.map((criterion) => {
            const share = totalWeight > 0 ? (Number(criterion.weight_score) / totalWeight) * 100 : 0
            return (
              <div key={criterion.id}>
                <div className="flex items-baseline justify-between gap-3">
                  <p className="text-sm font-medium">{criterion.criteria_name}</p>
                  <p className="shrink-0 text-sm tabular-nums text-muted-foreground">
                    {criterion.weight_score} pts · {Math.round(share)}%
                  </p>
                </div>
                <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                  <div className="h-full rounded-full bg-primary" style={{ width: `${share}%` }} />
                </div>
                <p className="mt-1 text-xs text-muted-foreground">{describeRule(criterion)}</p>
              </div>
            )
          })}
        </CardContent>
      </Card>
    </div>
  )
}

/** Renders a criterion's configuration as a sentence HR can read back. */
function describeRule(criterion) {
  if (criterion.rubric_json) {
    const entries = Object.entries(criterion.rubric_json)
      .map(([key, value]) => `${humanise(key)} = ${Math.round(value * 100)}%`)
      .join(', ')
    return `Rated against: ${entries}`
  }

  if (criterion.min_value !== null && criterion.max_value !== null) {
    const direction = criterion.score_direction === 'lower_better' ? 'lower is better' : 'higher is better'
    return `Graded from ${criterion.min_value} to ${criterion.max_value} (${direction})`
  }

  if (criterion.min_value !== null) return `Minimum of ${criterion.min_value}`
  if (criterion.max_value !== null) return `Maximum of ${criterion.max_value}`
  if (criterion.expected_value) return `Expected: ${criterion.expected_value}`

  return 'Scored on whether it is recorded at all'
}

/**
 * The client's requirements as stated on the request, each marked with whether
 * the ranking actually uses it.
 *
 * Typing "18 to 35" on the request used to change nothing about who was
 * ranked, because only the configured criteria are scored. Saying so beside
 * each requirement is what stops a stated requirement from being silently
 * ignored.
 */
function DetailsPanel({ job, canEdit, onEdit }) {
  const scoredCodes = new Set((job.criteria ?? []).map((c) => c.criteria_code))

  const rows = [
    ['Required education', job.required_education, 'education'],
    ['Required experience', job.required_experience_months ? `${job.required_experience_months} months` : null, 'experience'],
    ['Required certifications', job.required_certifications, 'certifications'],
    [
      'Gender preference',
      job.gender_preference ? humanise(job.gender_preference) : null,
      job.gender_preference && job.gender_preference !== 'any' ? 'gender' : null,
    ],
    [
      'Age range',
      job.age_min || job.age_max ? `${job.age_min ?? '—'}–${job.age_max ?? '—'} years` : null,
      'age',
    ],
    ['Minimum height', job.height_min_cm ? `${job.height_min_cm} cm` : null, 'height'],
    ['Physical requirement', job.physical_requirement, 'free_text'],
    ['Availability', job.availability_requirement, 'free_text'],
    ['How it was received', job.request_source ? humanise(job.request_source) : null, null],
    ['Remarks', job.remarks, null],
  ].filter(([, value]) => value)

  const unscored = rows.filter(([, , code]) => code && code !== 'free_text' && !scoredCodes.has(code))

  return (
    <Card>
      <CardHeader>
        <CardTitle>Client requirements</CardTitle>
        <CardDescription>As stated by {job.company_name}, and whether the ranking uses each one.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {unscored.length > 0 && (
          <div className="flex flex-wrap items-start gap-3 rounded-md border border-warning/40 bg-warning/5 p-3">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
            <p className="min-w-0 flex-1 text-sm text-muted-foreground">
              {unscored.map(([label]) => label).join(', ')}{' '}
              {unscored.length === 1 ? 'is' : 'are'} stated on this request but not used in the ranking.
              Add {unscored.length === 1 ? 'it' : 'them'} as criteria so applicants are matched against{' '}
              {unscored.length === 1 ? 'it' : 'them'}.
            </p>
            {canEdit && (
              <Button size="sm" variant="outline" onClick={onEdit}>
                Adjust criteria
              </Button>
            )}
          </div>
        )}

        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
          {rows.map(([label, value, code]) => (
            <div key={label}>
              <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                {label}
                {code === 'free_text' && <Badge tone="muted">Not scored · free text</Badge>}
                {code && code !== 'free_text' && (
                  scoredCodes.has(code) ? (
                    <Badge tone="success">Used in ranking</Badge>
                  ) : (
                    <Badge tone="warning">Not in ranking</Badge>
                  )
                )}
              </dt>
              <dd className="text-sm">{value}</dd>
            </div>
          ))}
        </dl>
      </CardContent>
    </Card>
  )
}

function staleReasonLabel(reason) {
  return (
    {
      criteria_changed: 'The criteria changed after this result was scored',
      profile_changed: "The applicant's details changed after this result was scored",
      left_pool: 'The applicant is no longer in the eligible pool',
    }[reason] ?? humanise(reason)
  )
}

/**
 * Every status change on this request, whoever or whatever made it (R-16): an
 * officer, a deployment filling a position, or a new headcount.
 */
function StatusHistory({ history }) {
  if (!history?.length) return null

  const sources = { manual: 'Changed by hand', deployment: 'After a deployment', headcount: 'After a headcount change' }

  return (
    <Card className="mt-5">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <History className="h-4 w-4 text-muted-foreground" />
          Status history
        </CardTitle>
        <CardDescription>Newest first. Closing, cancelling and reopening always carry a reason.</CardDescription>
      </CardHeader>
      <CardContent>
        <ol className="space-y-3">
          {history.map((row) => (
            <li key={row.id} className="rounded-row bg-inset p-3">
              <div className="flex flex-wrap items-center gap-2 text-sm">
                <StatusBadge status={row.from_status} />
                <span className="text-muted-foreground">to</span>
                <StatusBadge status={row.to_status} />
                <span className="text-xs text-muted-foreground">
                  {sources[row.source] ?? humanise(row.source)}
                  {row.changed_by && ` · ${row.changed_by}`}
                  {row.changed_at && ` · ${formatDateTime(row.changed_at)}`}
                </span>
              </div>
              {row.reason && <p className="mt-1 text-xs text-muted-foreground">{row.reason}</p>}
            </li>
          ))}
        </ol>
      </CardContent>
    </Card>
  )
}

/**
 * Changes the request's status. Offers only what the server's transition map
 * allows from here, and asks for a reason where one is owed; the server checks
 * both again regardless.
 */
function RequestStatusDialog({ job, open, onOpenChange, onChanged }) {
  const toast = useToast()
  const options = job.status_changes ?? []
  const [status, setStatus] = React.useState('')
  const [reason, setReason] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)

  // Reset on each opening only; the options come with the request it opened on.
  React.useEffect(() => {
    if (open) {
      setStatus(options[0]?.status ?? '')
      setReason('')
      setErrors({})
    }
  }, [open])

  const chosen = options.find((option) => option.status === status)

  async function submit(event) {
    event.preventDefault()
    setSubmitting(true)
    setErrors({})

    try {
      const response = await patch(`/job-requests/${job.id}/status`, {
        request_status: status,
        ...(reason.trim() ? { reason: reason.trim() } : {}),
      })
      toast.success('Request status updated', `Now ${humanise(response.data?.request_status ?? status)}.`)
      onOpenChange(false)
      onChanged()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Status not changed', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Change request status</DialogTitle>
          <DialogDescription>
            Currently {humanise(job.request_status)}. Partially fulfilled and fulfilled follow the headcount and are set by
            the system as deployments are recorded.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-4" noValidate>
          <div role="radiogroup" aria-label="New status" className="flex flex-wrap gap-2">
            {options.map((option) => (
              <Button
                key={option.status}
                type="button"
                role="radio"
                aria-checked={status === option.status}
                variant={status === option.status ? 'default' : 'outline'}
                size="sm"
                onClick={() => setStatus(option.status)}
              >
                {option.reopens ? 'Reopen' : humanise(option.status)}
              </Button>
            ))}
          </div>
          {chosen?.reopens && (
            <p className="text-xs text-muted-foreground">
              A reopened request goes back to open, or to partially fulfilled if workers are already deployed against it.
            </p>
          )}
          {errors.request_status?.[0] && <p className="text-xs font-medium text-destructive">{errors.request_status[0]}</p>}

          <Field
            label="Reason"
            htmlFor="request_status_reason"
            required={Boolean(chosen?.requires_reason)}
            hint={chosen?.requires_reason ? undefined : 'Optional for this change.'}
            error={errors.reason?.[0]}
          >
            <Textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting || !status || (chosen?.requires_reason && !reason.trim())}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Change status
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
