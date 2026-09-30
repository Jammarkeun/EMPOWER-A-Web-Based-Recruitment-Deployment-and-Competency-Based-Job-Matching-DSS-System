import * as React from 'react'
import { Link } from 'react-router-dom'
import {
  Users,
  UserCheck,
  Building2,
  ClipboardList,
  FileWarning,
  CalendarClock,
  FileX2,
  AlertTriangle,
  ArrowRight,
  CheckCircle2,
  FolderCheck,
} from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader, StatTile, Bento } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { TrendChart, CategoryBarChart, PipelineChart } from '@/components/charts'
import { formatNumber, formatDate, formatDateTime, cn } from '@/lib/utils'

/**
 * The dashboard.
 *
 * Laid out as a bento rather than a uniform grid, because the panels are not
 * equally important and a grid of identical cards says they are. Size carries
 * meaning here: the hiring-against-attrition trend is the widest thing on the
 * page because it is the one panel that answers "how are we doing"; the queue of
 * outstanding work is tall and sits beside it because it answers "what do I do
 * next"; everything else is reference the reader drops into afterwards.
 *
 * Reading order is deliberate too. Work waiting on a person comes before any
 * chart — someone opening this at nine in the morning wants the queue, not a
 * twelve-month line.
 */
export default function Dashboard() {
  const { data, loading, error, refetch, updatedAt } = useApi('/dashboard/summary')
  const [now, setNow] = React.useState(() => Date.now())

  React.useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 60_000)
    return () => clearInterval(timer)
  }, [])

  if (loading) return <LoadingState label="Loading dashboard…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const {
    headline,
    attention,
    applicants_by_folder,
    applicants_by_status,
    deployments_by_company,
    violations_by_type,
    monthly_trend,
    open_requests,
  } = data

  return (
    <>
      <PageHeader
        title="Dashboard"
        description="Recruitment and deployment activity for CDE Manpower Services."
      />
      <p className="mb-4 text-xs text-muted-foreground" role="status">
        {updatedAt && now - updatedAt > 300_000 ? 'Data may be stale. ' : ''}
        Updated {formatDateTime(updatedAt)}
      </p>

      <Bento>
        {/* ---------------------------------------------------- headline row */}
        <Cell span="xl:col-span-3 sm:col-span-3">
          <StatTile
            label="Active applicants"
            value={formatNumber(headline.active_applicants)}
            hint={`${formatNumber(headline.total_applicants)} on record in total`}
            icon={Users}
            to="/applicants"
          />
        </Cell>
        <Cell span="xl:col-span-3 sm:col-span-3">
          <StatTile
            label="Ready for deployment"
            value={formatNumber(headline.ready_for_deployment)}
            hint="All requirements verified"
            icon={UserCheck}
            tone="success"
            to="/applicants?folder_category=folder_1"
          />
        </Cell>
        <Cell span="xl:col-span-3 sm:col-span-3">
          <StatTile
            label="Active employees"
            value={formatNumber(headline.active_employees)}
            hint={`Across ${formatNumber(headline.client_companies)} client companies`}
            icon={Building2}
            to="/employees"
          />
        </Cell>
        <Cell span="xl:col-span-3 sm:col-span-3">
          <StatTile
            label="Positions to fill"
            value={formatNumber(headline.positions_to_fill)}
            hint={`${formatNumber(headline.open_requests)} open requests`}
            icon={ClipboardList}
            tone={headline.positions_to_fill > 0 ? 'warning' : 'default'}
            to="/job-requests?only_open=1"
          />
        </Cell>

        {/* ------------------------------------------- the story, and the queue */}
        <Cell span="xl:col-span-8 sm:col-span-6">
          <Panel
            title="Hiring and attrition"
            description="Deployments read against resignations and terminations, so replacement hiring is distinguishable from real growth."
          >
            {monthly_trend?.length ? (
              <TrendChart data={monthly_trend} />
            ) : (
              <EmptyState title="No activity recorded yet" />
            )}
          </Panel>
        </Cell>

        <Cell span="xl:col-span-4 sm:col-span-6">
          <ActionQueue attention={attention} />
        </Cell>

        {/* --------------------------------------------------- pipeline shape */}
        <Cell span="xl:col-span-5 sm:col-span-6">
          <Panel title="Applicants by stage" description="Where the current pipeline sits, earliest stage first.">
            {applicants_by_status?.length ? (
              <PipelineChart data={applicants_by_status} />
            ) : (
              <EmptyState title="No applicants yet" />
            )}
          </Panel>
        </Cell>

        <Cell span="xl:col-span-3 sm:col-span-3">
          <Panel
            title="Document folders"
            description="Applicants by document completeness."
            icon={FolderCheck}
            action={
              // The document library is a different question from these folders
              // - papers by kind, rather than one applicant's completeness - so
              // it is linked from here rather than folded into it.
              <Button variant="ghost" size="sm" asChild>
                <Link to="/documents">
                  Browse documents <ArrowRight className="h-3.5 w-3.5" />
                </Link>
              </Button>
            }
          >
            <div className="space-y-2.5">
              {applicants_by_folder?.map((folder) => (
                <div key={folder.folder} className="rounded-lg border bg-muted/30 p-3">
                  <div className="flex items-start justify-between gap-2">
                    <p className="text-sm font-medium leading-tight">{folder.label}</p>
                    <span className="text-lg font-semibold leading-none">{formatNumber(folder.count)}</span>
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">{folder.description}</p>
                </div>
              ))}
            </div>
          </Panel>
        </Cell>

        {/* ------------------------------------------------------- this month */}
        <Cell span="xl:col-span-4 sm:col-span-3">
          <Panel title="This month" description="Movement since the first of the month.">
            <dl className="grid grid-cols-2 gap-2.5">
              <MonthStat label="Deployments" value={headline.deployments_this_month} tone="success" />
              <MonthStat label="Resignations" value={headline.resignations_this_month} />
              <MonthStat
                label="Terminations"
                value={headline.terminations_this_month}
                tone={headline.terminations_this_month > 0 ? 'destructive' : 'default'}
              />
              <MonthStat label="Client companies" value={headline.client_companies} />
            </dl>
          </Panel>
        </Cell>

        {/* ------------------------------------------------------ the details */}
        <Cell span="xl:col-span-7 sm:col-span-6">
          <OpenRequests requests={open_requests} />
        </Cell>

        <Cell span="xl:col-span-5 sm:col-span-6">
          <Panel title="Deployed workforce by client" description="Active placements per client company.">
            {deployments_by_company?.length ? (
              <CategoryBarChart data={deployments_by_company} label="Employees" horizontal />
            ) : (
              <EmptyState title="No active deployments" description="Deployments appear here once recorded." />
            )}
          </Panel>
        </Cell>

        <Cell span="xl:col-span-12 sm:col-span-6">
          <Panel
            title="Disciplinary records by type"
            description="Violations logged across all deployed employees."
          >
            {violations_by_type?.length ? (
              <CategoryBarChart data={violations_by_type} label="Violations" />
            ) : (
              <EmptyState
                title="No violations recorded"
                description="A clean disciplinary record across the workforce."
              />
            )}
          </Panel>
        </Cell>
      </Bento>

    </>
  )
}

