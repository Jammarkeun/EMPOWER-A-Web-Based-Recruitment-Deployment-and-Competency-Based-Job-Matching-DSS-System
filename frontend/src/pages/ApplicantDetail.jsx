import * as React from 'react'
import { useParams } from 'react-router-dom'
import {
  Upload,
  Download,
  CheckCircle2,
  XCircle,
  Clock,
  FileText,
  ArrowRight,
  Loader2,
  AlertTriangle,
  UserCheck,
  KeyRound,
} from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { get, patch, post, upload } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Select } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Field } from '@/components/ui/form'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { formatDate, formatDateTime, humanise } from '@/lib/utils'
import { PortalAccessDialog } from '@/pages/Users'

export default function ApplicantDetail() {
  const { id } = useParams()
  const { can } = useAuth()
  const toast = useToast()

  const { data, loading, error, refetch } = useApi(`/applicants/${id}`)
  const [statusOpen, setStatusOpen] = React.useState(false)
  const [portalOpen, setPortalOpen] = React.useState(false)

  if (loading) return <LoadingState label="Loading applicant…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const { applicant, folder, allowed_transitions } = data

  return (
    <>
      <PageHeader
        breadcrumbs={[{ label: 'Applicants', to: '/applicants' }, { label: applicant.full_name }]}
        title={applicant.full_name}
        description={
          <>
            <span className="font-mono">{applicant.applicant_code}</span>
            {applicant.preferred_position && <> · Applying for {applicant.preferred_position}</>}
            {applicant.age && <> · {applicant.age} years old</>}
          </>
        }
        actions={
          <>
            {/*
              Portal access was previously unreachable: the dialog existed and
              the endpoint was tested, but nothing rendered it, so an applicant
              entered at the counter could never be given an account. Walk-ins
              are most applicants, and they cannot self-register after the fact.
            */}
            {can('users.view') && (
              <Button variant="outline" onClick={() => setPortalOpen(true)}>
                <KeyRound className="h-4 w-4" />
                Portal access
              </Button>
            )}
            {/*
              While an online registrant is unverified there is exactly one thing
              to do with the record, and it is offered in the panel below. Showing
              "Move to next stage" here would only lead to a refusal.
            */}
            {can('applicants.change_status') &&
              !applicant.awaiting_identity_check &&
              allowed_transitions?.length > 0 && (
                <Button onClick={() => setStatusOpen(true)}>
                  <ArrowRight className="h-4 w-4" />
                  Move to next stage
                </Button>
              )}
          </>
        }
      />

      <div className="mb-5 flex flex-wrap items-center gap-2">
        <StatusBadge status={applicant.current_status} />
        <StatusBadge status={applicant.folder_category} label={folder.label} />
        {folder.is_deployment_ready && (
          <Badge tone="success">
            <CheckCircle2 className="mr-1 h-3 w-3" /> Deployable
          </Badge>
        )}
        {applicant.source_channel === 'online' && <Badge tone="info">Registered online</Badge>}
      </div>

      {applicant.awaiting_identity_check && (
        <IdentityCheckPanel applicant={applicant} onChanged={refetch} />
      )}

      <FolderStatus folder={folder} />

      <Tabs defaultValue="requirements" className="mt-5">
        <TabsList>
          <TabsTrigger value="requirements">Requirements</TabsTrigger>
          <TabsTrigger value="profile">Profile</TabsTrigger>
          <TabsTrigger value="timeline">Timeline</TabsTrigger>
        </TabsList>

        <TabsContent value="requirements">
          <RequirementsPanel applicant={applicant} onChanged={refetch} />
        </TabsContent>

        <TabsContent value="profile">
          <ProfilePanel applicant={applicant} />
        </TabsContent>

        <TabsContent value="timeline">
          <TimelinePanel applicantId={id} />
        </TabsContent>
      </Tabs>

      <PortalAccessDialog
        open={portalOpen}
        onOpenChange={setPortalOpen}
        applicantId={applicant.id}
        personName={applicant.full_name}
      />

      <StatusDialog
        open={statusOpen}
        onOpenChange={setStatusOpen}
        applicant={applicant}
        transitions={allowed_transitions}
        onChanged={refetch}
      />
    </>
  )
}

