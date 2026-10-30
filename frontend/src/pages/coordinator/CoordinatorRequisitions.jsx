import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { FilePlus2, Plus, ChevronRight } from 'lucide-react'
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
 * The coordinator's manpower requisitions: the client asking CDE for people.
 * HR reviews each one; an approved requisition becomes a manpower request.
 */
export default function CoordinatorRequisitions() {
  const [searchParams, setSearchParams] = useSearchParams()
  const status = searchParams.get('status') ?? ''
  const mine = searchParams.get('mine') === '1'
  const page = Number(searchParams.get('page') ?? 1)

  const options = useApi('/coordinator/requisitions/options', undefined, { cacheKey: 'coordinator-requisition-options' })
  const { data, meta, loading, error, refetch } = useApi('/coordinator/requisitions', {
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

  return (
    <div className="space-y-4">
      <PageHeader
        title="Manpower Requisitions"
        description="When your client needs people, file a requisition for CDE's HR. Submitting does not approve it; HR reviews every one."
        actions={
          <Button asChild>
            <Link to="/coordinator/requisitions/new">
              <Plus className="h-4 w-4" />
              New requisition
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
          title="No requisitions"
          description="When your client asks for more people, write it up here."
          action={
            <Button size="sm" asChild>
              <Link to="/coordinator/requisitions/new">New requisition</Link>
            </Button>
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Requisition</TableHead>
                <TableHead>Position</TableHead>
                <TableHead>Needed by</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-8" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((r) => (
                <TableRow key={r.id} className="group">
                  <TableCell>
                    <Link to={`/coordinator/requisitions/${r.id}`} className="block">
                      <p className="font-mono text-sm font-semibold group-hover:text-brand">{r.requisition_code}</p>
                      <p className="text-xs text-muted-foreground">
                        {r.requested_by?.name} · {formatDateTime(r.submitted_at ?? r.created_at)}
                      </p>
                    </Link>
                  </TableCell>
                  <TableCell>
                    <p className="text-sm font-medium">
                      {r.workers_needed} × {r.position_title}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {r.request_reason_label ?? 'Temporary form'}
                      {r.department_name && ` · ${r.department_name}`}
                    </p>
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">{formatDate(r.required_by)}</TableCell>
                  <TableCell>
                    <ReportStatus status={r.status} label={r.status_label} />
                  </TableCell>
                  <TableCell className="text-right">
                    <Link to={`/coordinator/requisitions/${r.id}`} className="text-muted-foreground group-hover:text-brand">
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