/** Positions a panel in the bento grid. */
function Cell({ span, children }) {
  return <div className={cn('min-w-0', span)}>{children}</div>
}

/**
 * The standard bento panel.
 *
 * Full height so neighbouring cells in a row line up at the bottom, which is
 * what stops a bento from looking like cards that happened to land near each
 * other.
 */
function Panel({ title, description, icon: Icon, action, children, className }) {
  return (
    <Card className={cn('flex h-full flex-col', className)}>
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            <CardTitle className="flex items-center gap-2 text-base">
              {Icon && <Icon className="h-4 w-4 text-muted-foreground" />}
              {title}
            </CardTitle>
            {description && <CardDescription>{description}</CardDescription>}
          </div>
          {action}
        </div>
      </CardHeader>
      <CardContent className="flex-1">{children}</CardContent>
    </Card>
  )
}

function MonthStat({ label, value, tone = 'default' }) {
  const tones = {
    default: 'text-foreground',
    success: 'text-success',
    destructive: 'text-destructive',
  }

  return (
    <div className="rounded-lg border bg-muted/30 p-3">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={cn('mt-0.5 text-xl font-semibold tracking-tight', tones[tone])}>
        {formatNumber(value)}
      </dd>
    </div>
  )
}

/**
 * The queue of items that need a person to act. Anything at zero is dropped
 * rather than shown as a reassuring "0", so the panel only ever contains real
 * work.
 */
