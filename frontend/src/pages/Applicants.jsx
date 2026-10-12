import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Plus, Users, UserCheck, ChevronRight, X } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { formatDate } from '@/lib/utils'
import NewApplicantDialog from '@/components/dialogs/NewApplicantDialog'

const LIFECYCLE_STATUSES = [
  'applied',
  'initial_screening',
  'incomplete_requirements',
  'primary_requirements_complete',
  'pending_final_requirements',
  'ready_for_deployment',
  'training_scheduled',
  'training_completed',
  'client_evaluation',
  'approved',
  'deployed',
  'active',
  'resigned',
  'terminated',
  'archived',
]

export default function Applicants() {
  const { can } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const [dialogOpen, setDialogOpen] = React.useState(false)

  const search = searchParams.get('search') ?? ''
  const status = searchParams.get('current_status') ?? ''
  const folder = searchParams.get('folder_category') ?? ''
  const awaiting = searchParams.get('awaiting_identity_check') === '1'
  // Set when arriving from a client's position: "who applied for this role".
  const position = searchParams.get('preferred_position_id') ?? ''
  const page = Number(searchParams.get('page') ?? 1)

  const debouncedSearch = useDebounced(search)

  const { data, meta, loading, error, refetch } = useApi('/applicants', {
    search: debouncedSearch || undefined,
    current_status: status || undefined,
    folder_category: folder || undefined,
    awaiting_identity_check: awaiting ? 1 : undefined,
    preferred_position_id: position || undefined,
    page,
    per_page: 20,
  })

  const filtered = Boolean(search || status || folder || awaiting || position)
  const [selectedIds, setSelectedIds] = React.useState([])
  const allSelected = data?.length > 0 && selectedIds.length === data.length

  function toggleSelectAll() {
    if (allSelected) {
      setSelectedIds([])
    } else {
      setSelectedIds(data.map((a) => a.id))
    }
  }

  function toggleSelect(id) {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
    )
  }

  function updateFilter(key, value) {
    const next = new URLSearchParams(searchParams)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setSearchParams(next)
  }

  function clearFilters() {
    setSearchParams(new URLSearchParams())
  }

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
        searchPlaceholder="Search by name, applicant code, or contact…"
        filters={[
          {
            id: 'current_status',
            label: 'All Statuses',
            value: status,
            onChange: (val) => updateFilter('current_status', val),
            options: [
              { value: '', label: 'All Statuses' },
              ...LIFECYCLE_STATUSES.map((val) => ({
                value: val,
                label: val.split('_').map((w) => w[0].toUpperCase() + w.slice(1)).join(' '),
              })),
            ],
          },
          {
            id: 'folder_category',
            label: 'All Folders',
            value: folder,
            onChange: (val) => updateFilter('folder_category', val),
            options: [
              { value: '', label: 'All Folders' },
              { value: 'folder_1', label: 'Folder 1 — Deployable' },
              { value: 'folder_2', label: 'Folder 2 — Primary Complete' },
              { value: 'folder_3', label: 'Folder 3 — Resume Only' },
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
        ]}
        resultCount={meta?.total}
        resultLabel="applicants"
        onClearFilters={clearFilters}
      />

      {position && (
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <span>
            Showing applicants who applied for{' '}
            <span className="font-medium text-foreground">
              {data?.[0]?.preferred_position ?? 'the selected position'}
            </span>
          </span>
          <Button
            variant="ghost"
            size="sm"
            className="h-6 px-2 text-xs"
            onClick={() => updateFilter('preferred_position_id', '')}
          >
            <X className="h-3 w-3" />
            Show everyone
          </Button>
        </div>
      )}

      {loading ? (
        <Table>
          <SkeletonRows rows={8} columns={6} />
        </Table>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={awaiting ? UserCheck : Users}
          title={
            awaiting
              ? 'Nobody is waiting for an identity check'
              : filtered
                ? 'No applicants match these filters'
                : 'No applicants recorded yet'
          }
          description={
            awaiting
              ? 'Everyone who registered online has already been verified at the office.'
              : filtered
                ? 'Try clearing a filter or searching for a different keyword.'
                : 'Register an applicant when they visit the office to submit their documents.'
          }
          action={
            can('applicants.create') && !filtered ? (
              <Button size="sm" onClick={() => setDialogOpen(true)}>
                <Plus className="h-4 w-4" />
                Register Applicant
              </Button>
            ) : null
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-10">
                  <input
                    type="checkbox"
                    checked={allSelected}
                    onChange={toggleSelectAll}
                    className="h-4 w-4 rounded-control border-border text-brand focus:ring-brand"
                    aria-label="Select all applicants"
                  />
                </TableHead>
                <TableHead>Applicant & Code</TableHead>
                <TableHead>Position Sought</TableHead>
                <TableHead>Current Status</TableHead>
                <TableHead>Folder Category</TableHead>
                <TableHead>Application Date</TableHead>
                <TableHead className="w-8"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((applicant) => (
                <TableRow
                  key={applicant.id}
                  className={`group cursor-pointer ${selectedIds.includes(applicant.id) ? 'bg-muted/60' : ''}`}
                >
                  <TableCell onClick={(e) => e.stopPropagation()}>
                    <input
                      type="checkbox"
                      checked={selectedIds.includes(applicant.id)}
                      onChange={() => toggleSelect(applicant.id)}
                      className="h-4 w-4 rounded-control border-border text-brand focus:ring-brand"
                      aria-label={`Select ${applicant.full_name}`}
                    />
                  </TableCell>

                  {/* Identity Block: Full Name + Applicant Code */}
                  <TableCell>
                    <Link to={`/applicants/${applicant.id}`} className="block">
                      <div className="flex items-center gap-1.5">
                        <p className="font-semibold text-foreground group-hover:text-brand transition-colors">
                          {applicant.full_name}
                        </p>
                        {applicant.awaiting_identity_check && (
                          <span className="text-[10px] font-medium bg-status-warning-bg text-status-warning-text px-1.5 py-0.5 rounded-tag">
                            Unverified
                          </span>
                        )}
                      </div>
                      <p className="font-mono text-xs text-muted-foreground tabular-nums">
                        {applicant.applicant_code || `APP-${applicant.id}`}
                      </p>
                    </Link>
                  </TableCell>

                  {/* Position Sought */}
                  <TableCell className="text-sm font-medium text-foreground">
                    {applicant.preferred_position || 'General Application'}
                  </TableCell>

                  {/* Status Badge */}
                  <TableCell>
                    <StatusBadge status={applicant.current_status} />
                  </TableCell>

                  {/* Folder Category Tag */}
                  <TableCell>
                    <StatusBadge
                      status={applicant.folder_category}
                      label={applicant.folder_category?.replace('folder_', 'Folder ')}
                    />
                  </TableCell>

                  {/* Application Date */}
                  <TableCell className="whitespace-nowrap font-mono text-xs text-muted-foreground tabular-nums">
                    {formatDate(applicant.application_date)}
                  </TableCell>

                  {/* Action Link */}
                  <TableCell className="text-right">
                    <Link to={`/applicants/${applicant.id}`} className="text-muted-foreground group-hover:text-brand">
                      <ChevronRight className="h-4 w-4" />
                    </Link>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          <Pagination meta={meta} onPage={(p) => updateFilter('page', String(p))} />
        </div>
      )}

      {/* Floating Bulk Action Bar */}
      {selectedIds.length > 0 && (
        <div className="fixed bottom-6 right-6 z-40 flex items-center gap-3 rounded-panel border border-border bg-card p-3 shadow-md">
          <span className="text-xs font-semibold text-foreground font-mono tabular-nums">
            {selectedIds.length} applicant{selectedIds.length > 1 ? 's' : ''} selected
          </span>
          <div className="h-4 w-px bg-border" />
          <Button variant="ghost" size="sm" onClick={() => setSelectedIds([])} className="h-7 text-xs">
            Deselect
          </Button>
        </div>
      )}

      <NewApplicantDialog open={dialogOpen} onOpenChange={setDialogOpen} onCreated={refetch} />
    </div>
  )
}

export function Pagination({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null

  return (
    <div className="flex items-center justify-between pt-2 text-xs text-muted-foreground font-mono tabular-nums">
      <div>
        Showing page <span className="font-semibold text-foreground">{meta.current_page}</span> of{' '}
        <span className="font-semibold text-foreground">{meta.last_page}</span> ({meta.total} total)
      </div>
      <div className="flex items-center gap-1">
        <Button
          variant="outline"
          size="sm"
          disabled={meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
          className="h-8 text-xs font-sans"
        >
          Previous
        </Button>
        <Button
          variant="outline"
          size="sm"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
          className="h-8 text-xs font-sans"
        >
          Next
        </Button>
      </div>
    </div>
  )
}
