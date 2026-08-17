import * as React from 'react'
import { Archive as ArchiveIcon, Search, ChevronDown, ChevronRight } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { formatDate, formatDateTime, humanise } from '@/lib/utils'
import { Pagination } from './Applicants'

/**
 * Archived records stay searchable. Losing the ability to answer "did this
 * person work for us, and how did it end" is exactly what happened once a paper
 * folder was refiled, so the snapshot is kept readable rather than compressed
 * into an ID reference.
 */
export default function Archives() {
  const [search, setSearch] = React.useState('')
  const [entityType, setEntityType] = React.useState('')
  const [page, setPage] = React.useState(1)
  const [expanded, setExpanded] = React.useState(null)

  const debouncedSearch = useDebounced(search)

  const { data, meta, loading, error, refetch } = useApi('/archives', {
    search: debouncedSearch || undefined,
    entity_type: entityType || undefined,
    page,
    per_page: 20,
  })

  return (
    <>
      <PageHeader
        title="Archives"
        description="Closed records, kept in full and searchable."
      />

      <Card className="mb-4">
        <CardContent className="flex flex-col gap-3 pt-5 sm:flex-row">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
              placeholder="Search archived records by name or reason"
              className="pl-9"
              aria-label="Search archives"
            />
          </div>

          <Select
            value={entityType}
            onChange={(e) => {
              setEntityType(e.target.value)
              setPage(1)
            }}
            placeholder="All record types"
            aria-label="Filter by record type"
            className="sm:w-52"
          >
            <option value="employee">Employees</option>
            <option value="applicant">Applicants</option>
            <option value="deployment">Deployments</option>
          </Select>
        </CardContent>
      </Card>

      <Card>
        {loading ? (
          <SkeletonRows rows={5} columns={4} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={ArchiveIcon}
            title={search || entityType ? 'No archived records match' : 'Nothing archived yet'}
            description="Records are archived automatically when a resignation completes or a termination is finalised."
          />
        ) : (
          <>
            <ul className="divide-y">
              {data.map((archive) => {
                const person = archive.snapshot_json?.person
                const employee = archive.snapshot_json?.employee
                const name = person
                  ? [person.first_name, person.middle_name, person.last_name].filter(Boolean).join(' ')
                  : `Record #${archive.entity_id}`

                return (
                  <li key={archive.id} className="p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="font-medium">{name}</p>
                          <Badge tone="muted">{humanise(archive.entity_type)}</Badge>
                        </div>
                        <p className="mt-0.5 text-sm text-muted-foreground">{archive.archive_reason}</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                          Archived {formatDateTime(archive.archived_at)}
                          {employee?.employee_number && ` · ${employee.employee_number}`}
                        </p>
                      </div>

                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setExpanded(expanded === archive.id ? null : archive.id)}
                        aria-expanded={expanded === archive.id}
                      >
                        {expanded === archive.id ? (
                          <ChevronDown className="h-4 w-4" />
                        ) : (
                          <ChevronRight className="h-4 w-4" />
                        )}
                        Full record
                      </Button>
                    </div>

                    {expanded === archive.id && <ArchiveSnapshot snapshot={archive.snapshot_json} />}
                  </li>
                )
              })}
            </ul>

            <Pagination meta={meta} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}

function ArchiveSnapshot({ snapshot }) {
  if (!snapshot) return null

  return (
    <div className="mt-3 space-y-4 rounded-md border bg-muted/30 p-4 text-sm">
      {snapshot.deployments?.length > 0 && (
        <section>
          <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Deployment history
          </p>
          <ul className="space-y-1">
            {snapshot.deployments.map((deployment, index) => (
              <li key={index} className="text-muted-foreground">
                <span className="text-foreground">{deployment.position}</span> at {deployment.company}
                {deployment.department && ` · ${deployment.department}`}
                {deployment.from && ` · ${formatDate(deployment.from)}`}
                {deployment.to && ` to ${formatDate(deployment.to)}`}
              </li>
            ))}
          </ul>
        </section>
      )}

      {snapshot.violations?.length > 0 && (
        <section>
          <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Disciplinary record
          </p>
          <ul className="space-y-1">
            {snapshot.violations.map((violation, index) => (
              <li key={index} className="text-muted-foreground">
                <span className="text-foreground">{humanise(violation.type)}</span> ·{' '}
                {formatDate(violation.date)} — {violation.description}
                {violation.penalty && ` (${violation.penalty})`}
              </li>
            ))}
          </ul>
        </section>
      )}

      {snapshot.status_history?.length > 0 && (
        <section>
          <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Employment history
          </p>
          <ul className="space-y-1">
            {snapshot.status_history.map((entry, index) => (
              <li key={index} className="text-muted-foreground">
                {entry.from ? `${humanise(entry.from)} → ` : ''}
                {humanise(entry.to)} · {formatDateTime(entry.at)}
                {entry.reason && ` — ${entry.reason}`}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}