function ActionQueue({ attention }) {
  const items = [
    {
      key: 'incomplete_requirements',
      label: 'Applicants with incomplete requirements',
      value: attention.incomplete_requirements,
      icon: FileX2,
      to: '/applicants?current_status=incomplete_requirements',
      tone: 'warning',
    },
    {
      key: 'expired_documents',
      label: 'Expired documents',
      value: attention.expired_documents,
      icon: CalendarClock,
      to: '/applicants',
      tone: 'destructive',
      hint: 'Verified documents that have since lapsed',
    },
    {
      key: 'overdue_requests',
      label: 'Requests past their deadline',
      value: attention.overdue_requests,
      icon: ClipboardList,
      to: '/job-requests?only_open=1',
      tone: 'destructive',
    },
    {
      key: 'open_violations',
      label: 'Unresolved violations',
      value: attention.open_violations,
      icon: FileWarning,
      to: '/employees',
      tone: 'warning',
    },
    {
      key: 'pending_clearance',
      label: 'Resignations awaiting clearance',
      value: attention.pending_clearance,
      icon: FileWarning,
      to: '/separations',
      tone: 'warning',
    },
    {
      key: 'terminations_for_review',
      label: 'Terminations awaiting review',
      value: attention.terminations_for_review,
      icon: AlertTriangle,
      to: '/separations',
      tone: 'destructive',
    },
  ].filter((item) => item.value > 0)

  if (items.length === 0) {
    return (
      <Card className="flex h-full flex-col border-success/30 bg-success/5">
        <CardContent className="flex flex-1 flex-col items-center justify-center gap-2 py-10 text-center">
          <span className="flex h-11 w-11 items-center justify-center rounded-full bg-success/15">
            <CheckCircle2 className="h-6 w-6 text-success" />
          </span>
          <p className="text-sm font-medium">Nothing needs attention</p>
          <p className="max-w-[15rem] text-xs text-muted-foreground">
            No incomplete requirements, overdue requests, or unresolved cases.
          </p>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card className="flex h-full flex-col">
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center justify-between gap-2 text-base">
          Needs attention
          <Badge tone="warning">{items.length}</Badge>
        </CardTitle>
        <CardDescription>Items waiting on action from HR.</CardDescription>
      </CardHeader>
      <CardContent className="flex-1 space-y-2">
        {items.map((item) => (
          <Link
            key={item.key}
            to={item.to}
            className="group flex items-center gap-3 rounded-lg border p-2.5 transition-colors hover:border-primary/40 hover:bg-accent/50"
          >
            <span
              className={cn(
                'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                item.tone === 'destructive'
                  ? 'bg-destructive/10 text-destructive'
                  : 'bg-warning/15 text-warning'
              )}
            >
              <item.icon className="h-4 w-4" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium leading-tight">{item.label}</p>
              {item.hint && <p className="text-xs text-muted-foreground">{item.hint}</p>}
            </div>
            <span className="text-lg font-semibold tabular-nums">{formatNumber(item.value)}</span>
          </Link>
        ))}
      </CardContent>
    </Card>
  )
}

function OpenRequests({ requests }) {
  return (
    <Card className="flex h-full flex-col">
      <CardHeader className="flex-row items-start justify-between space-y-0 pb-3">
        <div className="space-y-1">
          <CardTitle className="text-base">Open manpower requests</CardTitle>
          <CardDescription>Ordered by deployment deadline.</CardDescription>
        </div>
        <Button variant="ghost" size="sm" asChild>
          <Link to="/job-requests">
            View all <ArrowRight className="h-3.5 w-3.5" />
          </Link>
        </Button>
      </CardHeader>
      <CardContent className="flex-1">
        {!requests?.length ? (
          <EmptyState title="No open requests" description="All client requests are currently fulfilled or closed." />
        ) : (
          <ul className="divide-y">
            {requests.map((request) => (
              <li key={request.id}>
                <Link
                  to={`/job-requests/${request.id}`}
                  className="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-3 transition-colors hover:bg-accent/40"
                >
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">{request.position_title}</p>
                    <p className="truncate text-xs text-muted-foreground">
                      {request.company} · {request.department}
                    </p>
                  </div>
                  <div className="flex shrink-0 items-center gap-3">
                    <div className="text-right">
                      <p className="text-sm font-medium tabular-nums">
                        {request.workers_fulfilled}/{request.workers_needed}
                      </p>
                      <p className="text-xs text-muted-foreground">filled</p>
                    </div>
                    <DeadlineBadge days={request.days_remaining} date={request.deadline} />
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

function DeadlineBadge({ days, date }) {
  if (days === null || days === undefined) {
    return <Badge tone="muted">No deadline</Badge>
  }

  if (days < 0) {
    return <Badge tone="destructive">{Math.abs(days)}d overdue</Badge>
  }

  // Under a week is where a request starts needing daily attention.
  return (
    <Badge tone={days <= 7 ? 'warning' : 'muted'} title={formatDate(date)}>
      {days}d left
    </Badge>
  )
}
