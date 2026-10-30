import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Siren, ChevronRight, MessageSquare } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { formatDate, formatDateTime } from '@/lib/utils'
import { Pagination, useClampPage } from '@/components/Pagination'
import { ReportStatus } from './coordinator/CoordinatorEmployeeDetail'

const STATUS_OPTIONS = [
  { value: 'open', label: 'Open (not resolved)' },
  { value: 'submitted', label: 'Not yet read' },
  { value: 'under_review', label: 'Case pending' },
  { value: 'resolved', label: 'Case resolved' },
  { value: 'all', label: 'All reports' },
]

/**
 * Incidents coordinators have reported from client sites.
 *
 * Opens on what is still open. A coordinator's draft never appears here.
 */
export default function IncidentReports() {
  const [searchParams, setSearchParams] = useSearchParams()
  const status = searchParams.get('status') ?? 'open'
  const company = searchParams.get('client_company_id') ?? ''
  const search = searchParams.get('search') ?? ''
  const page = Number(searchParams.get('page') ?? 1)
  const debounced = useDebounced(search)

  const companies = useApi('/clients', { per_page: 100 }, { cacheKey: 'incident-report-companies' })
  const { data, meta, loading, error, refetch } = useApi('/incident-reports', {
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
        title="Incident Reports"
        description="What coordinators have reported from client sites. Answer with a note and mark each case pending or resolved. An incident is never recorded against an employee."
      />

      <FilterToolbar
        search={search}
        onSearchChange={(value) => update('search', value)}
        searchPlaceholder="Search by report code or title…"
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
          <SkeletonRows rows={5} columns={5} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={Siren}
          title={
            search || company
              ? 'No incident reports match these filters'
              : status === 'open'
                ? 'Nothing open'
                : 'No incident reports'
          }
          description={
            search || company
              ? 'Try another client or status, or search for part of the title or report code.'
              : 'Reports appear here when a coordinator sends them.'
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Report</TableHead>
                <TableHead>What happened</TableHead>
                <TableHead>Client · department</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-8" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((report) => (
                <TableRow key={report.id} className="group">
                  <TableCell>
                    <Link to={`/incident-reports/${report.id}`} className="block">
                      <p className="font-mono text-sm font-semibold group-hover:text-brand">{report.report_code}</p>
                      <p className="text-xs text-muted-foreground">
                        {report.reported_by?.name} · {formatDateTime(report.submitted_at)}
                      </p>
                    </Link>
                  </TableCell>
                  <TableCell>
                    <p className="text-sm font-medium">{report.title}</p>
                    <p className="text-xs text-muted-foreground">
                      {formatDate(report.incident_date)}
                      {report.incident_time && ` · ${report.incident_time}`}
                      {report.notes_count > 0 && (
                        <span className="ml-2 inline-flex items-center gap-1">
                          <MessageSquare className="h-3 w-3" /> {report.notes_count}
                        </span>
                      )}
                    </p>
                  </TableCell>
                  <TableCell className="text-sm">
                    {report.company_name}
                    <p className="text-xs text-muted-foreground">{report.department_name ?? '—'}</p>
                  </TableCell>
                  <TableCell>
                    <ReportStatus status={report.status} label={report.status_label} />
                  </TableCell>
                  <TableCell className="text-right">
                    <Link to={`/incident-reports/${report.id}`} className="text-muted-foreground group-hover:text-brand">
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
