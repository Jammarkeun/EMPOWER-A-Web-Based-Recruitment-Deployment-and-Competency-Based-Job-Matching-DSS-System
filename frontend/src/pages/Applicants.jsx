import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Plus, Search, Users, UserCheck } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
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

  // Filters live in the URL so a filtered view can be bookmarked or shared with
  // a colleague, and so the browser back button behaves as expected.
  const search = searchParams.get('search') ?? ''
  const status = searchParams.get('current_status') ?? ''
  const folder = searchParams.get('folder_category') ?? ''
  const awaiting = searchParams.get('awaiting_identity_check') === '1'
  const page = Number(searchParams.get('page') ?? 1)

  const debouncedSearch = useDebounced(search)

  const { data, meta, loading, error, refetch } = useApi('/applicants', {
    search: debouncedSearch || undefined,
    current_status: status || undefined,
    folder_category: folder || undefined,
    awaiting_identity_check: awaiting ? 1 : undefined,
    page,
    per_page: 20,
  })

  const filtered = Boolean(search || status || folder || awaiting)

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
    // Any filter change invalidates the current page number.
    if (key !== 'page') next.delete('page')
    setSearchParams(next)
  }

  return (
    <>
      <PageHeader
        title="Applicants"
        description="Everyone who has applied, from first contact through to deployment."
        actions={
          can('applicants.create') && (
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Register applicant
            </Button>
          )
        }
      />

      <Card className="mb-4">
        <CardContent className="flex flex-col gap-3 pt-5 sm:flex-row">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => updateFilter('search', e.target.value)}
              placeholder="Search by name, applicant code, or contact number"
              className="pl-9"
              aria-label="Search applicants"
            />
          </div>

          <Select
            value={status}
            onChange={(e) => updateFilter('current_status', e.target.value)}
            placeholder="All statuses"
            aria-label="Filter by status"
            className="sm:w-56"
          >
            {LIFECYCLE_STATUSES.map((value) => (
              <option key={value} value={value}>
                {value.split('_').map((w) => w[0].toUpperCase() + w.slice(1)).join(' ')}
              </option>
            ))}
          </Select>

          <Select
            value={folder}
            onChange={(e) => updateFilter('folder_category', e.target.value)}
            placeholder="All folders"
            aria-label="Filter by folder"
            className="sm:w-52"
          >
            <option value="folder_1">Folder 1 — Deployable</option>
            <option value="folder_2">Folder 2 — Primary complete</option>
            <option value="folder_3">Folder 3 — Resume only</option>
          </Select>

          {/*
            A one-click view of who has registered online and not yet been seen
            at the office. This is the queue the counter works from, and the
            notification about a new online registration links straight here.
          */}
          <Button
            variant={awaiting ? 'default' : 'outline'}
            onClick={() => updateFilter('awaiting_identity_check', awaiting ? '' : '1')}
            aria-pressed={awaiting}
            className="shrink-0"
          >
            <UserCheck className="h-4 w-4" />
            Awaiting ID check
          </Button>
        </CardContent>
      </Card>

      <Card>
        {loading ? (
          <SkeletonRows rows={8} columns={6} />
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
                  : 'No applicants yet'
            }
            description={
              awaiting
                ? 'Everyone who registered online has already been seen at the office.'
                : filtered
                  ? 'Try clearing a filter or searching for a different name.'
                  : 'Register an applicant when they visit the office to submit their documents.'
            }
            action={
              can('applicants.create') && !filtered ? (
                <Button size="sm" onClick={() => setDialogOpen(true)}>
                  <Plus className="h-4 w-4" />
                  Register applicant
                </Button>
              ) : null
            }
          />
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-10">
                    <input
                      type="checkbox"
                      checked={allSelected}
                      onChange={toggleSelectAll}
                      className="h-4 w-4 rounded border-input"
                      aria-label="Select all applicants"
                    />
                  </TableHead>
                  <TableHead>Applicant</TableHead>
                  <TableHead>Code</TableHead>
                  <TableHead>Position sought</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Folder</TableHead>
                  <TableHead>Applied</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((applicant) => (
                  <TableRow key={applicant.id} className={selectedIds.includes(applicant.id) ? 'bg-muted/50' : ''}>
                    <TableCell>
                      <input
                        type="checkbox"
                        checked={selectedIds.includes(applicant.id)}
                        onChange={() => toggleSelect(applicant.id)}
                        className="h-4 w-4 rounded border-input"
                        aria-label={`Select ${applicant.full_name}`}
                      />
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-wrap items-center gap-1.5">
                        <Link
                          to={`/applicants/${applicant.id}`}
                          className="font-medium text-primary hover:underline"
                        >
                          {applicant.full_name}
                        </Link>
                        {applicant.awaiting_identity_check && (
                          <Badge tone="warning" className="text-[10px]">
                            Not yet verified
                          </Badge>
                        )}
                      </div>
                      <p className="text-xs text-muted-foreground">
                        {applicant.contact_number || applicant.email || 'No contact details'}
                      </p>
                    </TableCell>
                    <TableCell className="font-mono text-xs text-muted-foreground">
                      {applicant.applicant_code}
                    </TableCell>
                    <TableCell className="text-sm">{applicant.preferred_position || '—'}</TableCell>
                    <TableCell>
                      <StatusBadge status={applicant.current_status} />
                    </TableCell>
                    <TableCell>
                      <StatusBadge
                        status={applicant.folder_category}
                        label={applicant.folder_category?.replace('folder_', 'Folder ')}
                      />
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                      {formatDate(applicant.application_date)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            <Pagination meta={meta} onPage={(p) => updateFilter('page', String(p))} />
          </>
        )}
      </Card>

      {/* Floating Bulk Action Toolbar */}
      {selectedIds.length > 0 && (
        <div className="fixed bottom-6 right-6 z-40 flex items-center gap-3 rounded-xl border bg-card/95 px-4 py-3 shadow-xl backdrop-blur border-primary/30">
          <span className="text-sm font-semibold">
            {selectedIds.length} applicant{selectedIds.length > 1 ? 's' : ''} selected
          </span>
          <div className="h-4 w-px bg-border" />
          <Button variant="ghost" size="sm" onClick={() => setSelectedIds([])}>
            Deselect
          </Button>
        </div>
      )}

      <NewApplicantDialog open={dialogOpen} onOpenChange={setDialogOpen} onCreated={refetch} />
    </>
  )
}

export function Pagination({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null

  return (
    <div className="flex items-center justify-between gap-3 border-t px-4 py-3 no-print">
      <p className="text-xs text-muted-foreground">
        Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
      </p>
      <div className="flex items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          disabled={meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
        >
          Previous
        </Button>
        <span className="text-xs tabular-nums text-muted-foreground">
          Page {meta.current_page} of {meta.last_page}
        </span>
        <Button
          variant="outline"
          size="sm"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
        >
          Next
        </Button>
      </div>
    </div>
  )
}
