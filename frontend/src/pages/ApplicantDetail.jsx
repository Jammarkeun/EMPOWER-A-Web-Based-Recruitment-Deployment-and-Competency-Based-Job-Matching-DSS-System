import * as React from 'react'
import { useParams } from 'react-router-dom'
import { AlertTriangle, CheckCircle2, Clock, FileText, ArrowRight, KeyRound } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { patch } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import PersonAvatar from '@/components/PersonAvatar'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import { formatDate, formatDateTime, humanise } from '@/lib/utils'
import { PortalAccessDialog } from '@/components/dialogs/PortalAccessDialog'
import ApplicantProgress from '@/components/ApplicantProgress'
import DeployApplicantDialog from '@/components/dialogs/DeployApplicantDialog'
import MatchingProfileDialog from '@/components/dialogs/MatchingProfileDialog'
import IdentityCheckPanel from '@/components/applicant/IdentityCheckPanel'
import { FolderStatus, RequirementsPanel } from '@/components/applicant/RequirementsPanel'
import PlacementPanel from '@/components/applicant/PlacementPanel'
import EndorseDialog from '@/components/applicant/EndorseDialog'
import TrainingSummary from '@/components/applicant/TrainingSummary'
import ArchiveApplicantDialog from '@/components/applicant/ArchiveApplicantDialog'

export default function ApplicantDetail() {
  const { id } = useParams()
  const { can } = useAuth()
  const toast = useToast()

  const { data, loading, error, refetch, setData } = useApi(`/applicants/${id}`)
  const [portalOpen, setPortalOpen] = React.useState(false)
  const [profileOpen, setProfileOpen] = React.useState(false)
  const [deployOpen, setDeployOpen] = React.useState(false)
  const [endorseOpen, setEndorseOpen] = React.useState(false)
  const [archiveOpen, setArchiveOpen] = React.useState(false)
  const [moving, setMoving] = React.useState(false)

  if (loading) return <LoadingState label="Loading applicant…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const { applicant, folder, allowed_transitions, training } = data

  // Said before the click rather than after it: the server refuses the
  // endorsement anyway, but the officer should not have to try to find out.
  const blockedMoves =
    training?.required_before_endorsement && !training.requirement_met
      ? { client_evaluation: 'Training must be recorded as completed first. Enrol them in a session from the Training page.' }
      : {}

  function applyMove(result) {
    setData((current) => ({
      ...current,
      applicant: { ...current.applicant, ...result.applicant },
      allowed_transitions: result.allowed_transitions,
      placement: result.placement ?? current.placement,
    }))
  }

  return (
    <>
      <PageHeader
        breadcrumbs={[{ label: 'Applicants', to: '/applicants' }, { label: applicant.full_name }]}
        leading={<PersonAvatar src={applicant.photo_url} name={applicant.full_name} size="lg" />}
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
          </>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <StatusBadge status={applicant.folder_category} label={folder.label} />
        {folder.is_deployment_ready && (
          <Badge tone="success">
            <CheckCircle2 className="mr-1 h-3 w-3" /> Deployable
          </Badge>
        )}
        {applicant.source_channel === 'online' && <Badge tone="info">Registered online</Badge>}
        {applicant.documents_lapsed && (
          <Badge tone="warning">
            <AlertTriangle className="mr-1 h-3 w-3" aria-hidden="true" />
            Documents lapsed since {applicant.status_label}
          </Badge>
        )}
      </div>

      {applicant.documents_lapsed && (
        <p className="mb-4 rounded-row bg-status-warning-bg px-4 py-3 text-sm text-status-warning-text">
          {applicant.full_name} reached “{applicant.status_label}” with every required document verified, but a document
          has since lapsed or been rejected. The status has not been changed; deployment stays refused until the folder is
          back to Folder 1.
        </p>
      )}

      {applicant.awaiting_identity_check && (
        <IdentityCheckPanel applicant={applicant} onChanged={refetch} />
      )}

      {/*
        The recruitment path, shown rather than hidden behind a dropdown of
        fifteen statuses. An officer can see where the applicant is and what the
        single next step is, instead of having to know the whole map.
      */}
      {!applicant.awaiting_identity_check && (
        <div className="mb-5">
          <ApplicantProgress
            applicant={applicant}
            stages={data.stages}
            blockedMoves={blockedMoves}
            transitions={allowed_transitions}
            busy={moving}
            canChange={can('applicants.change_status')}
            employeeId={applicant.employee?.id}
            onMove={async (status) => {
              // Deployment is the one move that needs facts the status cannot
              // carry - which client, which position, from when - so it opens a
              // short form instead of moving straight away.
              if (status === 'deployed') {
                setDeployOpen(true)
                return
              }

              // Endorsing asks which request it is for, when the officer knows,
              // so the applicant's answer belongs to that placement (R-05).
              if (status === 'client_evaluation') {
                setEndorseOpen(true)
                return
              }

              // Archiving asks why, as a chosen reason rather than a sentence,
              // so the agency can count what it loses to each cause.
              if (status === 'archived') {
                setArchiveOpen(true)
                return
              }

              setMoving(true)
              try {
                const response = await patch(`/applicants/${applicant.id}/status`, { to_status: status })
                applyMove(response.data)
                toast.success('Stage updated', `Now ${humanise(status)}.`)
              } catch (error) {
                toast.error(
                  error.isConflict ? 'This move is not allowed' : 'Could not update stage',
                  error.message
                )
              } finally {
                setMoving(false)
              }
            }}
          />
        </div>
      )}

      <FolderStatus folder={folder} />

      <PlacementPanel
        applicant={applicant}
        placement={data.placement}
        canCorrect={can('applicants.change_status')}
        onChanged={(placement) => setData((current) => ({ ...current, placement }))}
      />

      <TrainingSummary training={training} canManage={can('training.update')} />

      <Tabs defaultValue="requirements" className="mt-5">
        <TabsList>
          <TabsTrigger value="requirements">Requirements</TabsTrigger>
          <TabsTrigger value="profile">Profile</TabsTrigger>
          <TabsTrigger value="timeline">Timeline</TabsTrigger>
        </TabsList>

        <TabsContent value="requirements">
          {/*
            Verifying a document updates this page in place rather than
            refetching it. A refetch remounts the whole applicant view and
            throws the reader back to the top, which meant scrolling down to
            find your place again after every single document.
          */}
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
        </TabsContent>

        <TabsContent value="profile">
          <ProfilePanel
            applicant={applicant}
            onEdit={can('applicants.update') ? () => setProfileOpen(true) : null}
          />
          <MatchingProfileDialog
            open={profileOpen}
            onOpenChange={setProfileOpen}
            applicant={applicant}
            onSaved={refetch}
          />
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

      <DeployApplicantDialog
        applicant={applicant}
        open={deployOpen}
        onOpenChange={setDeployOpen}
        onDeployed={refetch}
      />

      <EndorseDialog applicant={applicant} open={endorseOpen} onOpenChange={setEndorseOpen} onEndorsed={applyMove} />

      <ArchiveApplicantDialog
        open={archiveOpen}
        onOpenChange={setArchiveOpen}
        applicant={applicant}
        onArchived={applyMove}
      />
    </>
  )
}

