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
  ArrowUpRight,
  CheckCircle2,
  FolderCheck,
  Truck,
  BarChart3,
  TrendingUp,
  Layers,
  Flag,
} from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { PageHeader, StatTile, Bento } from '@/components/PageHeader'
import { Card } from '@/components/ui/card'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { PanelTitle, InsetRow, RowIcon, ProgressTrack } from '@/components/ui/panel'
import { TrendChart, CategoryBarChart, PipelineChart } from '@/components/charts'
import { formatNumber, formatDate, formatDateTime, cn } from '@/lib/utils'

/**
 * The dashboard.
 *
 * Laid out as a bento rather than a uniform grid, because the panels are not
 * equally important and a grid of identical cards says they are. The headline
 * figures come first, then the three panels someone opening this at nine in the
 * morning actually acts on - who is ready to place, which requests are due, and
 * what is waiting on HR - and only then the charts, which are reference the
 * reader drops into afterwards.
 */
export default function Dashboard() {
  const { user } = useAuth()
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
    links,
  } = data

  const firstName = String(user?.full_name ?? '').trim().split(/\s+/)[0]

  return (
    <>
      <PageHeader
        title={firstName ? `${greeting(now)}, ${firstName}` : 'Dashboard'}
        description={
          <span role="status">
            {updatedAt && now - updatedAt > 300_000 ? 'Data may be stale. ' : ''}
            Updated {formatDateTime(updatedAt)}
          </span>
        }
      />

      <Bento>
        {/* ---------------------------------------------------- headline row */}
        <Cell span="xl:col-span-3 sm:col-span-3">
          <StatTile
            label="Active applicants"
            value={formatNumber(headline.active_applicants)}
            hint={`${formatNumber(headline.total_applicants)} on record`}
            icon={Users}
            to="/applicants"
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
        <Cell span="xl:col-span-3 sm:col-span-3">
          <StatTile
            label="Deployed this month"
            value={formatNumber(headline.deployments_this_month)}
            hint={`${formatNumber(headline.resignations_this_month)} resigned · ${formatNumber(headline.terminations_this_month)} terminated`}
            icon={Truck}
            tone={headline.terminations_this_month > 0 ? 'destructive' : 'default'}
          />
        </Cell>

        {/* ------------------------------- ready, due, and waiting on someone */}
        <Cell span="xl:col-span-4 sm:col-span-6">
          <div className="flex h-full flex-col gap-4">
            <ReadyHighlight
              ready={headline.ready_for_deployment}
              active={headline.active_applicants}
              to={applicantListPath(links?.ready_for_deployment)}
            />
            <Panel
              title="Applicants by stage"
              icon={Layers}
              description="Where the current pipeline sits, earliest stage first."
              className="flex-1"
            >
              {applicants_by_status?.length ? (
                <PipelineChart data={applicants_by_status} />
              ) : (
                <EmptyState title="No applicants yet" />
              )}
            </Panel>
          </div>
        </Cell>

        <Cell span="xl:col-span-4 sm:col-span-3">
          <OpenRequests requests={open_requests} />
        </Cell>

        <Cell span="xl:col-span-4 sm:col-span-3">
          <ActionQueue attention={attention} links={links} />
        </Cell>

        {/* ------------------------------------------------------ the story */}
        <Cell span="xl:col-span-12 sm:col-span-6">
          <Panel
            title="Hiring and attrition"
            icon={TrendingUp}
            description="Deployments read against resignations and terminations, so replacement hiring is distinguishable from real growth."
          >
            {monthly_trend?.length ? (
              <TrendChart data={monthly_trend} />
            ) : (
              <EmptyState title="No activity recorded yet" />
            )}
          </Panel>
        </Cell>

        {/* ------------------------------------------------------ the details */}
        <Cell span="xl:col-span-7 sm:col-span-6">
          <Panel title="Deployed workforce by client" icon={BarChart3} description="Active placements per client company.">
            {deployments_by_company?.length ? (
              <CategoryBarChart data={deployments_by_company} label="Employees" horizontal />
            ) : (
              <EmptyState title="No active deployments" description="Deployments appear here once recorded." />
            )}
          </Panel>
        </Cell>

        <Cell span="xl:col-span-5 sm:col-span-6">
          <Panel
            title="Document folders"
            description="Applicants by document completeness."
            icon={FolderCheck}
            action={
              // The document library is a different question from these folders
              // - papers by kind, rather than one applicant's completeness - so
              // it is linked from here rather than folded into it.
              <Button variant="outline" size="sm" asChild>
                <Link to="/documents">Browse documents</Link>
              </Button>
            }
          >
            <div className="space-y-2">
              {applicants_by_folder?.map((folder) => (
                <InsetRow key={folder.folder} className="items-start p-4">
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium leading-tight">{folder.label}</p>
                    <p className="mt-1 text-xs text-muted-foreground">{folder.description}</p>
                  </div>
                  <span className="text-2xl font-normal leading-none tabular-nums">{formatNumber(folder.count)}</span>
                </InsetRow>
              ))}
            </div>
          </Panel>
        </Cell>

        <Cell span="xl:col-span-12 sm:col-span-6">
          <Panel title="Disciplinary records by type" icon={Flag} description="Violations logged across all deployed employees.">
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

/** Morning, afternoon, or evening on this computer's clock. */
function greeting(timestamp) {
  const hour = new Date(timestamp).getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
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
function Panel({ title, description, icon, action, children, className }) {
  return (
    <Card className={cn('flex h-full flex-col p-5 sm:p-6', className)}>
      <PanelTitle icon={icon} title={title} description={description} action={action} />
      <div className="mt-5 min-h-0 flex-1">{children}</div>
    </Card>
  )
}

/**
 * The applicant list opened on the filters the server counted a figure with,
 * so the number on the tile and the people on the list are the same.
 */
function listPath(path, filters) {
  const query = new URLSearchParams(filters ?? {}).toString()
  return query ? `${path}?${query}` : path
}

function applicantListPath(filters) {
  return listPath('/applicants', filters)
}

/**
 * The one highlighted figure: applicants ready to place, out of everyone still
 * active. Ready applicants are counted from the active pool, so the bar can
 * never run past full.
 */
function ReadyHighlight({ ready, active, to }) {
  const percent = active > 0 ? Math.min(100, (ready / active) * 100) : 0

  return (
    <Link
      to={to}
      className="surface-highlight group block rounded-panel p-5 text-white transition-shadow hover:shadow-float sm:p-6"
    >
      <div className="flex items-center gap-2 text-sm text-white/90">
        <UserCheck className="h-4 w-4" aria-hidden="true" />
        Ready for deployment
        <ArrowUpRight className="ml-auto h-4 w-4 opacity-70 transition-opacity group-hover:opacity-100" aria-hidden="true" />
      </div>

      <div className="mt-5 flex items-end justify-between gap-3">
        <p className="text-[44px] font-normal leading-none tracking-tight">
          {formatNumber(ready)}
          <span className="ml-1 text-[28px] text-white/80">/ {formatNumber(active)}</span>
        </p>
        <p className="pb-1 text-right text-sm leading-tight text-white/90">
          All requirements
          <span className="block text-xs text-white/75">verified</span>
        </p>
      </div>

      <ProgressTrack
        tone="light"
        value={percent}
        className="mt-5 h-4"
        label={`${formatNumber(ready)} of ${formatNumber(active)} active applicants are ready for deployment`}
      />
    </Link>
  )
}

/**
 * The queue of items that need a person to act. Anything at zero is dropped
 * rather than shown as a reassuring "0", so the panel only ever contains real
 * work.
 */
function ActionQueue({ attention, links }) {
  const items = [
    {
      key: 'incomplete_requirements',
      label: 'Applicants with incomplete requirements',
      value: attention.incomplete_requirements,
      icon: FileX2,
      to: applicantListPath(links?.incomplete_requirements),
      tone: 'warning',
    },
    {
      key: 'expired_documents',
      label: 'Expired documents',
      value: attention.expired_documents,
      icon: CalendarClock,
      to: '/applicants',
      tone: 'destructive',
      hint: 'Lapsed while the applicant waited for a posting',
    },
    {
      key: 'employee_renewals_overdue',
      label: 'Employee renewals overdue',
      value: attention.employee_renewals_overdue,
      icon: CalendarClock,
      to: '/employees',
      tone: 'warning',
      hint: 'Annual medical or health card lapsed; renewed while employed',
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
      to: listPath('/separations', links?.pending_clearance),
      tone: 'warning',
    },
    {
      // Open, but the next move is the employee's: counted apart so the
      // figure above is only HR's own work.
      key: 'resignations_awaiting_employee',
      label: 'Resignations waiting on the employee',
      value: attention.resignations_awaiting_employee,
      icon: FileWarning,
      to: listPath('/separations', links?.resignations_awaiting_employee),
      tone: 'info',
      hint: 'HR asked them for more information',
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

  return (
    <Panel
      title="Needs attention"
      icon={AlertTriangle}
      action={items.length > 0 ? <Badge tone="warning">{items.length}</Badge> : null}
    >
      {items.length === 0 ? (
        <div className="flex h-full min-h-[14rem] flex-col items-center justify-center gap-2 rounded-row bg-inset px-6 py-10 text-center">
          <span className="flex h-11 w-11 items-center justify-center rounded-full bg-card">
            <CheckCircle2 className="h-5 w-5 text-status-success-dot" />
          </span>
          <p className="text-sm font-medium">Nothing needs attention</p>
          <p className="max-w-[15rem] text-xs text-muted-foreground">
            No incomplete requirements, overdue requests, or unresolved cases.
          </p>
        </div>
      ) : (
        <div className="space-y-2">
          {items.map((item) => (
            <InsetRow key={item.key} to={item.to}>
              <RowIcon icon={item.icon} tone={item.tone} />
              <div className="min-w-0 flex-1">
                <p className="text-sm leading-snug">{item.label}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">{item.hint ?? 'Waiting on HR'}</p>
              </div>
              <span className="text-lg font-medium tabular-nums">{formatNumber(item.value)}</span>
            </InsetRow>
          ))}
        </div>
      )}
    </Panel>
  )
}

function OpenRequests({ requests }) {
  return (
    <Panel
      title="Open manpower requests"
      icon={ClipboardList}
      description="Ordered by deployment deadline."
      action={
        <Button variant="outline" size="sm" asChild>
          <Link to="/job-requests">View all</Link>
        </Button>
      }
    >
      {!requests?.length ? (
        <EmptyState title="No open requests" description="All client requests are currently fulfilled or closed." />
      ) : (
        <ul className="space-y-2">
          {requests.map((request) => {
            const percent = request.workers_needed > 0 ? (request.workers_fulfilled / request.workers_needed) * 100 : 0

            return (
              <li key={request.id}>
                <InsetRow to={`/job-requests/${request.id}`} className="block p-4">
                  <div className="flex items-center justify-between gap-2">
                    <p className="text-xs text-muted-foreground">
                      {request.deadline ? formatDate(request.deadline) : 'No deadline set'}
                    </p>
                    <DeadlineBadge days={request.days_remaining} date={request.deadline} />
                  </div>

                  <div className="mt-2.5 flex items-center justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate text-[15px] font-medium leading-tight">{request.position_title}</p>
                      <p className="mt-1 truncate text-xs text-muted-foreground">
                        {request.company} · {request.department}
                      </p>
                    </div>
                    <div className="shrink-0 text-right">
                      <p className="text-sm font-medium tabular-nums">
                        {request.workers_fulfilled}/{request.workers_needed}
                      </p>
                      <p className="text-xs text-muted-foreground">filled</p>
                    </div>
                  </div>

                  <ProgressTrack
                    value={percent}
                    tone={request.workers_fulfilled >= request.workers_needed ? 'success' : 'default'}
                    className="mt-3 h-2"
                    label={`${request.workers_fulfilled} of ${request.workers_needed} filled`}
                  />
                </InsetRow>
              </li>
            )
          })}
        </ul>
      )}
    </Panel>
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
