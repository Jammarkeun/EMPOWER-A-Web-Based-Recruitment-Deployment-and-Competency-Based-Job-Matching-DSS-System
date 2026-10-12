import * as React from 'react'
import { Link } from 'react-router-dom'
import { Users, Truck, Flag, ClipboardList, AlertTriangle, CornerUpLeft, Plus, FileEdit } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import { formatDate } from '@/lib/utils'

/**
 * The coordinator's home: their one client, at a glance.
 *
 * Every figure here comes from the coordinator API, which scopes to the client
 * in the coordinator's assignment on the server. There is no CDE-wide total
 * and no other client on this screen to leak.
 */
export default function CoordinatorDashboard() {
  const { data, loading, error, refetch } = useApi('/coordinator/dashboard')

  if (loading) return <LoadingState label="Loading your client…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const { company, summary } = data

  return (
    <div className="space-y-5">
      <PageHeader
        title="Dashboard"
        description={
          <>
            Assigned client: <span className="font-medium text-foreground">{company.company_name}</span>
            {data.assigned_since && <> · since {formatDate(data.assigned_since)}</>}
          </>
        }
        actions={
          <Button asChild>
            <Link to="/coordinator/reports/new">
              <Plus className="h-4 w-4" />
              New violation report
            </Link>
          </Button>
        }
      />

      {company.status !== 'active' && (
        <div className="rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-sm">
          This client is marked inactive by CDE. Its records remain available to you.
        </div>
      )}

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Tile icon={Users} label="Active employees" value={summary.active_employees} to="/coordinator/employees" />
        <Tile icon={Truck} label="Current deployments" value={summary.current_deployments} to="/coordinator/deployments" />
        <Tile
          icon={ClipboardList}
          label="Open manpower requests"
          value={summary.open_requests}
          hint={`${summary.positions_to_fill} workers still needed`}
          to="/coordinator/requests"
        />
        <Tile icon={Flag} label="Reports awaiting HR" value={summary.reports_pending} to="/coordinator/reports?status=submitted" />
        <Tile
          icon={CornerUpLeft}
          label="Returned to you"
          value={summary.reports_returned}
          tone={summary.reports_returned ? 'warning' : null}
          to="/coordinator/reports?status=returned"
        />
        <Tile icon={FileEdit} label="Your drafts" value={summary.reports_draft} to="/coordinator/reports?status=draft" />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Needs your attention</CardTitle>
            <CardDescription>Reports HR sent back, and workers at the review threshold.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {data.returned_reports.length === 0 && data.flagged_employees.length === 0 ? (
              <p className="text-sm text-muted-foreground">Nothing at the moment.</p>
            ) : (
              <ul className="divide-y">
                {data.returned_reports.map((report) => (
                  <li key={`r-${report.id}`} className="py-2.5">
                    <Link to={`/coordinator/reports/${report.id}`} className="group block">
                      <p className="flex items-center gap-2 text-sm font-medium group-hover:text-brand">
                        <CornerUpLeft className="h-3.5 w-3.5 text-warning" />
                        {report.report_code} · {report.employee_name}
                      </p>
                      {report.review_note && (
                        <p className="mt-0.5 text-xs text-muted-foreground">HR: {report.review_note}</p>
                      )}
                    </Link>
                  </li>
                ))}
                {data.flagged_employees.map((employee) => (
                  <li key={`e-${employee.id}`} className="py-2.5">
                    <Link to={`/coordinator/employees/${employee.id}`} className="group block">
                      <p className="flex items-center gap-2 text-sm font-medium group-hover:text-brand">
                        <AlertTriangle className="h-3.5 w-3.5 text-warning" />
                        {employee.full_name}
                      </p>
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        {employee.active_violations} active violations, at the review threshold of {summary.threshold}.
                        HR and the administrator decide what follows.
                      </p>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Recent violations</CardTitle>
            <CardDescription>Official records for workers at this client.</CardDescription>
          </CardHeader>
          <CardContent>
            {data.recent_violations.length === 0 ? (
              <EmptyState icon={Flag} title="No violations on record" />
            ) : (
              <ul className="divide-y">
                {data.recent_violations.map((violation) => (
                  <li key={violation.id} className="flex items-start justify-between gap-3 py-2.5">
                    <div className="min-w-0">
                      <Link
                        to={`/coordinator/employees/${violation.employee.id}`}
                        className="text-sm font-medium hover:text-brand"
                      >
                        {violation.employee.full_name}
                      </Link>
                      <p className="text-xs text-muted-foreground">
                        {violation.type_label} · {formatDate(violation.violation_date)}
                      </p>
                    </div>
                    <div className="flex shrink-0 gap-1.5">
                      {!violation.is_active && <Badge tone="muted">No longer counting</Badge>}
                      <StatusBadge status={violation.status} />
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

function Tile({ icon: Icon, label, value, hint, tone, to }) {
  return (
    <Link to={to} className="rounded-xl border bg-card p-4 shadow-sm transition-colors hover:border-primary/30">
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
      </div>
      <p className={`mt-2 text-2xl font-semibold tracking-tight ${tone === 'warning' ? 'text-warning' : ''}`}>
        {value ?? 0}
      </p>
      {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}
    </Link>
  )
}