function ProfilePanel({ applicant, onEdit }) {
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

  const hasProfile =
    applicant.educations?.length || applicant.experiences?.length || applicant.skills?.length || applicant.certifications?.length

  return (
    <div className="space-y-4">
      {/*
        What the competency evaluation reads. Said here because these sections
        used to be display-only with nothing anywhere to fill them in, so every
        applicant scored zero on education, experience, skills, and
        certificates.
      */}
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/40 px-3 py-2.5">
        <p className="text-xs text-muted-foreground">
          {hasProfile
            ? 'Education, experience, skills, certificates, and the screening details below are what the matching compares with a request.'
            : 'No qualifications recorded yet, so this applicant scores nothing on education, experience, skills, or certificates. Record them from the resume.'}
        </p>
        {onEdit && (
          <Button size="sm" variant={hasProfile ? 'outline' : 'default'} onClick={onEdit}>
            Edit matching profile
          </Button>
        )}
      </div>

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
            <CardTitle>Skills</CardTitle>
          </CardHeader>
          <CardContent>
            {!applicant.skills?.length ? (
              <p className="text-sm text-muted-foreground">No skills recorded.</p>
            ) : (
              <ul className="flex flex-wrap gap-1.5">
                {applicant.skills.map((skill) => (
                  <li key={skill.id ?? skill.skill_name}>
                    <Badge tone="outline">{skill.skill_name}</Badge>
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
                {event.recorded_automatically && (
                  <Badge tone="warning" className="mt-1">
                    Recorded automatically
                  </Badge>
                )}
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
