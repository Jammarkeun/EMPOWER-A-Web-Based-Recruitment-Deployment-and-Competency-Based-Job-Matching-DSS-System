import * as React from 'react'
import { Link, useParams } from 'react-router-dom'
import { Flag, Truck, CalendarClock, ShieldAlert } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import PersonAvatar from '@/components/PersonAvatar'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import { formatMinutes } from '@/components/AttendancePanel'
import { cn, formatDate } from '@/lib/utils'

/**
 * One worker at the coordinator's client: where they are placed, under whom,
 * their disciplinary record, and the coordinator's reports about them.
 *
 * Read-only, and only what coordination needs. Personal details such as birth
 * date, home address, and government numbers stay with HR. Opening this page
 * is recorded in the audit trail.
 */
export default function CoordinatorEmployeeDetail() {
  const { id } = useParams()
  const { data, loading, error, refetch } = useApi(`/coordinator/employees/${id}`)

  if (loading) return <LoadingState label="Loading employee…" />
  if (error?.status === 403) return <OutOfScope message={error.message} backTo="/coordinator/employees" backLabel="Back to your employees" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const { employee, violations, violation_policy: policy, reports, deployments, attendance } = data
  const flagged = policy.threshold > 0 && policy.active_count >= policy.threshold

  return (
    <div className="space-y-5">
      <PageHeader
        breadcrumbs={[{ label: 'Employees', to: '/coordinator/employees' }, { label: employee.full_name }]}
        leading={<PersonAvatar src={employee.photo_url} name={employee.full_name} size="lg" />}
        title={employee.full_name}
        description={
          <>
            <span className="font-mono">{employee.employee_number}</span>
            {employee.position && <> · {employee.position}</>}
            {employee.department && <> · {employee.department}</>}
          </>
        }
        actions={
          employee.employment_status === 'active' && (
            <Button asChild>
              <Link to={`/coordinator/reports/new?employee=${employee.id}`}>
                <Flag className="h-4 w-4" />
                Report an incident
              </Link>
            </Button>
          )
        }
      />

      <div className="flex flex-wrap gap-2">
        <StatusBadge status={employee.employment_status} />
        {flagged && <Badge tone="warning">At the review threshold — HR and the administrator will review</Badge>}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Placement</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
              {[
                ['Position', employee.position],
                ['Department', employee.department],
                ['Supervisor / OIC', employee.supervisor],
                ['Employee number', employee.employee_number],
                ['Biometrics number', employee.biometric_number],
                ['Hire date', formatDate(employee.hire_date)],
              ]
                .filter(([, v]) => v)
                .map(([label, value]) => (
                  <div key={label}>
                    <dt className="text-xs text-muted-foreground">{label}</dt>
                    <dd className="text-sm">{value}</dd>
                  </div>
                ))}
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <CalendarClock className="h-4 w-4 text-muted-foreground" />
              Attendance this month
            </CardTitle>
            <CardDescription>
              {attendance.schedule ? `Schedule: ${attendance.schedule}` : 'No schedule set by HR.'} HR encodes the DTR.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {[
                ['Absent', attendance.summary.days_absent, 'destructive'],
                ['Late', attendance.summary.days_late, 'warning', attendance.summary.late_minutes],
                ['Left early', attendance.summary.days_undertime, 'warning', attendance.summary.undertime_minutes],
                ['Missing punches', attendance.summary.incomplete_records, 'destructive'],
              ].map(([label, value, tone, minutes]) => (
                <div key={label} className="rounded-md border px-3 py-2">
                  <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</dt>
                  <dd
                    className={cn(
                      'text-lg font-semibold tabular-nums',
                      value > 0 && tone === 'destructive' && 'text-destructive',
                      value > 0 && tone === 'warning' && 'text-warning'
                    )}
                  >
                    {value}
                    {minutes > 0 && <span className="ml-1 text-xs font-normal text-muted-foreground">{formatMinutes(minutes)}</span>}
                  </dd>
                </div>
              ))}
            </dl>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Disciplinary record</CardTitle>
          <CardDescription>
            {policy.active_count} counting within the last {policy.window_months} months, against a review threshold
            of {policy.threshold}. Reaching it flags the record for HR; it does not end anyone's employment.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {violations.length === 0 ? (
            <EmptyState icon={Flag} title="No violations on record" />
          ) : (
            <ul className="divide-y">
              {violations.map((v) => (
                <li key={v.id} className={cn('py-2.5', !v.is_active && 'opacity-60')}>
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone="warning">{v.type_label}</Badge>
                    <StatusBadge status={v.status} />
                    {!v.is_active && <Badge tone="muted">No longer counting</Badge>}
                    <span className="text-xs text-muted-foreground">{formatDate(v.violation_date)}</span>
                  </div>
                  <p className="mt-1 text-sm">{v.description}</p>
                  <p className="text-xs text-muted-foreground">
                    {v.penalty && <>Penalty: {v.penalty}. </>}
                    {v.reported_by && <>Reported by {v.reported_by}.</>}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Reports about this employee</CardTitle>
          </CardHeader>
          <CardContent>
            {reports.length === 0 ? (
              <p className="text-sm text-muted-foreground">None.</p>
            ) : (
              <ul className="divide-y">
                {reports.map((r) => (
                  <li key={r.id} className="py-2">
                    <Link to={`/coordinator/reports/${r.id}`} className="flex items-center justify-between gap-2 hover:text-brand">
                      <span className="text-sm">
                        <span className="font-mono">{r.report_code}</span> · {formatDate(r.incident_date)}
                      </span>
                      <ReportStatus status={r.status} label={r.status_label} />
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Deployments at this client</CardTitle>
          </CardHeader>
          <CardContent>
            {deployments.length === 0 ? (
              <EmptyState icon={Truck} title="No deployments" />
            ) : (
              <ul className="divide-y">
                {deployments.map((d) => (
                  <li key={d.id} className="flex items-start justify-between gap-2 py-2">
                    <div>
                      <p className="text-sm font-medium">{d.position}</p>
                      <p className="text-xs text-muted-foreground">
                        {d.department} · from {formatDate(d.deployment_date)}
                        {d.end_date && ` to ${formatDate(d.end_date)}`}
                        {d.supervisor && ` · ${d.supervisor}`}
                      </p>
                    </div>
                    <StatusBadge status={d.deployment_status} />
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

/**
 * A record that belongs to another client.
 *
 * Said plainly, without a retry button: trying again can never succeed, because
 * the refusal comes from the coordinator's assignment, not from a failed load.
 */
export function OutOfScope({ message, backTo, backLabel }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-20 text-center">
      <div className="rounded-full bg-destructive/10 p-3">
        <ShieldAlert className="h-6 w-6 text-destructive" />
      </div>
      <div className="space-y-1">
        <p className="text-sm font-medium">Outside your assigned client</p>
        <p className="max-w-sm text-sm text-muted-foreground">
          {message ?? 'This record belongs to another client company.'} If you have been reassigned, your
          administrator can confirm which client you now cover.
        </p>
      </div>
      <Button variant="outline" size="sm" asChild>
        <Link to={backTo}>{backLabel}</Link>
      </Button>
    </div>
  )
}

const REPORT_TONES = {
  draft: 'muted',
  submitted: 'info',
  under_review: 'info',
  returned: 'warning',
  validated: 'success',
  dismissed: 'muted',
}

export function ReportStatus({ status, label }) {
  return <Badge tone={REPORT_TONES[status] ?? 'muted'}>{label ?? status}</Badge>
}
