import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Siren, Plus, ChevronRight, MessageSquare } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { formatDate, formatDateTime } from '@/lib/utils'
import { Pagination, useClampPage } from '@/components/Pagination'
import { ReportStatus } from './CoordinatorEmployeeDetail'

/**
 * The coordinator's incident reports: things that happened at the client's
 * site, for the office to read. Not about one worker - that is a violation
 * report.
 */
export default function CoordinatorIncidents() {
  const [searchParams, setSearchParams] = useSearchParams()
  const status = searchParams.get('status') ?? ''
  const mine = searchParams.get('mine') === '1'
  const page = Number(searchParams.get('page') ?? 1)

  const options = useApi('/coordinator/incident-reports/options', undefined, { cacheKey: 'coordinator-incident-options' })
  const { data, meta, loading, error, refetch } = useApi('/coordinator/incident-reports', {
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

  useClampPage(meta, (p) => update('page', String(p)))

  const newButton = (
    <Button asChild>
      <Link to="/coordinator/incidents/new">
        <Plus className="h-4 w-4" />
        New incident report
      </Link>
    </Button>
  )

  return (
    <div className="space-y-4">
      <PageHeader
        title="Incident Reports"
        description="Things that happened at your client's site, written up for the office - a stoppage, an accident, workers sent home. An incident is not a violation and names no employee."
        actions={newButton}
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
          <SkeletonRows rows={5} columns={5} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={Siren}
          title="No incident reports"
          description="When something happens on site that the office should know about, write it up here."
          action={
            <Button size="sm" asChild>
              <Link to="/coordinator/incidents/new">New incident report</Link>
            </Button>
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Report</TableHead>
                <TableHead>What happened</TableHead>
                <TableHead>When</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-8" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((report) => (
                <TableRow key={report.id} className="group">
                  <TableCell>
                    <Link to={`/coordinator/incidents/${report.id}`} className="block">
                      <p className="font-mono text-sm font-semibold group-hover:text-brand">{report.report_code}</p>
                      <p className="text-xs text-muted-foreground">
                        {report.reported_by?.name} · {formatDateTime(report.submitted_at ?? report.created_at)}
                      </p>
                    </Link>
                  </TableCell>
                  <TableCell>
                    <p className="text-sm font-medium">{report.title}</p>
                    <p className="text-xs text-muted-foreground">
                      {report.department_name ?? report.location ?? '—'}
                      {report.notes_count > 0 && (
                        <span className="ml-2 inline-flex items-center gap-1">
                          <MessageSquare className="h-3 w-3" /> {report.notes_count}
                        </span>
                      )}
                    </p>
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                    {formatDate(report.incident_date)}
                    {report.incident_time && ` · ${report.incident_time}`}
                  </TableCell>
                  <TableCell>
                    <ReportStatus status={report.status} label={report.status_label} />
                  </TableCell>
                  <TableCell className="text-right">
                    <Link to={`/coordinator/incidents/${report.id}`} className="text-muted-foreground group-hover:text-brand">
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
