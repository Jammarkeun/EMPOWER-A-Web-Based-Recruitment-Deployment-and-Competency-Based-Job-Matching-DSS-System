import * as React from 'react'
import { Link } from 'react-router-dom'
import { Folder, FolderOpen, ArrowLeft, Eye, FileText, Loader2 } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { get } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import { formatDate, cn } from '@/lib/utils'

/**
 * Uploaded documents, grouped by what kind of document they are.
 *
 * A different question from the Folder 1/2/3 filing, and deliberately a separate
 * screen. Folders 1/2/3 describe how complete one applicant's paperwork is; this
 * answers "show me the medical certificates", which previously meant opening
 * applicants one at a time until you found them.
 *
 * Only uploaded files appear. A requirement verified at the counter has no file
 * to show, and listing it as an empty row would look like a fault rather than
 * the ordinary case it is.
 */
export default function DocumentFolders({ clientCompanyId = null }) {
  const [openFolder, setOpenFolder] = React.useState(null)

  const params = clientCompanyId ? { client_company_id: clientCompanyId } : undefined
  const { data, loading, error, refetch } = useApi('/documents', params)

  if (loading) return <LoadingState label="Loading documents…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  if (openFolder) {
    return (
      <FolderContents
        folder={openFolder}
        clientCompanyId={clientCompanyId}
        onBack={() => setOpenFolder(null)}
      />
    )
  }

  const groups = {
    primary: data.folders.filter((f) => f.group === 'primary'),
    final: data.folders.filter((f) => f.group === 'final'),
  }

  return (
    <div className="space-y-4">
      {data.total_files === 0 ? (
        <Card>
          <CardContent className="pt-5">
            <EmptyState
              icon={Folder}
              title="No documents uploaded yet"
              description={
                clientCompanyId
                  ? 'Nobody placed with this client has uploaded a document.'
                  : 'Applicants upload their documents through the portal. Requirements checked at the counter are verified without a file, so they do not appear here.'
              }
            />
          </CardContent>
        </Card>
      ) : (
        [
          ['primary', 'Main documents'],
          ['final', 'Medical documents'],
        ].map(([key, label]) =>
          groups[key].length === 0 ? null : (
            <Card key={key}>
              <CardHeader className="pb-3">
                <CardTitle className="text-base">{label}</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                  {groups[key].map((folder) => (
                    <button
                      key={folder.requirement_type_id}
                      type="button"
                      onClick={() => folder.file_count > 0 && setOpenFolder(folder)}
                      disabled={folder.file_count === 0}
                      className={cn(
                        'flex items-center gap-3 rounded-lg border p-3 text-left transition-all',
                        folder.file_count > 0
                          ? 'cursor-pointer hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-sm'
                          : 'cursor-default opacity-55'
                      )}
                    >
                      <span
                        className={cn(
                          'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                          folder.file_count > 0
                            ? 'bg-primary/10 text-primary'
                            : 'bg-muted text-muted-foreground'
                        )}
                      >
                        <Folder className="h-4 w-4" />
                      </span>
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{folder.name}</p>
                        <p className="text-xs text-muted-foreground">
                          {folder.file_count === 0
                            ? 'No files'
                            : `${folder.file_count} file${folder.file_count === 1 ? '' : 's'}`}
                        </p>
                      </div>
                    </button>
                  ))}
                </div>
              </CardContent>
            </Card>
          )
        )
      )}
    </div>
  )
}

function FolderContents({ folder, clientCompanyId, onBack }) {
  const toast = useToast()
  const [opening, setOpening] = React.useState(null)

  const params = clientCompanyId ? { client_company_id: clientCompanyId } : undefined
  const { data, meta, loading, error, refetch } = useApi(
    `/documents/${folder.requirement_type_id}`,
    params
  )

  async function openFile(row) {
    setOpening(row.id)
    try {
      const response = await get(`/documents/file/${row.id}`)
      const opened = window.open(response.data.url, '_blank', 'noopener')

      // Nothing throws when a popup blocker steps in, so without this the
      // button simply appears not to work.
      if (!opened) {
        toast.warning(
          'Your browser blocked the document',
          'Allow pop-ups for this site, then try again.'
        )
      }
    } catch (err) {
      toast.error('Could not open document', err.message)
    } finally {
      setOpening(null)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex items-start gap-3">
            <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <FolderOpen className="h-4 w-4" />
            </span>
            <div>
              <CardTitle className="text-base">{folder.name}</CardTitle>
              <CardDescription>
                {meta?.total ?? folder.file_count} file
                {(meta?.total ?? folder.file_count) === 1 ? '' : 's'}
              </CardDescription>
            </div>
          </div>
          <Button variant="ghost" size="sm" onClick={onBack}>
            <ArrowLeft className="h-3.5 w-3.5" />
            All folders
          </Button>
        </div>
      </CardHeader>

      <CardContent>
        {loading ? (
          <LoadingState label="Loading files…" />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState title="This folder is empty" />
        ) : (
          <ul className="divide-y">
            {data.map((row) => (
              <li key={row.id} className="flex flex-wrap items-center gap-3 py-3">
                <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />

                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{row.file_name}</p>
                  <p className="truncate text-xs text-muted-foreground">
                    {/* Whose document it is, so a file is never an orphan. */}
                    <Link
                      to={`/applicants/${row.applicant.id}`}
                      className="text-primary hover:underline"
                    >
                      {row.applicant.name}
                    </Link>
                    {row.submitted_at && <> · uploaded {formatDate(row.submitted_at)}</>}
                    {row.expiry_date && <> · expires {formatDate(row.expiry_date)}</>}
                  </p>
                </div>

                <StatusBadge status={row.status} />

                {row.verified_by && (
                  <Badge tone="outline" className="hidden text-[10px] lg:inline-flex">
                    {row.verified_by}
                  </Badge>
                )}

                <Button
                  variant="ghost"
                  size="sm"
                  disabled={opening === row.id}
                  onClick={() => openFile(row)}
                >
                  {opening === row.id ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <Eye className="h-3.5 w-3.5" />
                  )}
                  View
                </Button>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
