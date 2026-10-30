import * as React from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, History, ScrollText, X } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Select } from '@/components/ui/select'
import { Input, NumberInput } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { Pagination } from '@/components/Pagination'
import { formatDateTime, humanise } from '@/lib/utils'

const ACTION_TONES = {
  login: 'muted',
  logout: 'muted',
  create: 'success',
  update: 'info',
  delete: 'destructive',
  deployment: 'success',
  violation: 'warning',
  resignation: 'warning',
  termination: 'destructive',
  evaluate: 'info',
  view: 'muted',
  // A placement answer corrected by the office (R-07).
  correction: 'warning',
}

/** Where a record type lives in the app, for the ones that have a page. */
const RECORD_PAGES = {
  Applicant: (id) => `/applicants/${id}`,
  Employee: (id) => `/employees/${id}`,
  JobRequest: (id) => `/job-requests/${id}`,
  Training: (id) => `/trainings/${id}`,
  ClientCompany: (id) => `/clients/${id}`,
}

const EMPTY_FILTERS = {
  action_type: '',
  module_key: '',
  actor_role: '',
  actor_user_id: '',
  record_type: '',
  record_id: '',
  date_from: '',
  date_to: '',
}

const VISIBLE_CHANGES = 4

/**
 * The audit trail. Administrator-only by design: the person whose actions are
 * recorded should not also control the record. Read-only: there is nothing
 * here, or anywhere in the API, that edits or deletes an entry.
 *
 * Each entry says who did what to which record and, field by field, what the
 * entry itself holds (audit R-28). "Before → after" appears only when both
 * were recorded; a value recorded on its own is shown as recorded, never as a
 * change, and an entry with no values is shown as activity only.
 */
