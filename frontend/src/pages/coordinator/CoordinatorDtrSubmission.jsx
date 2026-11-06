import * as React from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { CornerUpLeft, Download, Loader2, Send } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { api } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { LoadingState, ErrorState } from '@/components/ui/states'
import DtrSubmissionForm from '@/components/coordination/DtrSubmissionForm'
import { DTR_SUBMISSION_STATUS, formatFileSize } from '@/lib/attendance'
import { formatDate, formatDateTime } from '@/lib/utils'
import { OutOfScope } from './CoordinatorEmployeeDetail'

/** Saves a private file fetched with the signed-in session. */
export async function downloadPrivateFile(url, filename) {
  const response = await api.get(url, { responseType: 'blob' })
  const href = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = href
  link.download = filename
  link.click()
  URL.revokeObjectURL(href)
}

/**
 * One DTR file the coordinator sent: what was sent, where it stands, HR's
 * feedback, and every step so far. A returned file is corrected by sending a
 * new one, which keeps this one as it was.
 */
export default function CoordinatorDtrSubmission() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const { data, loading, error, refetch } = useApi(`/coordinator/dtr-submissions/${id}`)
  const [correcting, setCorrecting] = React.useState(false)
  const [downloading, setDownloading] = React.useState(false)

  if (loading) return <LoadingState label="Loading DTR submission…" />
  if (error?.status === 403) return <OutOfScope message={error.message} backTo="/coordinator/dtr-submissions" backLabel="Back to DTR submissions" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  async function download() {
    setDownloading(true)
    try {
      await downloadPrivateFile(`/coordinator/dtr-submissions/${data.id}/file`, data.original_filename)
    } catch (err) {
      toast.error('Could not download the file', err.message)
    } finally {
      setDownloading(false)
    }
  }

  const status = DTR_SUBMISSION_STATUS[data.status]

  return (
    <div className="space-y-5">
      <PageHeader
        breadcrumbs={[{ label: 'DTR Submissions', to: '/coordinator/dtr-submissions' }, { label: data.submission_code }]}
        title={data.original_filename}
        description={`${data.submission_code} · ${data.company} · covers ${formatDate(data.period_from)} – ${formatDate(data.period_to)}`}
        actions={
          <>
            <Button variant="outline" onClick={download} disabled={downloading}>
              {downloading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
              File as sent
            </Button>
            {data.status === 'returned' && !data.resubmission && (
              <Button onClick={() => setCorrecting(true)}>
                <Send className="h-4 w-4" />
                Send corrected file
              </Button>
            )}
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={status?.tone}>{data.status_label}</Badge>
        <span className="text-sm text-muted-foreground">
          {data.status === 'submitted' && 'Waiting for HR to check the file.'}
          {data.status === 'under_review' && 'HR is checking the file in the DTR importer.'}
          {data.status === 'imported' && `Imported by HR${data.import ? ` as ${data.import.import_code}` : ''}, ${formatDateTime(data.imported_at)}.`}
          {data.status === 'returned' && 'HR sent it back for correction.'}
          {data.status === 'rejected' && 'HR rejected this file.'}
        </span>
      </div>

      {['returned', 'rejected'].includes(data.status) && data.review_note && (
        <div className="flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-sm">
          <CornerUpLeft className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
          <p>
            {data.reviewed_by}: <span className="font-medium">{data.review_note}</span>
            {data.resubmission && (
              <>
                {' '}A corrected file was sent as{' '}
                <Link to={`/coordinator/dtr-submissions/${data.resubmission.id}`} className="font-medium text-brand hover:underline">
                  {data.resubmission.submission_code}
                </Link>{' '}
                ({data.resubmission.status_label.toLowerCase()}).
              </>
            )}
          </p>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-3">
            <CardTitle className="text-base">What was sent</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              {[
                ['Client company', data.company],
                ['DTR covers', `${formatDate(data.period_from)} – ${formatDate(data.period_to)}`],
                ['File', `${data.original_filename} · ${formatFileSize(data.file_size_bytes)} · ${data.total_rows} row(s)`],
                ['Sent', `${formatDateTime(data.submitted_at)} by ${data.submitted_by}`],
                ['Source / handover', data.source_note || '—'],
                ['Corrects', data.resubmission_of?.submission_code],
              ]
                .filter(([, value]) => value)
                .map(([label, value]) => (
                  <div key={label}>
                    <dt className="text-xs text-muted-foreground">{label}</dt>
                    <dd>
                      {label === 'Corrects' ? (
                        <Link to={`/coordinator/dtr-submissions/${data.resubmission_of.id}`} className="text-brand hover:underline">
                          {value}
                        </Link>
                      ) : (
                        value
                      )}
                    </dd>
                  </div>
                ))}
            </dl>
            {data.import && (
              <p className="mt-4 rounded-md border px-3 py-2 text-sm">
                Imported as <span className="font-mono">{data.import.import_code}</span>: {data.import.imported_rows} day(s) added,{' '}
                {data.import.unchanged_rows} already on file, {data.import.error_rows} problem row(s).
              </p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">History</CardTitle>
          </CardHeader>
          <CardContent>
            <ol className="space-y-3 border-l pl-4">
              {data.events.map((e) => (
                <li key={e.id} className="relative text-sm">
                  <span className="absolute -left-[21px] top-1.5 h-2 w-2 rounded-full bg-border" aria-hidden="true" />
                  <p className="font-medium">{e.label}</p>
                  <p className="text-xs text-muted-foreground">
                    {e.by} · {formatDateTime(e.at)}
                  </p>
                  {e.note && <p className="mt-0.5 text-xs">{e.note}</p>}
                </li>
              ))}
            </ol>
          </CardContent>
        </Card>
      </div>

      <DtrSubmissionForm
        open={correcting}
        onOpenChange={setCorrecting}
        correcting={data}
        onSubmitted={(created) => navigate(`/coordinator/dtr-submissions/${created.id}`)}
      />
    </div>
  )
}
