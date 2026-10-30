import * as React from 'react'
import { Link } from 'react-router-dom'
import { Users, Truck, Flag, ClipboardList, AlertTriangle, CornerUpLeft, Plus, FileEdit, Siren, FilePlus2 } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader, StatTile } from '@/components/PageHeader'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import { PanelTitle, InsetRow, RowIcon } from '@/components/ui/panel'
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
          <>
            <Button asChild>
              <Link to="/coordinator/reports/new">
                <Plus className="h-4 w-4" />
                New violation report
              </Link>
            </Button>
            <Button variant="outline" asChild>
              <Link to="/coordinator/incidents/new">
                <Siren className="h-4 w-4" />
                New incident report
              </Link>
            </Button>
            <Button variant="outline" asChild>
              <Link to="/coordinator/requisitions/new">
                <FilePlus2 className="h-4 w-4" />
                New requisition
              </Link>
            </Button>
          </>
        }
      />

      {company.status !== 'active' && (
        <div className="rounded-row bg-status-warning-bg px-4 py-3 text-sm">
          This client is marked inactive by CDE. Its records remain available to you.
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <StatTile icon={Users} label="Active employees" value={summary.active_employees ?? 0} to="/coordinator/employees" />
        <StatTile icon={Truck} label="Current deployments" value={summary.current_deployments ?? 0} to="/coordinator/deployments" />
        <StatTile
          icon={ClipboardList}
          label="Open manpower requests"
          value={summary.open_requests ?? 0}
          hint={`${summary.positions_to_fill} workers still needed`}
          to="/coordinator/requests"
        />
        <StatTile icon={Flag} label="Reports awaiting HR" value={summary.reports_pending ?? 0} to="/coordinator/reports?status=pending" />
        <StatTile
          icon={CornerUpLeft}
          label="Returned to you"
          value={summary.reports_returned ?? 0}
          tone={summary.reports_returned ? 'warning' : 'default'}
          to="/coordinator/reports?status=returned&mine=1"
        />
        <StatTile icon={FileEdit} label="Your drafts" value={summary.reports_draft ?? 0} to="/coordinator/reports?status=draft" />
        <StatTile icon={Siren} label="Open incident reports" value={summary.incidents_open ?? 0} to="/coordinator/incidents?status=open" />
        <StatTile
          icon={FilePlus2}
          label="Requisitions awaiting HR"
          value={summary.requisitions_pending ?? 0}
          hint={summary.requisitions_returned ? `${summary.requisitions_returned} returned to you` : undefined}
          tone={summary.requisitions_returned ? 'warning' : 'default'}
          to={summary.requisitions_returned ? '/coordinator/requisitions?status=returned&mine=1' : '/coordinator/requisitions?status=pending'}
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="p-5 sm:p-6">
          <PanelTitle
            icon={AlertTriangle}
            title="Needs your attention"
            description="Reports HR sent back, and workers at the review threshold."
          />
          <div className="mt-5">
            {data.returned_reports.length === 0 && data.flagged_employees.length === 0 ? (
              <p className="rounded-row bg-inset px-4 py-6 text-center text-sm text-muted-foreground">Nothing at the moment.</p>
            ) : (
              <ul className="space-y-2">
                {data.returned_reports.map((report) => (
                  <li key={`r-${report.id}`}>
                    <InsetRow to={`/coordinator/reports/${report.id}`} className="items-start">
                      <RowIcon icon={CornerUpLeft} tone="warning" />
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium leading-snug">
                          {report.report_code} · {report.employee_name}
                        </p>
                        {report.review_note && (
                          <p className="mt-0.5 text-xs text-muted-foreground">HR: {report.review_note}</p>
                        )}
                      </div>
                    </InsetRow>
                  </li>
                ))}
                {data.flagged_employees.map((employee) => (
                  <li key={`e-${employee.id}`}>
                    <InsetRow to={`/coordinator/employees/${employee.id}`} className="items-start">
                      <RowIcon icon={AlertTriangle} tone="warning" />
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium leading-snug">{employee.full_name}</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                          {employee.active_violations} active violations, at the review threshold of {summary.threshold}.
                          HR and the administrator decide what follows.
                        </p>
                      </div>
                    </InsetRow>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Card>

        <Card className="p-5 sm:p-6">
          <PanelTitle icon={Flag} title="Recent violations" description="Official records for workers at this client." />
          <div className="mt-5">
            {data.recent_violations.length === 0 ? (
              <EmptyState icon={Flag} title="No violations on record" />
            ) : (
              <ul className="space-y-2">
                {data.recent_violations.map((violation) => (
                  <li key={violation.id}>
                    <InsetRow to={`/coordinator/employees/${violation.employee.id}`} className="items-start justify-between">
                      <div className="min-w-0">
                        <p className="text-sm font-medium leading-snug">{violation.employee.full_name}</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                          {violation.type_label} · {formatDate(violation.violation_date)}
                        </p>
                      </div>
                      <div className="flex shrink-0 flex-wrap justify-end gap-1.5">
                        {!violation.is_active && <Badge tone="muted">No longer counting</Badge>}
                        <StatusBadge status={violation.status} />
                      </div>
                    </InsetRow>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Card>
      </div>
    </div>
  )
}
