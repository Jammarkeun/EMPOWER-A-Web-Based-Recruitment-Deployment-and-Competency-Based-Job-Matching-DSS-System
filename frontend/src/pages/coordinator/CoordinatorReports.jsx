import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Flag, Plus, ChevronRight } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { formatDate, formatDateTime } from '@/lib/utils'
import { Pagination } from '@/components/Pagination'
import { ReportStatus } from './CoordinatorEmployeeDetail'

/**
 * The coordinator's violation reports for their client.
 *
 * Reports are the coordinator's way of passing on what the client reported.
 * HR reviews each one; only a validated report becomes an official violation.
 */
export default function CoordinatorReports() {
  const [searchParams, setSearchParams] = useSearchParams()
  const status = searchParams.get('status') ?? ''
  const mine = searchParams.get('mine') === '1'
  const page = Number(searchParams.get('page') ?? 1)

  const options = useApi('/coordinator/violation-reports/options', undefined, { cacheKey: 'coordinator-report-options' })
  const { data, meta, loading, error, refetch } = useApi('/coordinator/violation-reports', {
    status: status || undefined,
    mine: mine ? 1 : undefined,
    page,
    per_page: 25,
  })

  function update(key, value) {
    const next = new URLSearchParams(searchParams)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setSearchParams(next)
  }

  return (
    <div className="space-y-4">
      <PageHeader
        title="Violation Reports"
        description="Incidents reported by your client, written up for CDE's HR. HR reviews each report; only a validated report becomes an official violation."
        actions={
          <Button asChild>
            <Link to="/coordinator/reports/new">
              <Plus className="h-4 w-4" />
              New report
            </Link>
          </Button>
        }
      />

      <FilterToolbar
        filters={[
          {
            id: 'status',
            label: 'All statuses',
            value: status,
            onChange: (value) => update('status', value),
            options: [{ value: '', label: 'All statuses' }, ...(options.data?.statuses ?? [])],
          },
        ]}
        viewToggles={[{ id: 'mine', label: 'Mine only', active: mine, onToggle: () => update('mine', mine ? '' : '1') }]}
        resultCount={meta?.total}
        resultLabel="reports"
      />

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={5} columns={6} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={Flag}
          title="No reports"
          description="When your client reports an incident involving a CDE worker, write it up here and submit it to HR."
          action={
            <Button size="sm" asChild>
              <Link to="/coordinator/reports/new">New report</Link>
            </Button>
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Report</TableHead>
                <TableHead>Employee</TableHead>
                <TableHead>Violation</TableHead>
                <TableHead>Incident</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-8" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((report) => (
                <TableRow key={report.id} className="group">
                  <TableCell>
                    <Link to={`/coordinator/reports/${report.id}`} className="block">
                      <p className="font-mono text-sm font-semibold group-hover:text-brand">{report.report_code}</p>
                      <p className="text-xs text-muted-foreground">
                        {report.reported_by?.name} · {formatDateTime(report.submitted_at ?? report.created_at)}
                      </p>
                    </Link>
                  </TableCell>
                  <TableCell>
                    <p className="text-sm font-medium">{report.employee?.full_name}</p>
                    <p className="text-xs text-muted-foreground">{report.department_name ?? '—'}</p>
                  </TableCell>
                  <TableCell className="text-sm">{report.type_label}</TableCell>
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">{formatDate(report.incident_date)}</TableCell>
                  <TableCell>
                    <ReportStatus status={report.status} label={report.status_label} />
                  </TableCell>
                  <TableCell className="text-right">
                    <Link to={`/coordinator/reports/${report.id}`} className="text-muted-foreground group-hover:text-brand">
                      <ChevronRight className="h-4 w-4" />
                    </Link>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <Pagination meta={meta} onPage={(p) => update('page', String(p))} />
        </div>
      )}
    </div>
  )
}
