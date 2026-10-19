import * as React from 'react'
import { Eye, Info, CheckCircle2, XCircle, Clock, FileText, Loader2, AlertTriangle } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { get, patch, post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Select } from '@/components/ui/select'
import { formatDate, cn } from '@/lib/utils'

/**
 * Explains why the applicant sits in their current folder.
 *
 * Naming the specific outstanding documents is the point: a bare "Folder 3"
 * tells HR nothing they can act on, whereas a list tells them exactly what to
 * chase.
 */
export function FolderStatus({ folder }) {
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

/**
 * Exported for the document library, which opens an applicant's folder with
 * this same panel - so reviewing a document works identically from either
 * screen rather than being written twice and drifting apart.
 */
export function RequirementsPanel({ applicant, onChanged }) {
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
        <div className="sticky top-16 z-20 flex flex-wrap items-center justify-between gap-3 rounded-row border-2 border-inset-selected-border bg-card px-4 py-2.5 shadow-float lg:top-24">
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
