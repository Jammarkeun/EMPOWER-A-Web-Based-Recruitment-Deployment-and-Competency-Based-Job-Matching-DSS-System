import * as React from 'react'
import { Link } from 'react-router-dom'
import { ArrowLeft, ChevronRight, ExternalLink, FolderOpen, Loader2, UserRound } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { EmptyState, ErrorState, LoadingState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { FolderStatus, RequirementsPanel } from '@/pages/ApplicantDetail'
import { Pagination } from '@/pages/Applicants'
import { formatDate } from '@/lib/utils'

/**
 * The document library, by applicant.
 *
 * The review panel's recommendation was plain: the documents view should start
 * from the person, and opening a name should show every document that person
 * has. That is also how the agency already works - one applicant's folder at a
 * time - so the list here is a list of names, each with a count of what they
 * have sent, what is accepted, and what is still waiting for somebody to look
 * at it. Opening one shows their whole checklist, uploaded or verified at the
 * counter, and lets HR review it in place.
 *
 * The open applicant can be held by the caller (the Documents page keeps it in
 * the URL so it survives a refresh and the back button); otherwise it is local.
 */
export default function ApplicantDocuments({ clientCompanyId = null, selectedId, onSelect }) {
  const [localSelected, setLocalSelected] = React.useState(null)
  const controlled = onSelect !== undefined
  const selected = controlled ? selectedId : localSelected
  const select = controlled ? onSelect : setLocalSelected

  if (selected) {
    return <ApplicantFolder applicantId={selected} onBack={() => select(null)} />
  }

  return <ApplicantList clientCompanyId={clientCompanyId} onOpen={select} />
}

function ApplicantList({ clientCompanyId, onOpen }) {
  const [search, setSearch] = React.useState('')
  const [view, setView] = React.useState('with_documents')
  const [page, setPage] = React.useState(1)
  const debounced = useDebounced(search)

  React.useEffect(() => setPage(1), [debounced, view])

  const { data, meta, loading, error, refetch } = useApi('/documents/applicants', {
    search: debounced || undefined,
    client_company_id: clientCompanyId ?? undefined,
    needs_review: view === 'needs_review' ? 1 : undefined,
    include_empty: view === 'everyone' ? 1 : undefined,
    page,
    per_page: 25,
  })

  return (
    <div className="space-y-3">
      <FilterToolbar
        search={search}
        onSearchChange={setSearch}
        searchPlaceholder="Search by applicant name or code…"
        viewToggles={[
          { id: 'with_documents', label: 'With documents', active: view === 'with_documents', onToggle: () => setView('with_documents') },
          { id: 'needs_review', label: 'Awaiting review', active: view === 'needs_review', onToggle: () => setView('needs_review') },
          ...(clientCompanyId ? [] : [{ id: 'everyone', label: 'Everyone', active: view === 'everyone', onToggle: () => setView('everyone') }]),
        ]}
        resultCount={meta?.total}
        resultLabel="applicants"
      />

      {loading ? (
        <div className="rounded-panel border bg-card">
          <SkeletonRows rows={8} columns={5} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={FolderOpen}
          title={view === 'needs_review' ? 'Nothing is waiting for review' : 'No applicant documents yet'}
          description={
            clientCompanyId
              ? 'Nobody placed with this client has documents on file.'
              : 'Applicants appear here once they upload a document through the portal, or once a document is verified at the counter.'
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Applicant</TableHead>
                <TableHead>Stage</TableHead>
                <TableHead>Documents on file</TableHead>
                <TableHead>Last upload</TableHead>
                <TableHead className="w-8" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((row) => {
                const docs = row.documents

                return (
                  <TableRow
                    key={row.id}
                    className="group cursor-pointer"
                    onClick={() => onOpen(row.id)}
                    onKeyDown={(event) => event.key === 'Enter' && onOpen(row.id)}
                    tabIndex={0}
                    aria-label={`Open ${row.full_name}'s documents`}
                  >
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                          <UserRound className="h-4 w-4" />
                        </span>
                        <div className="min-w-0">
                          <p className="truncate font-semibold text-foreground transition-colors group-hover:text-brand">
                            {row.full_name}
                          </p>
                          <p className="truncate text-xs text-muted-foreground">
                            <span className="font-mono">{row.applicant_code}</span>
                            {row.employee_number && <> · <span className="font-mono">{row.employee_number}</span></>}
                            {row.preferred_position && ` · ${row.preferred_position}`}
                          </p>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={row.current_status} />
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-wrap items-center gap-1.5 text-xs">
                        <span className="tabular-nums text-muted-foreground">
                          <span className="font-semibold text-foreground">{docs.verified}</span> of{' '}
                          {docs.checklist} verified
                        </span>
                        {docs.awaiting_review > 0 && <Badge tone="info">{docs.awaiting_review} awaiting review</Badge>}
                        {docs.needs_attention > 0 && <Badge tone="warning">{docs.needs_attention} need attention</Badge>}
                        {docs.uploaded > 0 && (
                          <span className="text-muted-foreground">· {docs.uploaded} uploaded</span>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                      {formatDate(docs.last_submitted_at)}
                    </TableCell>
                    <TableCell className="text-right">
                      <ChevronRight className="h-4 w-4 text-muted-foreground group-hover:text-brand" />
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>

          <Pagination meta={meta} onPage={setPage} />
        </div>
      )}
    </div>
  )
}

/**
 * One applicant's folder: who they are, then every document they have.
 *
 * Read from the applicant's own record on the server, so what is listed is
 * exactly what belongs to this person and nothing else.
 */
function ApplicantFolder({ applicantId, onBack }) {
  const { data, loading, refreshing, error, refetch, setData } = useApi(`/applicants/${applicantId}`)

  if (loading) return <LoadingState label="Opening documents…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const { applicant, folder } = data
  const files = (applicant.requirements ?? []).filter((r) => r.has_file).length
  const verified = (applicant.requirements ?? []).filter((r) => r.status === 'verified').length

  return (
    <div className="space-y-4">
      <Button variant="ghost" size="sm" onClick={onBack} className="-ml-2">
        <ArrowLeft className="h-3.5 w-3.5" />
        All applicants
      </Button>

      <Card>
        <CardContent className="flex flex-wrap items-center gap-4 pt-5">
          <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
            <UserRound className="h-6 w-6" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-lg font-semibold tracking-tight">{applicant.full_name}</p>
            <p className="text-sm text-muted-foreground">
              <span className="font-mono">{applicant.applicant_code}</span>
              {applicant.preferred_position && ` · Applying for ${applicant.preferred_position}`}
            </p>
            <div className="mt-1.5 flex flex-wrap items-center gap-2">
              <StatusBadge status={applicant.current_status} />
              <StatusBadge status={applicant.folder_category} label={folder.label} />
              <span className="text-xs text-muted-foreground">
                {verified} of {applicant.requirements?.length ?? 0} verified · {files} uploaded
              </span>
              {refreshing && <Loader2 className="h-3 w-3 animate-spin text-muted-foreground" />}
            </div>
          </div>
          <Button variant="outline" size="sm" asChild>
            <Link to={`/applicants/${applicant.id}`}>
              <ExternalLink className="h-3.5 w-3.5" />
              Open applicant record
            </Link>
          </Button>
        </CardContent>
      </Card>

      <FolderStatus folder={folder} />

      <RequirementsPanel
        applicant={applicant}
        onChanged={(result) =>
          setData((current) => ({
            ...current,
            folder: result?.folder ?? current.folder,
            applicant: {
              ...current.applicant,
              current_status: result?.applicant_status ?? current.applicant.current_status,
              requirements: result?.requirements ?? current.applicant.requirements,
            },
          }))
        }
      />
    </div>
  )
}
