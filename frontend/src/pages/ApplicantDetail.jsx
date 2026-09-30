import * as React from 'react'
import { useParams } from 'react-router-dom'
import {
  Eye,
  Info,
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
import { get, patch, post } from '@/lib/api'
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
import { formatDate, formatDateTime, humanise, cn } from '@/lib/utils'
import { PortalAccessDialog } from '@/pages/Users'
import ApplicantProgress from '@/components/ApplicantProgress'
import DeployApplicantDialog from '@/components/dialogs/DeployApplicantDialog'

export default function ApplicantDetail() {
  const { id } = useParams()
  const { can } = useAuth()
  const toast = useToast()

  const { data, loading, error, refetch, setData } = useApi(`/applicants/${id}`)
  const [portalOpen, setPortalOpen] = React.useState(false)
  const [deployOpen, setDeployOpen] = React.useState(false)
  const [moving, setMoving] = React.useState(false)

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
      </div>

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
            transitions={allowed_transitions}
            busy={moving}
            canChange={can('applicants.change_status')}
            onMove={async (status) => {
              // Deployment is the one move that needs facts the status cannot
              // carry - which client, which position, from when - so it opens a
              // short form instead of moving straight away.
              if (status === 'deployed') {
                setDeployOpen(true)
                return
              }

              /*
               * Archiving asks why, as a chosen reason rather than a sentence.
               *
               * CDE named two causes an application stops - the requirements
               * were never completed, or the person was not suitable for the
               * work - and the agency cannot see how many it loses to each if
               * the answer is typed differently every time.
               */
              let disposition = null

              if (status === 'archived') {
                disposition = window.prompt(
                  'Why is this application not proceeding? Enter 1 for incomplete requirements, 2 for not suitable for the job, or 3 for other:',
                  '1'
                )

                // Cancelled: leave the applicant where they are rather than
                // archiving them with no reason on the record.
                if (disposition === null) return

                disposition = { 1: 'incomplete_requirements', 2: 'not_suitable', 3: 'other' }[
                  disposition.trim()
                ]

                if (!disposition) {
                  toast.error('Not archived', 'Choose 1, 2 or 3 so the reason can be reported on.')
                  return
                }
              }

              setMoving(true)
              try {
                const response = await patch(`/applicants/${applicant.id}/status`, {
                  to_status: status,
                  ...(disposition ? { disposition_reason: disposition } : {}),
                })
                setData((current) => ({
                  ...current,
                  applicant: { ...current.applicant, ...response.data.applicant },
                  allowed_transitions: response.data.allowed_transitions,
                }))
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

      <DeployApplicantDialog
        applicant={applicant}
        open={deployOpen}
        onOpenChange={setDeployOpen}
        onDeployed={refetch}
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

/**
 * How a verification status is presented.
 *
 * Deliberately separate from whether a file exists. The two were previously one
 * value, which is what made "not uploaded" read as "not verified" — and left
 * walk-in applicants looking permanently incomplete when their papers had in
 * fact been checked across the counter.
 */
/*
 * Keyed on review_state rather than status, which splits the old catch-all
 * "Pending" into the two things it was hiding: a document that has arrived and
 * one somebody has actually opened. Officers see the same words the applicant
 * does, so a query at the counter — "it still says being checked" — refers to
 * something both people can see on their own screen.
 */
const VERIFICATION = {
  verified: { label: 'Verified', tone: 'success', icon: CheckCircle2 },
  rejected: { label: 'Rejected', tone: 'destructive', icon: XCircle },
  needs_correction: { label: 'Needs correction', tone: 'warning', icon: AlertTriangle },
  expired: { label: 'Expired', tone: 'destructive', icon: AlertTriangle },
  under_review: { label: 'Being checked', tone: 'info', icon: Eye },
  uploaded: { label: 'Upload received', tone: 'info', icon: Clock },
  not_uploaded: { label: 'Awaiting', tone: 'muted', icon: Clock },
}

function verificationOf(requirement) {
  return VERIFICATION[requirement.review_state] ?? VERIFICATION.not_uploaded
}

function RequirementsPanel({ applicant, onChanged }) {
  const { can } = useAuth()
  const toast = useToast()

  /*
   * The rows are held locally rather than read straight from the parent.
   *
   * Verifying used to trigger a full refetch of the applicant, which remounted
   * the page and threw the reader back to the top — so after every single
   * document you had to scroll down and find your place again. Patching the row
   * in place keeps the position, and the parent is told separately so the folder
   * badge above stays truthful.
   */
  const [rows, setRows] = React.useState(applicant.requirements ?? [])
  const [selected, setSelected] = React.useState(() => new Set())
  const [busy, setBusy] = React.useState(null)
  const [bulkBusy, setBulkBusy] = React.useState(false)

  React.useEffect(() => {
    setRows(applicant.requirements ?? [])
  }, [applicant.requirements])

  const grouped = React.useMemo(() => {
    const groups = { primary: [], final: [] }
    for (const requirement of rows) {
      groups[requirement.requirement_group]?.push(requirement)
    }
    return groups
  }, [rows])

  const mayVerify = can('requirements.verify')

  /** Anything not already verified is worth offering for a bulk check. */
  const selectable = React.useMemo(
    () => rows.filter((r) => r.status !== 'verified').map((r) => r.requirement_type_id),
    [rows]
  )

  function toggle(typeId) {
    setSelected((current) => {
      const next = new Set(current)
      next.has(typeId) ? next.delete(typeId) : next.add(typeId)
      return next
    })
  }

  function toggleGroup(groupRows) {
    const ids = groupRows.filter((r) => r.status !== 'verified').map((r) => r.requirement_type_id)
    const allOn = ids.length > 0 && ids.every((id) => selected.has(id))

    setSelected((current) => {
      const next = new Set(current)
      ids.forEach((id) => (allOn ? next.delete(id) : next.add(id)))
      return next
    })
  }

  /** Replaces one row in place, leaving the scroll position untouched. */
  function patchRow(updated) {
    setRows((current) =>
      current.map((r) => (r.requirement_type_id === updated.requirement_type_id ? updated : r))
    )
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

      patchRow(response.data.requirement)
      onChanged(response.data)

      toast.success(
        status === 'verified' ? 'Verified' : 'Document updated',
        `${requirement.requirement_name} — folder is now ${response.data.folder.label}.`
      )
    } catch (error) {
      toast.error('Could not update document', error.message)
    } finally {
      setBusy(null)
    }
  }

  async function verifySelected() {
    if (selected.size === 0) return

    setBulkBusy(true)

    try {
      const response = await post(`/applicants/${applicant.id}/requirements/verify-batch`, {
        requirement_type_ids: [...selected],
      })

      setRows(response.data.requirements)
      setSelected(new Set())
      onChanged(response.data)

      toast.success(response.message, `Folder is now ${response.data.folder.label}.`)
    } catch (error) {
      toast.error('Could not verify the selected documents', error.message)
    } finally {
      setBulkBusy(false)
    }
  }

  /**
   * Opening a document is also what starts its review, so the row is corrected
   * from the response rather than left claiming the document is untouched. The
   * applicant is being told at that same moment that somebody is looking at it,
   * and the two screens should not disagree.
   */
  async function handleDownload(requirement) {
    try {
      const response = await get(
        `/applicants/${applicant.id}/requirements/${requirement.requirement_type_id}/download`
      )

      if (response.data.requirement) patchRow(response.data.requirement)

      const opened = window.open(response.data.url, '_blank', 'noopener')

      // A blocked popup is the one failure that otherwise looks like nothing
      // happened: the request succeeded, so nothing throws, and the button
      // simply appears dead.
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
      {/*
        Said once, at the top. Staff no longer upload on an applicant's behalf,
        and without this the missing Upload button reads as something broken
        rather than as a deliberate rule.
      */}
      <div className="flex gap-2.5 rounded-lg border bg-muted/40 px-3 py-2.5 text-xs text-muted-foreground">
        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
        <p>
          Applicants upload their own documents. For a walk-in, check the original across the
          counter and mark it verified here — no file is needed.
        </p>
      </div>

      {/* The bulk bar only appears once something is ticked, so it never sits
          there as dead furniture. */}
      {mayVerify && selected.size > 0 && (
        <div className="sticky top-16 z-20 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/40 bg-card px-4 py-2.5 shadow-sm">
          <p className="text-sm">
            <span className="font-semibold">{selected.size}</span> selected
          </p>
          <div className="flex items-center gap-2">
            <Button variant="ghost" size="sm" onClick={() => setSelected(new Set())} disabled={bulkBusy}>
              Clear
            </Button>
            <Button size="sm" onClick={verifySelected} disabled={bulkBusy}>
              {bulkBusy ? (
                <Loader2 className="h-3.5 w-3.5 animate-spin" />
              ) : (
                <CheckCircle2 className="h-3.5 w-3.5" />
              )}
              Verify selected
            </Button>
          </div>
        </div>
      )}

      {['primary', 'final'].map((group) => {
        const groupRows = grouped[group]
        if (groupRows.length === 0) return null

        const groupSelectable = groupRows.filter((r) => r.status !== 'verified')
        const allSelected =
          groupSelectable.length > 0 &&
          groupSelectable.every((r) => selected.has(r.requirement_type_id))

        return (
          <Card key={group}>
            <CardHeader className="pb-3">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <CardTitle className="text-base">
                    {group === 'primary' ? 'Main requirements' : 'Medical requirements'}
                  </CardTitle>
                  <CardDescription>
                    {group === 'primary'
                      ? 'Collected during screening.'
                      : 'Collected once the applicant is close to placement.'}
                  </CardDescription>
                </div>

                {mayVerify && groupSelectable.length > 0 && (
                  <label className="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground">
                    <input
                      type="checkbox"
                      checked={allSelected}
                      onChange={() => toggleGroup(groupRows)}
                      className="h-4 w-4 cursor-pointer rounded border-input accent-primary"
                    />
                    Select all outstanding
                  </label>
                )}
              </div>
            </CardHeader>

            <CardContent className="divide-y">
              {groupRows.map((requirement) => {
                const verification = verificationOf(requirement)
                const VerificationIcon = verification.icon
                const isSelected = selected.has(requirement.requirement_type_id)
                const canSelect = mayVerify && requirement.status !== 'verified'

                return (
                  <div
                    key={requirement.requirement_type_id}
                    className={cn(
                      '-mx-2 flex flex-wrap items-center gap-3 rounded-md px-2 py-3 transition-colors',
                      isSelected && 'bg-primary/[0.04]'
                    )}
                  >
                    {/* Checkbox, not a radio: several documents are checked
                        together at the counter and verified in one go. */}
                    {mayVerify && (
                      <input
                        type="checkbox"
                        checked={isSelected}
                        disabled={!canSelect}
                        onChange={() => toggle(requirement.requirement_type_id)}
                        aria-label={`Select ${requirement.requirement_name}`}
                        className="h-4 w-4 shrink-0 cursor-pointer rounded border-input accent-primary disabled:cursor-not-allowed disabled:opacity-30"
                      />
                    )}

                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <p className="text-sm font-medium">{requirement.requirement_name}</p>
                        {!requirement.is_required && (
                          <Badge tone="outline" className="text-[10px]">
                            Optional
                          </Badge>
                        )}
                      </div>

                      {/* The two facts, side by side and never conflated. */}
                      <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <span
                          className={cn(
                            'inline-flex items-center gap-1',
                            requirement.has_file ? 'text-foreground/70' : 'text-muted-foreground'
                          )}
                        >
                          {requirement.has_file ? (
                            <>
                              <FileText className="h-3 w-3" />
                              {requirement.file_name}
                            </>
                          ) : (
                            <>
                              <FileText className="h-3 w-3 opacity-40" />
                              No file uploaded
                            </>
                          )}
                        </span>

                        <span
                          className={cn(
                            'inline-flex items-center gap-1 font-medium',
                            verification.tone === 'success' && 'text-success',
                            verification.tone === 'destructive' && 'text-destructive',
                            verification.tone === 'warning' && 'text-warning',
                            verification.tone === 'info' && 'text-primary',
                            verification.tone === 'muted' && 'text-muted-foreground'
                          )}
                        >
                          <VerificationIcon className="h-3 w-3" />
                          {verification.label}
                        </span>

                        {requirement.verification_method_label && (
                          <span className="text-muted-foreground">
                            {requirement.verification_method_label}
                          </span>
                        )}
                        {requirement.verified_by && (
                          <span className="text-muted-foreground">by {requirement.verified_by}</span>
                        )}
                        {/* Who started the review, when nobody has decided yet.
                            It answers "is a colleague already on this?" before
                            two officers check the same folder. */}
                        {!requirement.verified_by && requirement.first_viewed_by && (
                          <span className="text-muted-foreground">
                            opened by {requirement.first_viewed_by}
                          </span>
                        )}
                        {requirement.expiry_date && (
                          <span className="text-muted-foreground">
                            expires {formatDate(requirement.expiry_date)}
                          </span>
                        )}
                      </div>

                      {requirement.rejection_reason && (
                        <p className="mt-1 text-xs text-destructive">
                          {requirement.rejection_reason}
                        </p>
                      )}
                    </div>

                    <div className="flex shrink-0 items-center gap-1">
                      {busy === requirement.requirement_type_id ? (
                        <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                      ) : (
                        <>
                          {requirement.has_file && (
                            <Button variant="ghost" size="sm" onClick={() => handleDownload(requirement)}>
                              <Eye className="h-3.5 w-3.5" />
                              View
                            </Button>
                          )}

                          {/* Available with or without a file: the officer may
                              be holding the original. */}
                          {mayVerify && requirement.status !== 'verified' && (
                            <Button variant="ghost" size="sm" onClick={() => handleVerify(requirement, 'verified')}>
                              <CheckCircle2 className="h-3.5 w-3.5 text-success" />
                              Verify
                            </Button>
                          )}

                          {mayVerify && requirement.status === 'verified' && (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => handleVerify(requirement, 'needs_correction')}
                              title="Mark this document as needing correction"
                            >
                              <AlertTriangle className="h-3.5 w-3.5 text-warning" />
                              Undo
                            </Button>
                          )}

                          {mayVerify && requirement.has_file && requirement.status !== 'rejected' && (
                            <Button variant="ghost" size="sm" onClick={() => handleVerify(requirement, 'rejected')}>
                              <XCircle className="h-3.5 w-3.5 text-destructive" />
                              Reject
                            </Button>
                          )}
                        </>
                      )}
                    </div>
                  </div>
                )
              })}
            </CardContent>
          </Card>
        )
      })}
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
