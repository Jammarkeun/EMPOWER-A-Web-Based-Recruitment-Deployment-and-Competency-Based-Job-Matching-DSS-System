import * as React from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { ChevronRight, FileUp, Upload } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { Pagination, useClampPage } from '@/components/Pagination'
import DtrSubmissionForm from '@/components/coordination/DtrSubmissionForm'
import { DTR_SUBMISSION_STATUS } from '@/lib/attendance'
import { formatDate, formatDateTime } from '@/lib/utils'

/**
 * The client's DTR files the coordinator has handed to HR, and what became of
 * each: waiting for HR, being checked, imported, or sent back for correction.
 * Nothing here imports attendance; HR does that in the Attendance (DTR) module.
 */
export default function CoordinatorDtrSubmissions() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const status = searchParams.get('status') ?? ''
  const page = Number(searchParams.get('page') ?? 1)
  const [sending, setSending] = React.useState(false)

  const { data, meta, loading, error, refetch } = useApi('/coordinator/dtr-submissions', {
    status: status || undefined,
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
        title="DTR Submissions"
        description="Send HR the DTR files your client gives you. HR checks every row and imports them; nothing is recorded until then."
        actions={
          <Button onClick={() => setSending(true)}>
            <Upload className="h-4 w-4" />
            Submit DTR file
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
            options: [
              { value: '', label: 'All statuses' },
              { value: 'pending', label: 'Waiting for HR' },
              ...Object.entries(DTR_SUBMISSION_STATUS).map(([value, s]) => ({ value, label: s.label })),
            ],
          },
        ]}
        resultCount={meta?.total}
        resultLabel="files"
      />

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={5} columns={6} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={FileUp}
          title={status ? 'No files in this view' : 'No DTR files sent yet'}
          description="When the client gives you its DTR, send the file here for HR."
          action={
            <Button size="sm" onClick={() => setSending(true)}>
              Submit DTR file
            </Button>
          }
        />
      ) : (
        <div className="space-y-3">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>File</TableHead>
                  <TableHead>Covers</TableHead>
                  <TableHead>Sent</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>HR feedback</TableHead>
                  <TableHead className="w-8" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((s) => (
                  <TableRow key={s.id} className="group">
                    <TableCell>
                      <Link to={`/coordinator/dtr-submissions/${s.id}`} className="block">
                        <p className="font-mono text-sm font-semibold group-hover:text-brand">{s.submission_code}</p>
                        <p className="max-w-xs truncate text-xs text-muted-foreground" title={s.original_filename}>
                          {s.original_filename} · {s.company}
                        </p>
                      </Link>
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm">
                      {formatDate(s.period_from)} – {formatDate(s.period_to)}
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      {formatDateTime(s.submitted_at)}
                      <span className="block">{s.submitted_by}</span>
                    </TableCell>
                    <TableCell>
                      <Badge tone={DTR_SUBMISSION_STATUS[s.status]?.tone}>{s.status_label}</Badge>
                      {s.import_code && <p className="mt-1 text-xs text-muted-foreground">as {s.import_code}</p>}
                    </TableCell>
                    <TableCell className="max-w-xs text-xs">
                      {s.review_note ? <span className="line-clamp-2" title={s.review_note}>{s.review_note}</span> : <span className="text-muted-foreground">—</span>}
                    </TableCell>
                    <TableCell className="text-right">
                      <Link to={`/coordinator/dtr-submissions/${s.id}`} className="text-muted-foreground group-hover:text-brand" aria-label={`Open ${s.submission_code}`}>
                        <ChevronRight className="h-4 w-4" />
                      </Link>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <Pagination meta={meta} onPage={(p) => update('page', String(p))} />
        </div>
      )}

      <DtrSubmissionForm open={sending} onOpenChange={setSending} onSubmitted={(created) => navigate(`/coordinator/dtr-submissions/${created.id}`)} />
    </div>
  )
}
