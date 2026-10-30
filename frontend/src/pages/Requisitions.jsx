import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { FilePlus2, ChevronRight } from 'lucide-react'
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
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'fulfilled', label: 'Fulfilled' },
  { value: 'all', label: 'All requisitions' },
]

/**
 * Coordinators' manpower requisitions, for HR to review.
 *
 * Opens on what is waiting. A coordinator's draft never appears here.
 */
export default function Requisitions() {
  const [searchParams, setSearchParams] = useSearchParams()
  const status = searchParams.get('status') ?? 'pending'
  const company = searchParams.get('client_company_id') ?? ''
  const search = searchParams.get('search') ?? ''
  const page = Number(searchParams.get('page') ?? 1)
  const debounced = useDebounced(search)

  const companies = useApi('/clients', { per_page: 100 }, { cacheKey: 'requisition-companies' })
  const { data, meta, loading, error, refetch } = useApi('/requisitions', {
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
        title="Manpower Requisitions"
        description="Requests for people that coordinators filed from client sites on CDE's Manpower Requisition Form. Approve one, then record it as a manpower request to start recruiting."
      />

      <FilterToolbar
        search={search}
        onSearchChange={(value) => update('search', value)}
        searchPlaceholder="Search by requisition code or position…"
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
        resultLabel="requisitions"
      />

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={5} columns={5} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={FilePlus2}
          title={
            search || company
              ? 'No requisitions match these filters'
              : status === 'pending'
                ? 'Nothing waiting for review'
                : 'No requisitions'
          }
          description={
            search || company
              ? 'Try another client or status, or search for part of the position or requisition code.'
              : 'Requisitions appear here when a coordinator submits them.'
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Requisition</TableHead>
                <TableHead>Position</TableHead>
                <TableHead>Client · department</TableHead>
                <TableHead>Needed by</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-8" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((r) => (
                <TableRow key={r.id} className="group">
                  <TableCell>
                    <Link to={`/requisitions/${r.id}`} className="block">
                      <p className="font-mono text-sm font-semibold group-hover:text-brand">{r.requisition_code}</p>
                      <p className="text-xs text-muted-foreground">
                        {r.requested_by?.name} · {formatDateTime(r.submitted_at)}
                      </p>
                    </Link>
                  </TableCell>
                  <TableCell className="text-sm font-medium">
                    {r.workers_needed} × {r.position_title}
                    <p className="text-xs font-normal text-muted-foreground">
                      {r.request_reason_label ?? 'Temporary form'}
                    </p>
                    {r.job_request && <p className="font-mono text-xs text-muted-foreground">{r.job_request.request_code}</p>}
                  </TableCell>
                  <TableCell className="text-sm">
                    {r.company_name}
                    <p className="text-xs text-muted-foreground">{r.department_name ?? '—'}</p>
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">{formatDate(r.required_by)}</TableCell>
                  <TableCell>
                    <ReportStatus status={r.status} label={r.status_label} />
                  </TableCell>
                  <TableCell className="text-right">
                    <Link to={`/requisitions/${r.id}`} className="text-muted-foreground group-hover:text-brand">
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
