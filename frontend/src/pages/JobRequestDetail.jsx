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
} from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import { formatDate, humanise } from '@/lib/utils'
import { FillBar } from './JobRequests'
import CriteriaDialog from '@/components/dialogs/CriteriaDialog'
import DeployDialog from '@/components/dialogs/DeployDialog'

export default function JobRequestDetail() {
  const { id } = useParams()
  const { can } = useAuth()
  const toast = useToast()

  const request = useApi(`/job-requests/${id}`)
  const rankings = useApi(`/job-requests/${id}/rankings`)

  const [evaluating, setEvaluating] = React.useState(false)
  const [criteriaOpen, setCriteriaOpen] = React.useState(false)
  const [deployTarget, setDeployTarget] = React.useState(null)

  async function handleEvaluate() {
    setEvaluating(true)

    try {
      const response = await post(`/job-requests/${id}/evaluate`)
      toast.success(
        'Evaluation complete',
        `${response.data.qualified_candidates} of ${response.data.evaluated_candidates} candidates met every mandatory requirement.`
      )
      rankings.refetch()
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
            <div className="mt-2">
              <StatusBadge status={job.request_status} />
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
              <p className="text-sm font-medium">Competency criteria have not been set</p>
              <p className="text-sm text-muted-foreground">
                Set the criteria and their weights before running an evaluation, so the ranking
                reflects what this client actually needs.
              </p>
            </div>
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
          <DetailsPanel job={job} />
        </TabsContent>
      </Tabs>

      <CriteriaDialog
        open={criteriaOpen}
        onOpenChange={setCriteriaOpen}
        jobRequestId={id}
        onSaved={request.refetch}
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
function RankingsPanel({ rankings, canDeploy, canShortlist, jobRequestId, onDeploy, onChanged }) {
  const [expanded, setExpanded] = React.useState(null)
  const [shortlisting, setShortlisting] = React.useState(false)
  const [selected, setSelected] = React.useState([])
  const toast = useToast()

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
        <Card key={match.id} className={match.hard_filter_pass ? '' : 'opacity-75'}>
          <CardContent className="pt-4">
            <div className="flex flex-wrap items-start gap-3">
              {canShortlist && match.hard_filter_pass && (
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

              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold tabular-nums">
                {match.rank_order}
              </div>

              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <Link
                    to={`/applicants/${match.applicant_id}`}
                    className="font-medium text-primary hover:underline"
                  >
                    {match.applicant_name}
                  </Link>
                  <StatusBadge status={match.recommendation_level} />
                  {match.is_shortlisted && <Badge tone="info">Shortlisted</Badge>}
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
                <div className="text-right">
                  <p className="text-xl font-semibold tabular-nums">{match.percentage_score}%</p>
                  <p className="text-xs text-muted-foreground">
                    {match.raw_score} of {match.max_score}
                  </p>
                </div>

                {canDeploy && match.hard_filter_pass && (
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
                <span className="font-medium">{criterion.label}:</span>
                <span className="text-muted-foreground">{criterion.explanation}</span>
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

function DetailsPanel({ job }) {
  const rows = [
    ['Required education', job.required_education],
    ['Required experience', job.required_experience_months ? `${job.required_experience_months} months` : null],
    ['Required certifications', job.required_certifications],
    ['Gender preference', job.gender_preference ? humanise(job.gender_preference) : null],
    ['Age range', job.age_min && job.age_max ? `${job.age_min}–${job.age_max} years` : null],
    ['Minimum height', job.height_min_cm ? `${job.height_min_cm} cm` : null],
    ['Physical requirement', job.physical_requirement],
    ['Availability', job.availability_requirement],
    ['How it was received', job.request_source ? humanise(job.request_source) : null],
    ['Remarks', job.remarks],
  ].filter(([, value]) => value)

  return (
    <Card>
      <CardHeader>
        <CardTitle>Client requirements</CardTitle>
        <CardDescription>As stated by {job.company_name}.</CardDescription>
      </CardHeader>
      <CardContent>
        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
          {rows.map(([label, value]) => (
            <div key={label}>
              <dt className="text-xs text-muted-foreground">{label}</dt>
              <dd className="text-sm">{value}</dd>
            </div>
          ))}
        </dl>
      </CardContent>
    </Card>
  )
}