/**
 * The identity check for an online registrant.
 *
 * Everything on this record was typed in by the applicant themselves and has
 * never been checked against a document. Confirming it is the single action
 * that releases them into screening, so it is presented as a deliberate step
 * with a named officer against it rather than as one option in a status menu.
 *
 * Shown above the folder status because until the check is done, nothing else
 * on the page can be acted on.
 */
function IdentityCheckPanel({ applicant, onChanged }) {
  const { can } = useAuth()
  const toast = useToast()
  const [remarks, setRemarks] = React.useState('')
  const [submitting, setSubmitting] = React.useState(false)

  async function handleConfirm() {
    setSubmitting(true)
    try {
      await post(`/applicants/${applicant.id}/verify-identity`, {
        remarks: remarks || undefined,
      })
      toast.success(
        'Identity confirmed',
        `${applicant.full_name} has moved to initial screening.`
      )
      onChanged()
    } catch (error) {
      toast.error('Could not confirm identity', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card className="mb-5 border-warning/40 bg-warning/5">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <UserCheck className="h-4 w-4 text-warning" />
          Identity not yet confirmed
        </CardTitle>
        <CardDescription>
          This applicant registered online on {formatDate(applicant.self_registered_at)} and entered
          these details themselves. Nobody has seen them or checked an ID, so the record is held
          before screening.
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <div className="rounded-md border bg-card px-3 py-2.5 text-sm">
          <p className="mb-1.5 font-medium">Check against a valid ID before confirming</p>
          <ul className="space-y-0.5 text-muted-foreground">
            <li>
              Name: <span className="font-medium text-foreground">{applicant.full_name}</span>
            </li>
            <li>
              Date of birth:{' '}
              <span className="font-medium text-foreground">
                {formatDate(applicant.birth_date)}
              </span>
              {applicant.age ? ` (${applicant.age} years old)` : ''}
            </li>
            <li>
              Address:{' '}
              <span className="font-medium text-foreground">{applicant.present_address}</span>
            </li>
            <li>
              Mobile:{' '}
              <span className="font-medium text-foreground">
                {applicant.contact_number || '—'}
              </span>
            </li>
          </ul>
        </div>

        {can('applicants.change_status') ? (
          <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
            <Field
              label="Which ID was presented?"
              htmlFor="identity_remarks"
              className="flex-1"
              hint="Recorded in the audit trail against your name."
            >
              <Input
                value={remarks}
                onChange={(e) => setRemarks(e.target.value)}
                placeholder="e.g. PhilSys National ID"
              />
            </Field>
            <Button onClick={handleConfirm} disabled={submitting} className="shrink-0">
              {submitting ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <CheckCircle2 className="h-4 w-4" />
              )}
              Confirm identity
            </Button>
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">
            An HR officer needs to confirm this applicant&rsquo;s identity before screening can
            begin.
          </p>
        )}

        {/* If the applicant corrected a detail verbally, HR fixes it on the
            Profile tab first and confirms afterwards. */}
        <p className="text-xs text-muted-foreground">
          If any detail above is wrong, correct it on the Profile tab before confirming.
        </p>
      </CardContent>
    </Card>
  )
}

/**
 * Explains why the applicant sits in their current folder.
 *
 * Naming the specific outstanding documents is the point: a bare "Folder 3"
 * tells HR nothing they can act on, whereas a list tells them exactly what to
 * chase.
 */
function FolderStatus({ folder }) {
  const outstanding = [...(folder.missing_primary ?? []), ...(folder.missing_final ?? [])]

  return (
    <Card className={folder.is_deployment_ready ? 'border-success/40 bg-success/5' : 'border-warning/40 bg-warning/5'}>
      <CardContent className="flex items-start gap-3 py-4">
        {folder.is_deployment_ready ? (
          <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-success" />
        ) : (
          <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-warning" />
        )}
        <div className="min-w-0 space-y-1">
          <p className="text-sm font-medium">{folder.label}</p>
          <p className="text-sm text-muted-foreground">{folder.reason}</p>
          {outstanding.length > 0 && (
            <div className="flex flex-wrap gap-1.5 pt-1">
              {outstanding.map((name) => (
                <Badge key={name} tone="warning">
                  {name}
                </Badge>
              ))}
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  )
}

function RequirementsPanel({ applicant, onChanged }) {
  const { can } = useAuth()
  const toast = useToast()
  const [busy, setBusy] = React.useState(null)

  const grouped = React.useMemo(() => {
    const groups = { primary: [], final: [] }
    for (const requirement of applicant.requirements ?? []) {
      groups[requirement.requirement_group]?.push(requirement)
    }
    return groups
  }, [applicant.requirements])

  async function handleUpload(requirement, file) {
    if (!file) return

    setBusy(requirement.requirement_type_id)
    const formData = new FormData()
    formData.append('file', file)

    // The server requires an expiry for documents that lapse. Defaulting to six
    // months keeps the common case one click, and HR can correct it after.
    if (requirement.requirement_code && ['police_clearance', 'barangay_clearance', 'drug_test', 'urine_test', 'stool_test', 'hepatitis_b', 'health_card', 'medical_result'].includes(requirement.requirement_code)) {
      const expiry = new Date()
      expiry.setMonth(expiry.getMonth() + 6)
      formData.append('expiry_date', expiry.toISOString().slice(0, 10))
    }

    try {
      await upload(`/applicants/${applicant.id}/requirements/${requirement.requirement_type_id}/upload`, formData)
      toast.success('Document uploaded', `${requirement.requirement_name} is now awaiting verification.`)
      onChanged()
    } catch (error) {
      toast.error('Upload failed', error.message)
    } finally {
      setBusy(null)
    }
  }

  async function handleVerify(requirement, status) {
    setBusy(requirement.requirement_type_id)

    try {
      const body = { status }
      if (status === 'rejected') {
        const reason = window.prompt('Why is this document being rejected?')
        if (!reason) {
          setBusy(null)
          return
        }
        body.rejection_reason = reason
      }

      const response = await patch(
        `/applicants/${applicant.id}/requirements/${requirement.requirement_type_id}`,
        body
      )

      toast.success(
        status === 'verified' ? 'Document verified' : 'Document updated',
        `Folder is now ${response.data.folder.label}.`
      )
      onChanged()
    } catch (error) {
      toast.error('Could not update document', error.message)
    } finally {
      setBusy(null)
    }
  }

  async function handleDownload(requirement) {
    try {
      const response = await get(
        `/applicants/${applicant.id}/requirements/${requirement.requirement_type_id}/download`
      )
      const opened = window.open(response.data.url, '_blank', 'noopener')

      // A blocked popup is the one failure that otherwise looks like nothing
      // happened at all: the request succeeded, so no error is thrown, and the
      // user is left clicking a button that appears dead.
      if (!opened) {
        toast.warning(
          'Your browser blocked the document',
          'Allow pop-ups for this site, then try opening it again.'
        )
      }
    } catch (error) {
      toast.error('Could not open document', error.message)
    }
  }

  return (
    <div className="space-y-4">
      {['primary', 'final'].map((group) => (
        <Card key={group}>
          <CardHeader>
            <CardTitle>{group === 'primary' ? 'Primary requirements' : 'Final requirements'}</CardTitle>
            <CardDescription>
              {group === 'primary'
                ? 'Collected during screening.'
                : 'Medical results, collected once the applicant is close to placement.'}
            </CardDescription>
          </CardHeader>
          <CardContent className="divide-y">
            {grouped[group].map((requirement) => (
              <div key={requirement.id} className="flex flex-wrap items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-medium">{requirement.requirement_name}</p>
                    <StatusBadge status={requirement.status} />
                    {requirement.is_expired && <Badge tone="destructive">Expired</Badge>}
                  </div>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {requirement.file_name ? (
                      <>
                        {requirement.file_name}
                        {requirement.expiry_date && <> · expires {formatDate(requirement.expiry_date)}</>}
                        {requirement.verified_by && <> · verified by {requirement.verified_by}</>}
                      </>
                    ) : (
                      'No document uploaded'
                    )}
                  </p>
                  {requirement.rejection_reason && (
                    <p className="mt-0.5 text-xs text-destructive">Rejected: {requirement.rejection_reason}</p>
                  )}
                </div>

                <div className="flex shrink-0 items-center gap-1.5">
                  {busy === requirement.requirement_type_id ? (
                    <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                  ) : (
                    <>
                      {requirement.has_file && (
                        <Button variant="ghost" size="sm" onClick={() => handleDownload(requirement)}>
                          <Download className="h-3.5 w-3.5" />
                          View
                        </Button>
                      )}

                      {can('requirements.upload') && (
                        <label className="cursor-pointer">
                          <input
                            type="file"
                            className="sr-only"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            onChange={(e) => handleUpload(requirement, e.target.files?.[0])}
                          />
                          <span className="inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-xs font-medium hover:bg-accent">
                            <Upload className="h-3.5 w-3.5" />
                            {requirement.has_file ? 'Replace' : 'Upload'}
                          </span>
                        </label>
                      )}

                      {can('requirements.verify') && requirement.has_file && requirement.status !== 'verified' && (
                        <Button variant="ghost" size="sm" onClick={() => handleVerify(requirement, 'verified')}>
                          <CheckCircle2 className="h-3.5 w-3.5 text-success" />
                          Verify
                        </Button>
                      )}

                      {can('requirements.verify') && requirement.has_file && requirement.status !== 'rejected' && (
                        <Button variant="ghost" size="sm" onClick={() => handleVerify(requirement, 'rejected')}>
                          <XCircle className="h-3.5 w-3.5 text-destructive" />
                          Reject
                        </Button>
                      )}
                    </>
                  )}
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

function ProfilePanel({ applicant }) {
  const details = [
    ['Sex', applicant.sex ? humanise(applicant.sex) : null],
    ['Date of birth', applicant.birth_date ? formatDate(applicant.birth_date) : null],
    ['Civil status', applicant.civil_status ? humanise(applicant.civil_status) : null],
    ['Contact number', applicant.contact_number],
    ['Email', applicant.email],
    ['Present address', applicant.present_address],
    ['Provincial address', applicant.provincial_address],
    ['Height', applicant.height_cm ? `${applicant.height_cm} cm` : null],
    ['Distance from worksite', applicant.distance_km ? `${applicant.distance_km} km` : null],
    ['Available from', applicant.availability_date ? formatDate(applicant.availability_date) : null],
    ['Communication rating', applicant.communication_rating ? `${applicant.communication_rating} / 5` : null],
    ['Reliability rating', applicant.reliability_rating ? `${applicant.reliability_rating} / 5` : null],
    ['How they applied', humanise(applicant.source_channel)],
  ].filter(([, value]) => value)

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle>Personal details</CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-x-4 gap-y-3 sm:grid-cols-2">
            {details.map(([label, value]) => (
              <div key={label}>
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd className="text-sm">{value}</dd>
              </div>
            ))}
          </dl>
        </CardContent>
      </Card>

      <div className="space-y-4">
        <Card>
          <CardHeader>
            <CardTitle>Education</CardTitle>
          </CardHeader>
          <CardContent>
            {!applicant.educations?.length ? (
              <p className="text-sm text-muted-foreground">No education recorded.</p>
            ) : (
              <ul className="space-y-2.5">
                {applicant.educations.map((edu) => (
                  <li key={edu.id}>
                    <p className="text-sm font-medium">{edu.education_label}</p>
                    <p className="text-xs text-muted-foreground">
                      {edu.school_name}
                      {edu.course_program && ` · ${edu.course_program}`}
                      {edu.graduation_year && ` · ${edu.graduation_year}`}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Work experience</CardTitle>
          </CardHeader>
          <CardContent>
            {!applicant.experiences?.length ? (
              <p className="text-sm text-muted-foreground">No previous employment recorded.</p>
            ) : (
              <ul className="space-y-2.5">
                {applicant.experiences.map((exp) => (
                  <li key={exp.id}>
                    <p className="text-sm font-medium">{exp.position_title}</p>
                    <p className="text-xs text-muted-foreground">
                      {exp.company_name} · {exp.months_experience} months
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Certifications</CardTitle>
          </CardHeader>
          <CardContent>
            {!applicant.certifications?.length ? (
              <p className="text-sm text-muted-foreground">No certifications recorded.</p>
            ) : (
              <ul className="space-y-2">
                {applicant.certifications.map((cert) => (
                  <li key={cert.id} className="flex items-center justify-between gap-2">
                    <span className="text-sm">{cert.certification_name}</span>
                    {cert.expires_at && (
                      <Badge tone={new Date(cert.expires_at) < new Date() ? 'destructive' : 'muted'}>
                        {new Date(cert.expires_at) < new Date() ? 'Expired' : `Valid to ${formatDate(cert.expires_at)}`}
                      </Badge>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

function TimelinePanel({ applicantId }) {
  const { data, loading, error, refetch } = useApi(`/applicants/${applicantId}/timeline`)

  if (loading) return <LoadingState />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />

  if (!data?.length) {
    return (
      <Card>
        <EmptyState icon={Clock} title="No activity yet" description="Status changes and events will appear here." />
      </Card>
    )
  }

  const icons = { status_change: ArrowRight, training: FileText, evaluation: CheckCircle2 }

  return (
    <Card>
      <CardContent className="pt-5">
        <ol className="relative space-y-5 border-l pl-6">
          {data.map((event, index) => {
            const Icon = icons[event.type] ?? Clock
            return (
              <li key={index} className="relative">
                <span className="absolute -left-[31px] flex h-6 w-6 items-center justify-center rounded-full border bg-card">
                  <Icon className="h-3 w-3 text-muted-foreground" />
                </span>
                <p className="text-sm font-medium">{event.title}</p>
                {event.detail && <p className="text-xs text-muted-foreground">{event.detail}</p>}
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {formatDateTime(event.occurred_at)}
                  {event.actor && ` · ${event.actor}`}
                </p>
              </li>
            )
          })}
        </ol>
      </CardContent>
    </Card>
  )
}

function StatusDialog({ open, onOpenChange, applicant, transitions, onChanged }) {
  const [status, setStatus] = React.useState('')
  const [reason, setReason] = React.useState('')
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setStatus(transitions?.[0] ?? '')
      setReason('')
    }
  }, [open, transitions])

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)

    try {
      await patch(`/applicants/${applicant.id}/status`, { to_status: status, reason: reason || undefined })
      toast.success('Status updated', `Now ${humanise(status)}.`)
      onOpenChange(false)
      onChanged()
    } catch (error) {
      // A 409 means the move is blocked by a business rule, such as documents
      // still outstanding. The server's message names the reason, so it is
      // shown verbatim rather than replaced with something generic.
      toast.error(error.isConflict ? 'This move is not allowed' : 'Could not update status', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Move to next stage</DialogTitle>
          <DialogDescription>
            Only stages that follow {humanise(applicant.current_status)} are offered. Document
            requirements are checked before the change is applied.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          <Field label="New status" htmlFor="to_status" required>
            <Select value={status} onChange={(e) => setStatus(e.target.value)}>
              {transitions?.map((value) => (
                <option key={value} value={value}>
                  {humanise(value)}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Reason" htmlFor="reason" hint="Recorded in the applicant's history">
            <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Optional note" />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting || !status}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Update status
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
