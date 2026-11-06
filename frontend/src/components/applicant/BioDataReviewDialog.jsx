import * as React from 'react'
import { AlertTriangle, CheckCircle2, Download, Info, Loader2, Undo2 } from 'lucide-react'
import { get, post } from '@/lib/api'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Textarea } from '@/components/ui/textarea'
import { Field } from '@/components/ui/form'
import { LoadingState, ErrorState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import BioDataSummary from '@/components/bioData/BioDataSummary'
import { BIO_DATA_LABELS, BIO_DATA_STATES, downloadBioDataPdf, optionLabel } from '@/lib/bioData'
import { formatDateTime } from '@/lib/utils'

const OUTCOMES = {
  verified: { label: 'Verified', tone: 'success' },
  needs_correction: { label: 'Returned for correction', tone: 'warning' },
}

/**
 * HR's review of an applicant's digital Bio-Data.
 *
 * Opening it is what puts a submission under review, as opening an uploaded
 * file does. The reviewer sees the latest version with every declared value
 * that differs from the profile marked, since verifying writes those onto the
 * profile; earlier versions, and what was decided about each, stay readable.
 *
 * Verifying confirms what was entered and nothing more: it is not a check of
 * the birth certificate, the diploma or any other requirement.
 */
export default function BioDataReviewDialog({ applicant, open, onOpenChange, onReviewed }) {
  const { can } = useAuth()
  const toast = useToast()
  const [data, setData] = React.useState(null)
  const [error, setError] = React.useState(null)
  const [selected, setSelected] = React.useState(0)
  const [mode, setMode] = React.useState(null)
  const [note, setNote] = React.useState('')
  const [noteError, setNoteError] = React.useState(null)
  const [busy, setBusy] = React.useState(false)
  const [downloading, setDownloading] = React.useState(false)

  const load = React.useCallback(async () => {
    setError(null)
    try {
      const response = await get(`/applicants/${applicant.id}/bio-data`)
      setData(response.data)
    } catch (err) {
      setError(err)
    }
  }, [applicant.id])

  React.useEffect(() => {
    if (!open) return
    setData(null)
    setSelected(0)
    setMode(null)
    setNote('')
    setNoteError(null)
    load()
  }, [open, load])

  const version = data?.versions?.[selected]
  const pending = selected === 0 && data?.can_review
  const state = BIO_DATA_STATES[data?.state] ?? BIO_DATA_STATES.not_started

  async function decide(outcome) {
    if (outcome === 'needs_correction' && !note.trim()) {
      setNoteError('Say what the applicant needs to correct.')
      return
    }

    setBusy(true)
    try {
      const response = await post(`/applicants/${applicant.id}/bio-data/review`, {
        outcome,
        note: note.trim() || null,
      })
      setData(response.data.bio_data)
      setMode(null)
      setNote('')
      onReviewed?.(response.data)
      toast.success(response.message, `Folder is now ${response.data.folder.label}.`)
    } catch (err) {
      if (err.isValidation) setNoteError(err.errors.note?.[0] ?? err.message)
      else toast.error('Could not record the review', err.message)
    } finally {
      setBusy(false)
    }
  }

  async function downloadPdf() {
    setDownloading(true)
    try {
      await downloadBioDataPdf(`/applicants/${applicant.id}/bio-data/pdf?version=${version.version_number}`)
    } catch (err) {
      toast.error('Could not create the PDF', err.message)
    } finally {
      setDownloading(false)
    }
  }

  const changes = pending ? version?.would_change : null
  const changeList = Object.entries(changes ?? {})

  return (
    <Dialog open={open} onOpenChange={(next) => !busy && onOpenChange(next)}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="flex flex-wrap items-center gap-2">
            Bio-Data — {applicant.full_name}
            {data && <Badge tone={state.tone}>{state.label}</Badge>}
          </DialogTitle>
          <DialogDescription>
            Entered by the applicant through the portal. Verifying confirms this information only, not any
            other document.
          </DialogDescription>
        </DialogHeader>

        {error && <ErrorState message={error.message} onRetry={load} />}
        {!data && !error && <LoadingState label="Loading Bio-Data…" />}

        {data && data.versions.length === 0 && (
          <p className="rounded-md border bg-muted/40 px-3 py-3 text-sm text-muted-foreground">
            {data.has_draft
              ? 'The applicant has started the form but has not sent it. Drafts are not shown to the office.'
              : 'The applicant has not filled in the digital Bio-Data.'}
          </p>
        )}

        {version && (
          <div className="space-y-4">
            {/* Every submission is kept; pick one to read it as it was sent. */}
            {data.versions.length > 1 && (
              <div className="flex flex-wrap gap-1.5" role="tablist" aria-label="Versions">
                {data.versions.map((v, index) => (
                  <Button
                    key={v.id}
                    size="sm"
                    variant={index === selected ? 'navy' : 'outline'}
                    onClick={() => setSelected(index)}
                    role="tab"
                    aria-selected={index === selected}
                  >
                    Version {v.version_number}
                  </Button>
                ))}
              </div>
            )}

            <div className="flex flex-wrap items-start justify-between gap-2 rounded-md border px-3 py-2.5 text-xs">
              <div className="space-y-0.5">
                <p>
                  Version {version.version_number}, sent {formatDateTime(version.submitted_at)}
                  {version.submitted_by && ` by ${version.submitted_by}`}
                </p>
                {version.review_outcome ? (
                  <p className="text-muted-foreground">
                    {OUTCOMES[version.review_outcome]?.label} by {version.reviewed_by ?? 'staff'} on{' '}
                    {formatDateTime(version.reviewed_at)}
                    {version.review_note && ` — “${version.review_note}”`}
                  </p>
                ) : (
                  <p className="text-muted-foreground">Waiting for a decision.</p>
                )}
                {Object.keys(version.applied_changes ?? {}).length > 0 && (
                  <p className="text-muted-foreground">
                    Profile updated on verification:{' '}
                    {Object.keys(version.applied_changes).map((f) => BIO_DATA_LABELS[f] ?? f).join(', ')}
                  </p>
                )}
              </div>
              <Button variant="outline" size="sm" onClick={downloadPdf} disabled={downloading}>
                {downloading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Download className="h-3.5 w-3.5" />}
                PDF
              </Button>
            </div>

            {pending && changeList.length > 0 && (
              <div className="flex gap-2 rounded-md border border-warning/40 bg-warning/5 px-3 py-2.5 text-xs">
                <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
                <p>
                  Verifying will update the profile:{' '}
                  {changeList
                    .map(([field, [from, to]]) => `${BIO_DATA_LABELS[field]} ${display(field, from, data.options)} → ${display(field, to, data.options)}`)
                    .join('; ')}
                  .
                </p>
              </div>
            )}

            <BioDataSummary
              profile={version.data.profile}
              form={version.data.form}
              options={data.options}
              changes={changes}
            />

            {/* Matching scores what HR records, not what is declared here. */}
            <div className="flex gap-2 rounded-md border bg-muted/40 px-3 py-2.5 text-xs text-muted-foreground">
              <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
              <div>
                <p>
                  Education and work history declared here are not used for matching. The qualifications matching
                  scores are recorded by HR under the applicant&apos;s matching profile.
                </p>
                <p className="mt-1">
                  Recorded now:{' '}
                  {data.recorded_qualifications.educations.length
                    ? data.recorded_qualifications.educations
                        .map((e) => `${optionLabel(data.options.education_level, e.education_level)} (${e.school_name})`)
                        .join(', ')
                    : 'no education'}
                  {' · '}
                  {data.recorded_qualifications.experiences.length
                    ? `${data.recorded_qualifications.experiences.length} job(s)`
                    : 'no work history'}
                  .
                </p>
              </div>
            </div>

            {pending && mode === 'return' && (
              <Field label="What needs correcting" htmlFor="bio_note" required error={noteError} hint="The applicant sees this on their Bio-Data page.">
                <Textarea
                  rows={3}
                  maxLength={255}
                  value={note}
                  onChange={(e) => {
                    setNote(e.target.value)
                    setNoteError(null)
                  }}
                />
              </Field>
            )}
          </div>
        )}

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={busy}>
            Close
          </Button>
          {pending && can('requirements.verify') && (
            mode === 'return' ? (
              <>
                <Button variant="outline" onClick={() => setMode(null)} disabled={busy}>
                  Back
                </Button>
                <Button variant="destructive" onClick={() => decide('needs_correction')} disabled={busy}>
                  {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Undo2 className="h-4 w-4" />}
                  Return for correction
                </Button>
              </>
            ) : (
              <>
                <Button variant="outline" onClick={() => setMode('return')} disabled={busy}>
                  <Undo2 className="h-4 w-4" />
                  Return for correction
                </Button>
                <Button onClick={() => decide('verified')} disabled={busy}>
                  {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                  Verify Bio-Data
                </Button>
              </>
            )
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function display(field, value, options) {
  if (value === null || value === undefined || value === '') return '(blank)'
  if (field === 'sex' || field === 'civil_status') return optionLabel(options[field], value)
  return String(value)
}
