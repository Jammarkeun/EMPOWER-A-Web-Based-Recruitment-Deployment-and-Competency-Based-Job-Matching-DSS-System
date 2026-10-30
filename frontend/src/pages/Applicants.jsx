import * as React from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Plus, Users, UserCheck, ChevronRight, X, AlertTriangle, Briefcase } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { useApplicantStatuses } from '@/hooks/useApplicantStatuses'
import { useAuth } from '@/contexts/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import PersonAvatar from '@/components/PersonAvatar'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { Pagination, useClampPage } from '@/components/Pagination'
import { formatDate } from '@/lib/utils'
import NewApplicantDialog from '@/components/dialogs/NewApplicantDialog'

/** Filters kept in the address bar, so a filtered list can be bookmarked or shared. */
const FILTER_KEYS = [
  'search',
  'current_status',
  'folder_category',
  'client_company_id',
  'preferred_position_id',
  'awaiting_identity_check',
  'documents_lapsed',
  'exclude_deployed',
]

/**
 * The applicant list.
 *
 * Every filter is applied by the server, together with the search and the
 * paging, so a filtered page never shows somebody the filters exclude. The
 * checkboxes and the "bulk action" bar that used to sit here were removed
 * (audit FN-01, decided 2026-10-19): no CDE procedure acts on applicants in
 * batches, and the only button the bar ever had was Deselect.
 */
