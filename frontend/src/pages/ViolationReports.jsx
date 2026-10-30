import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Flag, ChevronRight } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { formatDate, formatDateTime } from '@/lib/utils'
import { Pagination, useClampPage } from '@/components/Pagination'
import { ReportStatus } from './coordinator/CoordinatorEmployeeDetail'

const STATUS_OPTIONS = [
  { value: 'pending', label: 'Waiting on HR' },
  { value: 'returned', label: 'Returned for correction' },
  { value: 'validated', label: 'Validated' },
  { value: 'dismissed', label: 'Dismissed' },
  { value: 'all', label: 'All reports' },
]

/**
 * Coordinators' reports, for HR to review.
 *
 * Opens on what is waiting. A coordinator's draft never appears here: it has
 * not been sent.
 */
export default function ViolationReports() {
  const [searchParams, setSearchParams] = useSearchParams()
  const status = searchParams.get('status') ?? 'pending'
  const company = searchParams.get('client_company_id') ?? ''
  const search = searchParams.get('search') ?? ''
  const page = Number(searchParams.get('page') ?? 1)
  const debounced = useDebounced(search)

  const companies = useApi('/clients', { per_page: 100 }, { cacheKey: 'violation-report-companies' })
  const { data, meta, loading, error, refetch } = useApi('/violation-reports', {
    status,
    client_company_id: company || undefined,
    search: debounced || undefined,
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

  useClampPage(meta, (p) => update('page', String(p)))

  return (
    <div className="space-y-4">
      <PageHeader
        title="Violation Reports"
        description="Incidents coordinators have reported from client sites. Validating one records the official violation; nothing here ends anyone's employment."
      />

      <FilterToolbar
        search={search}
        onSearchChange={(value) => update('search', value)}
        searchPlaceholder="Search by report code, employee name or number…"
        filters={[
          { id: 'status', label: 'Status', value: status, onChange: (value) => update('status', value), options: STATUS_OPTIONS },
          {
            id: 'company',
            label: 'All client companies',
            value: company,
            onChange: (value) => update('client_company_id', value),
            options: [
              { value: '', label: 'All client companies' },
              ...(companies.data ?? []).map((c) => ({ value: String(c.id), label: c.company_name })),
            ],
          },
        ]}
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
          title={
            search || company
              ? 'No reports match these filters'
              : status === 'pending'
                ? 'Nothing waiting for review'
                : 'No reports'
          }
          description={
            search || company
              ? 'Try another client or status, or search for part of the name or report code.'
              : 'Reports appear here when a coordinator submits them.'
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Report</TableHead>
                <TableHead>Employee</TableHead>
                <TableHead>Client · department</TableHead>
                <TableHead>Violation</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-8" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((report) => (
                <TableRow key={report.id} className="group">
                  <TableCell>
                    <Link to={`/violation-reports/${report.id}`} className="block">
                      <p className="font-mono text-sm font-semibold group-hover:text-brand">{report.report_code}</p>
                      <p className="text-xs text-muted-foreground">
                        {report.reported_by?.name} · {formatDateTime(report.submitted_at)}
                      </p>
                    </Link>
                  </TableCell>
                  <TableCell>
                    <p className="text-sm font-medium">{report.employee?.full_name}</p>
                    <p className="font-mono text-xs text-muted-foreground">{report.employee?.employee_number}</p>
                  </TableCell>
                  <TableCell className="text-sm">
                    {report.company_name}
                    <p className="text-xs text-muted-foreground">{report.department_name ?? '—'}</p>
                  </TableCell>
                  <TableCell className="text-sm">
                    {report.type_label}
                    <p className="text-xs text-muted-foreground">{formatDate(report.incident_date)}</p>
                  </TableCell>
                  <TableCell>
                    <ReportStatus status={report.status} label={report.status_label} />
                  </TableCell>
                  <TableCell className="text-right">
                    <Link to={`/violation-reports/${report.id}`} className="text-muted-foreground group-hover:text-brand">
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