export default function AuditLogs() {
  const [filters, setFilters] = React.useState(EMPTY_FILTERS)
  const [page, setPage] = React.useState(1)

  const { data: facets } = useApi('/audit-logs/facets', undefined, { cacheKey: 'audit-facets', staleTime: 60_000 })
  const { data, meta, loading, error, refetch } = useApi('/audit-logs', {
    ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)),
    page,
    per_page: 50,
  })

  const filtered = Object.values(filters).some(Boolean)

  function update(key, value) {
    setFilters((current) => ({ ...current, [key]: value }))
    setPage(1)
  }

  function showRecordHistory(record) {
    setFilters({ ...EMPTY_FILTERS, record_type: record.type, record_id: String(record.id) })
    setPage(1)
  }

  return (
    <>
      <PageHeader
        title="Audit trail"
        description="Every consequential action: who performed it, on which record, and what changed."
      />

      <Card className="mb-4">
        <CardContent className="grid gap-3 pt-5 sm:grid-cols-2 lg:grid-cols-4">
          <Select
            value={filters.action_type}
            onChange={(e) => update('action_type', e.target.value)}
            placeholder="All actions"
            aria-label="Filter by action"
          >
            {(facets?.action_types ?? Object.keys(ACTION_TONES)).map((action) => (
              <option key={action} value={action}>
                {humanise(action)}
              </option>
            ))}
          </Select>

          <Select
            value={filters.module_key}
            onChange={(e) => update('module_key', e.target.value)}
            placeholder="All modules"
            aria-label="Filter by module"
          >
            {(facets?.modules ?? []).map((module) => (
              <option key={module} value={module}>
                {humanise(module)}
              </option>
            ))}
          </Select>

          <Select
            value={filters.actor_user_id}
            onChange={(e) => update('actor_user_id', e.target.value)}
            placeholder="Anyone"
            aria-label="Filter by who acted"
          >
            {(facets?.actors ?? []).map((actor) => (
              <option key={actor.id} value={String(actor.id)}>
                {actor.name} ({humanise(actor.user_type)})
              </option>
            ))}
          </Select>

          <Select
            value={filters.actor_role}
            onChange={(e) => update('actor_role', e.target.value)}
            placeholder="All roles"
            aria-label="Filter by role"
          >
            {(facets?.roles ?? []).map((role) => (
              <option key={role} value={role}>
                {humanise(role)}
              </option>
            ))}
          </Select>

          <Select
            value={filters.record_type}
            onChange={(e) => update('record_type', e.target.value)}
            placeholder="Any kind of record"
            aria-label="Filter by kind of record"
          >
            {(facets?.record_types ?? []).map((type) => (
              <option key={type} value={type}>
                {type.replace(/([a-z])([A-Z])/g, '$1 $2')}
              </option>
            ))}
          </Select>

          <NumberInput
            min="1"
            step="1"
            value={filters.record_id}
            onChange={(e) => update('record_id', e.target.value)}
            placeholder="Record number"
            aria-label="Filter by record number"
          />

          <label className="flex items-center gap-2 text-xs text-muted-foreground">
            From
            <Input
              type="date"
              value={filters.date_from}
              onChange={(e) => update('date_from', e.target.value)}
              aria-label="From date"
            />
          </label>

          <label className="flex items-center gap-2 text-xs text-muted-foreground">
            To
            <Input
              type="date"
              value={filters.date_to}
              min={filters.date_from || undefined}
              onChange={(e) => update('date_to', e.target.value)}
              aria-label="To date"
            />
          </label>
        </CardContent>
        {filtered && (
          <div className="flex items-center justify-between border-t px-5 py-2 text-xs text-muted-foreground">
            <span>{meta?.total !== undefined ? `${meta.total} matching entries` : 'Filtered'}</span>
            <Button variant="ghost" size="sm" className="h-7 text-xs" onClick={() => setFilters(EMPTY_FILTERS)}>
              <X className="h-3 w-3" />
              Clear filters
            </Button>
          </div>
        )}
      </Card>

      <Card>
        {loading ? (
          <SkeletonRows rows={10} columns={4} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={ScrollText}
            title={filtered ? 'No audit entries match these filters' : 'Nothing has been recorded yet'}
            description={filtered ? 'Widen the dates or clear a filter.' : undefined}
          />
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>When</TableHead>
                  <TableHead>Who</TableHead>
                  <TableHead>What</TableHead>
                  <TableHead>Details</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((entry) => (
                  <TableRow key={entry.id}>
                    <TableCell className="whitespace-nowrap align-top text-xs text-muted-foreground">
                      {formatDateTime(entry.created_at)}
                    </TableCell>
                    <TableCell className="align-top text-sm">
                      {entry.actor?.name ?? 'System'}
                      {/* The capacity they acted in, and the client, as recorded at the time. */}
                      {(entry.actor_role || entry.company_name) && (
                        <p className="text-xs text-muted-foreground">
                          {entry.actor_role && humanise(entry.actor_role)}
                          {entry.actor_role && entry.company_name && ' · '}
                          {entry.company_name}
                        </p>
                      )}
                      {entry.ip_address && <p className="text-xs text-muted-foreground">{entry.ip_address}</p>}
                    </TableCell>
                    <TableCell className="align-top">
                      <div className="flex flex-wrap items-center gap-1.5">
                        <Badge tone={ACTION_TONES[entry.action_type] ?? 'muted'}>{humanise(entry.action_type)}</Badge>
                        <span className="text-sm">{humanise(entry.module_key)}</span>
                      </div>
                      {entry.record && <RecordReference record={entry.record} onHistory={showRecordHistory} />}
                    </TableCell>
                    <TableCell className="max-w-md align-top">
                      <ChangeList entry={entry} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            <div className="px-4 pb-3">
              <Pagination meta={meta} onPage={setPage} />
            </div>
          </>
        )}
      </Card>
    </>
  )
}

function RecordReference({ record, onHistory }) {
  const kind = record.type.replace(/([a-z])([A-Z])/g, '$1 $2')
  const page = RECORD_PAGES[record.type]?.(record.id)

  return (
    <div className="mt-1 text-xs">
      <p className="text-muted-foreground">
        {kind} #{record.id}
      </p>
      {record.label &&
        (page ? (
          <Link to={page} className="font-medium hover:text-brand">
            {record.label}
          </Link>
        ) : (
          <p className="font-medium">{record.label}</p>
        ))}
      <button
        type="button"
        onClick={() => onHistory(record)}
        className="mt-0.5 inline-flex items-center gap-1 text-brand hover:underline"
      >
        <History className="h-3 w-3" aria-hidden="true" />
        Every entry for this record
      </button>
    </div>
  )
}

function display(value) {
  if (value === null || value === undefined || value === '') return <span className="italic text-muted-foreground">empty</span>
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  return String(value)
}

/** The entry's fields, labelled by what the entry actually holds. */
export function ChangeList({ entry }) {
  const [expanded, setExpanded] = React.useState(false)

  if (entry.detail_level === 'activity') {
    return <p className="text-xs text-muted-foreground">Activity only — no field values were recorded for this action.</p>
  }

  const rows = expanded ? entry.changes : entry.changes.slice(0, VISIBLE_CHANGES)
  const hidden = entry.changes.length - rows.length

  return (
    <div className="space-y-1">
      <ul className="space-y-1">
        {rows.map((change) => (
          <li key={change.field} className="text-xs">
            <span className="font-medium">{change.label}: </span>
            {change.kind === 'changed' ? (
              <span>
                <span className="text-muted-foreground line-through decoration-muted-foreground/40">{display(change.before)}</span>
                <ArrowRight className="mx-1 inline h-3 w-3 text-muted-foreground" aria-label="changed to" />
                <span>{display(change.after)}</span>
              </span>
            ) : change.kind === 'recorded' ? (
              <span>
                {display(change.after)}{' '}
                <span className="text-muted-foreground" title="Only the value written was recorded, not what it replaced.">
                  (recorded)
                </span>
              </span>
            ) : (
              <span>
                {display(change.before)} <span className="text-muted-foreground">(previous value)</span>
              </span>
            )}
          </li>
        ))}
      </ul>
      {hidden > 0 && (
        <button type="button" className="text-xs text-brand hover:underline" onClick={() => setExpanded(true)}>
          Show {hidden} more
        </button>
      )}
    </div>
  )
}
