import * as React from 'react'
import { useParams } from 'react-router-dom'
import { FileText } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { get } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { LoadingState, ErrorState } from '@/components/ui/states'
import IncidentThread from '@/components/coordination/IncidentThread'
import { IncidentFacts } from './coordinator/CoordinatorIncident'
import { ReportStatus } from './coordinator/CoordinatorEmployeeDetail'
import { formatDate, formatDateTime } from '@/lib/utils'

/**
 * The office's view of one incident report, and its answer.
 */
export default function IncidentReportDetail() {
  const { id } = useParams()
  const toast = useToast()
  const { data: report, loading, error, refetch } = useApi(`/incident-reports/${id}`)

  if (loading) return <LoadingState label="Loading report…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!report) return null

  async function openEvidence() {
    try {
      const response = await get(`/incident-reports/${id}/evidence`)
      if (!window.open(response.data.url, '_blank', 'noopener')) {
        toast.warning('Your browser blocked the document', 'Allow pop-ups for this site, then try again.')
      }
    } catch (err) {
      toast.error('Could not open the evidence', err.message)
    }
  }

  return (
    <div className="space-y-5">
      <PageHeader
        breadcrumbs={[{ label: 'Incident Reports', to: '/incident-reports' }, { label: report.report_code }]}
        title={report.title}
        description={`${report.company_name} · ${formatDate(report.incident_date)}${report.incident_time ? ` at ${report.incident_time}` : ''} · sent ${formatDateTime(report.submitted_at)}`}
      />

      <ReportStatus status={report.status} label={report.status_label} />

      <div className="grid gap-4 lg:grid-cols-5">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-3">
            <CardTitle className="text-base">What the coordinator reported</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3 text-sm">
            <IncidentFacts report={report} />
            {report.has_evidence && (
              <Button variant="outline" size="sm" onClick={openEvidence}>
                <FileText className="h-3.5 w-3.5" />
                {report.evidence_name ?? 'Evidence'}
              </Button>
            )}
          </CardContent>
        </Card>

        <div className="lg:col-span-3">
          <IncidentThread
            report={report}
            endpoint={`/incident-reports/${report.id}/notes`}
            canChangeStatus={report.can_change_status}
            onChanged={refetch}
          />
        </div>
      </div>
    </div>
  )
}