export default function Applicants() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const [dialogOpen, setDialogOpen] = React.useState(false)

  const value = (key) => searchParams.get(key) ?? ''
  const search = value('search')
  const status = value('current_status')
  // Several statuses, comma-separated, when a dashboard figure opens the list.
  const statusValues = status.split(',').filter(Boolean)
  const folder = value('folder_category')
  const client = value('client_company_id')
  const position = value('preferred_position_id')
  const awaiting = value('awaiting_identity_check') === '1'
  const lapsed = value('documents_lapsed') === '1'
  // Leaves out anyone working under an active deployment; the dashboard's
  // "ready for deployment" figure opens the list with it on.
  const notDeployed = value('exclude_deployed') === '1'
  const page = Number(searchParams.get('page') ?? 1)

  const debouncedSearch = useDebounced(search)
  const { statuses } = useApplicantStatuses()
  const { data: clients } = useApi('/clients', { status: 'active', per_page: 100 }, { cacheKey: 'clients-active' })
  const { data: positionData } = useApi('/positions', { client_company_id: client || undefined })

  const { data, meta, loading, error, refetch } = useApi('/applicants', {
    search: debouncedSearch || undefined,
    current_status: status || undefined,
    folder_category: folder || undefined,
    client_company_id: client || undefined,
    preferred_position_id: position || undefined,
    awaiting_identity_check: awaiting ? 1 : undefined,
    documents_lapsed: lapsed ? 1 : undefined,
    exclude_deployed: notDeployed ? 1 : undefined,
    page,
    per_page: 20,
  })

  const filtered = FILTER_KEYS.some((key) => searchParams.get(key))
  const statusLabel = (key) => statuses.find((s) => s.value === key)?.label ?? key

  useClampPage(meta, (p) => updateFilter('page', String(p)))

  function updateFilter(key, next) {
    const params = new URLSearchParams(searchParams)
    if (next) params.set(key, next)
    else params.delete(key)
    if (key !== 'page') params.delete('page')
    // A position belongs to one client; choosing another client clears it.
    if (key === 'client_company_id') params.delete('preferred_position_id')
    setSearchParams(params)
  }

  function clearFilters() {
    setSearchParams(new URLSearchParams())
  }

  // The whole row opens the record for a pointer; the name stays a real link
  // for the keyboard and for opening in a new tab.
  function openRow(event, applicant) {
    if (event.target.closest('a, button')) return
    navigate(`/applicants/${applicant.id}`)
  }

  const positionOptions = positionData?.positions ?? []

  return (
    <div className="space-y-4">
      <PageHeader
        title="Applicants"
        description="Recruitment pipeline, applicant records, and document completeness."
        actions={
          can('applicants.create') && (
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Register Applicant
            </Button>
          )
        }
      />

      <FilterToolbar
        search={search}
        onSearchChange={(val) => updateFilter('search', val)}
        searchPlaceholder="Search by name, code, position, contact or email…"
        filters={[
          {
            id: 'current_status',
            label: 'All statuses',
            value: status,
            onChange: (val) => updateFilter('current_status', val),
            options: [
              { value: '', label: 'All statuses' },
              ...(statusValues.length > 1 ? [{ value: status, label: `${statusValues.length} statuses` }] : []),
              ...statuses.map((s) => ({ value: s.value, label: s.label })),
            ],
          },
          {
            id: 'folder_category',
            label: 'All folders',
            value: folder,
            onChange: (val) => updateFilter('folder_category', val),
            options: [
              { value: '', label: 'All folders' },
              { value: 'folder_1', label: 'Folder 1 — Deployable' },
              { value: 'folder_2', label: 'Folder 2 — Primary complete' },
              { value: 'folder_3', label: 'Folder 3 — Resume only' },
            ],
          },
          {
            id: 'client_company_id',
            label: 'Applied to any client',
            value: client,
            onChange: (val) => updateFilter('client_company_id', val),
            options: [
              // Whose positions the applicant applied for, not where anyone works.
              { value: '', label: 'Applied to any client' },
              ...(clients ?? []).map((c) => ({ value: String(c.id), label: `Applied to ${c.company_name}` })),
            ],
          },
          {
            id: 'preferred_position_id',
            label: 'All positions',
            value: position,
            onChange: (val) => updateFilter('preferred_position_id', val),
            options: [
              { value: '', label: 'All positions' },
              ...positionOptions.map((p) => ({
                value: String(p.id),
                label: client || !p.company ? p.position_title : `${p.position_title} — ${p.company}`,
              })),
            ],
          },
        ]}
        viewToggles={[
          {
            id: 'awaiting_identity_check',
            label: 'Awaiting ID check',
            active: awaiting,
            onToggle: () => updateFilter('awaiting_identity_check', awaiting ? '' : '1'),
          },
          {
            id: 'documents_lapsed',
            label: 'Documents lapsed',
            active: lapsed,
            onToggle: () => updateFilter('documents_lapsed', lapsed ? '' : '1'),
          },
          {
            id: 'exclude_deployed',
            label: 'Not deployed',
            active: notDeployed,
            onToggle: () => updateFilter('exclude_deployed', notDeployed ? '' : '1'),
          },
        ]}
        resultCount={meta?.total}
        resultLabel="applicants"
        onClearFilters={clearFilters}
      />

      {statusValues.length > 1 && (
        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          <span>
            Showing applicants in{' '}
            <span className="font-medium text-foreground">{statusValues.map(statusLabel).join(', ')}</span>
          </span>
          <Button
            variant="ghost"
            size="sm"
            className="h-6 px-2 text-xs"
            onClick={() => updateFilter('current_status', '')}
          >
            <X className="h-3 w-3" />
            Show every status
          </Button>
        </div>
      )}

      {position && (
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <span>
            Showing applicants who applied for{' '}
            <span className="font-medium text-foreground">
              {positionOptions.find((p) => String(p.id) === position)?.position_title ??
                data?.[0]?.preferred_position ??
                'the selected position'}
            </span>
          </span>
          <Button
            variant="ghost"
            size="sm"
            className="h-6 px-2 text-xs"
            onClick={() => updateFilter('preferred_position_id', '')}
          >
            <X className="h-3 w-3" />
            Show every position
          </Button>
        </div>
      )}

      {loading ? (
        // A loading placeholder, not table rows, so it sits in the table's
        // panel rather than inside a <table>.
        <div className="w-full rounded-panel bg-card p-3 sm:p-4">
          <SkeletonRows rows={8} columns={6} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={awaiting ? UserCheck : Users}
          title={
            awaiting && !search
              ? 'Nobody is waiting for an identity check'
              : filtered
                ? 'No applicants match these filters'
                : 'No applicants recorded yet'
          }
          description={
            awaiting && !search
              ? 'Everyone who registered online has already been verified at the office.'
              : filtered
                ? 'Try clearing a filter, or search for part of the name — every word you type must match.'
                : 'Register an applicant when they visit the office to submit their documents.'
          }
          action={
            can('applicants.create') && !filtered ? (
              <Button size="sm" onClick={() => setDialogOpen(true)}>
                <Plus className="h-4 w-4" />
                Register Applicant
              </Button>
            ) : filtered ? (
              <Button size="sm" variant="outline" onClick={clearFilters}>
                Clear filters
              </Button>
            ) : null
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Applicant & Code</TableHead>
                <TableHead>Position Sought</TableHead>
                <TableHead>Current Status</TableHead>
                <TableHead>Folder Category</TableHead>
                <TableHead>Application Date</TableHead>
                <TableHead className="w-8">
                  <span className="sr-only">Open</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((applicant) => (
                <TableRow
                  key={applicant.id}
                  className="group cursor-pointer"
                  onClick={(event) => openRow(event, applicant)}
                >
                  {/* Identity: full name and applicant code */}
                  <TableCell>
                    <Link to={`/applicants/${applicant.id}`} className="flex items-center gap-3">
                      <PersonAvatar src={applicant.photo_url} name={applicant.full_name} size="sm" />
                      <div className="min-w-0">
                        <div className="flex items-center gap-1.5">
                          <p className="font-semibold text-foreground transition-colors group-hover:text-brand">
                            {applicant.full_name}
                          </p>
                          {applicant.awaiting_identity_check && (
                            <span className="rounded-tag bg-status-warning-bg px-1.5 py-0.5 text-[10px] font-medium text-status-warning-text">
                              Unverified
                            </span>
                          )}
                        </div>
                        {/* Never an invented identifier: a record without a code says so. */}
                        <p className="font-mono text-xs tabular-nums text-muted-foreground">
                          {applicant.applicant_code || 'No code'}
                        </p>
                      </div>
                    </Link>
                  </TableCell>

                  <TableCell className="text-sm font-medium text-foreground">
                    {applicant.preferred_position || 'General Application'}
                  </TableCell>

                  <TableCell>
                    <div className="flex flex-col items-start gap-1">
                      <StatusBadge status={applicant.current_status} label={applicant.status_label} />
                      <PlacementNote placement={applicant.placement} />
                      {applicant.documents_lapsed && (
                        <Badge tone="warning" title="The status was reached with every document verified; the folder now says one has lapsed or been rejected.">
                          <AlertTriangle className="h-3 w-3" aria-hidden="true" />
                          Documents lapsed
                        </Badge>
                      )}
                    </div>
                  </TableCell>

                  <TableCell>
                    <StatusBadge
                      status={applicant.folder_category}
                      label={applicant.folder_category?.replace('folder_', 'Folder ')}
                    />
                  </TableCell>

                  <TableCell className="whitespace-nowrap font-mono text-xs tabular-nums text-muted-foreground">
                    {formatDate(applicant.application_date)}
                  </TableCell>

                  <TableCell className="text-right">
                    <ChevronRight className="h-4 w-4 text-muted-foreground group-hover:text-brand" aria-hidden="true" />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          <Pagination meta={meta} onPage={(p) => updateFilter('page', String(p))} />
        </div>
      )}

      <NewApplicantDialog open={dialogOpen} onOpenChange={setDialogOpen} onCreated={refetch} />
    </div>
  )
}

/**
 * Where somebody works today, from the employment record - the same rule as
 * the Deployed filter - and any disagreement between that record and the
 * status. Shown, never corrected: the status and the employment record are
 * changed only by their own workflows.
 */
function PlacementNote({ placement }) {
  if (!placement) return null

  return (
    <>
      {placement.deployed && (
        <Badge tone="info" title="An active employee with an active deployment.">
          <Briefcase className="h-3 w-3" aria-hidden="true" />
          {placement.company ? `Deployed · ${placement.company}` : 'Deployed'}
        </Badge>
      )}
      {placement.mismatch === 'status_not_updated' && (
        <Badge tone="warning" title="The employment record shows an active deployment, but the applicant status was not moved on.">
          <AlertTriangle className="h-3 w-3" aria-hidden="true" />
          Status not updated
        </Badge>
      )}
      {placement.mismatch === 'no_active_deployment' && (
        <Badge tone="warning" title="The status says employed, but no active deployment is on record.">
          <AlertTriangle className="h-3 w-3" aria-hidden="true" />
          No active deployment
        </Badge>
      )}
    </>
  )
}
