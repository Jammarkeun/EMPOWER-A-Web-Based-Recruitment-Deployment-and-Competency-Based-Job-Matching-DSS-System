import * as React from 'react'
import {
  CheckCircle2,
  XCircle,
  Clock,
  AlertCircle,
  Download,
  Upload,
  Loader2,
  ScanLine,
  Info,
  UploadCloud,
  Eye,
} from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { get, upload as uploadFile } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
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
import { cn, formatDate } from '@/lib/utils'

/**
 * How each state of a document is described to the person who sent it.
 *
 * Keyed on `review_state`, which the API derives from the officer's decision and
 * from whether anyone has actually opened the file. The page used to key on
 * `status` alone and so called a document "being checked" the moment the upload
 * finished — when in truth it had been received and nothing more. An applicant
 * had no way to tell a document nobody had looked at from one genuinely under
 * review, and the difference is most of what they want to know.
 *
 * Every state is also written out in words rather than carried by colour, which
 * a red/green pairing alone would fail to do for the most common forms of colour
 * blindness.
 */
const REVIEW_META = {
  not_uploaded: {
    icon: AlertCircle,
    tone: 'muted',
    detail: 'Bring the original to the office, or send a copy now.',
  },
  uploaded: {
    icon: UploadCloud,
    tone: 'info',
    detail: 'We have your copy. Our staff will check it shortly.',
  },
  under_review: {
    icon: Eye,
    tone: 'warning',
    detail: 'Our staff have opened this and are checking it.',
  },
  verified: {
    icon: CheckCircle2,
    tone: 'success',
    detail: 'Checked and accepted. Nothing further needed.',
  },
  rejected: {
    icon: XCircle,
    tone: 'destructive',
    detail: 'Please send this again.',
  },
  needs_correction: {
    icon: AlertCircle,
    tone: 'warning',
    detail: 'Something needs fixing before this can be accepted.',
  },
  expired: {
    icon: Clock,
    tone: 'destructive',
    detail: 'This document has passed its expiry date.',
  },
}

function metaFor(requirement) {
  return REVIEW_META[requirement.review_state] ?? REVIEW_META.not_uploaded
}

/** A verified document is closed to the applicant; everything else can be sent. */
function canSend(requirement) {
  return requirement.status !== 'verified'
}

const TONE_TEXT = {
  success: 'text-success',
  destructive: 'text-destructive',
  warning: 'text-warning',
  info: 'text-primary',
  muted: 'text-muted-foreground',
}

export default function PortalDocuments() {
  const { data, loading, error, refetch, setData } = useApi('/portal/documents')
  const toast = useToast()
  const [sending, setSending] = React.useState(null)

  /*
   * The document most recently changed, briefly highlighted.
   *
   * Rows update in place rather than the page reloading, which is what keeps
   * the reader where they were — but it also means nothing visibly happens to
   * confirm the change landed. A short highlight is the acknowledgement the
   * reload used to provide by accident.
   */
  const [justChanged, setJustChanged] = React.useState(null)

  const [openingId, setOpeningId] = React.useState(null)

  React.useEffect(() => {
    if (!justChanged) return

    const timer = setTimeout(() => setJustChanged(null), 2500)
    return () => clearTimeout(timer)
  }, [justChanged])

  if (loading) return <LoadingState label="Loading your documents…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  /**
   * Replaces one requirement without touching the rest of the page.
   *
   * This is the whole fix for the scroll problem. Refetching after an upload
   * replaced the page with a spinner, the document lost its height, the browser
   * pinned the scroll to the top, and an applicant working through eighteen
   * requirements had to scroll back down every single time.
   */
  function replaceRequirement(updated) {
    setData((current) => ({
      ...current,
      requirements: current.requirements.map((row) =>
        row.requirement_type_id === updated.requirement_type_id ? { ...row, ...updated } : row
      ),
    }))
    setJustChanged(updated.requirement_type_id)
  }

  async function openDocument(requirement) {
    setOpeningId(requirement.requirement_type_id)

    try {
      const response = await get(`/portal/documents/${requirement.requirement_type_id}/download`)
      const opened = window.open(response.data.url, '_blank', 'noopener')

      // Nothing is thrown when a popup blocker steps in, so without this the
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
      setOpeningId(null)
    }
  }

  const groups = { primary: [], final: [] }
  for (const requirement of data.requirements) {
    groups[requirement.requirement_group]?.push(requirement)
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">My documents</h1>
        <p className="text-sm text-muted-foreground">
          Send your documents ahead of time so the office can check them before you visit.
        </p>
      </div>

      {/*
        Said once, plainly, at the top. Sending a scan is genuinely useful but it
        is not the same as submitting the document, and an applicant who believes
        otherwise stops turning up — which is the one outcome this page must not
        cause.
      */}
      <Card className="border-primary/30 bg-primary/[0.04]">
        <CardContent className="flex gap-3 pt-5">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
          <div className="space-y-1 text-sm">
            <p className="font-medium">Sending a copy does not replace your office visit</p>
            <p className="text-muted-foreground">
              Our staff still need to see the original document and check it against your ID.
              Sending it here first means any problem is found before you travel.
            </p>
          </div>
        </CardContent>
      </Card>

      <Card
        className={
          data.folder.is_deployment_ready
            ? 'border-success/40 bg-success/5'
            : 'border-warning/40 bg-warning/5'
        }
      >
        <CardContent className="pt-5">
          <p className="text-sm font-medium">{data.folder.label}</p>
          <p className="mt-0.5 text-sm text-muted-foreground">{data.folder.reason}</p>
        </CardContent>
      </Card>

      {['primary', 'final'].map((group) => (
        <Card key={group}>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">
              {group === 'primary' ? 'Main requirements' : 'Medical requirements'}
            </CardTitle>
            <CardDescription>
              {group === 'primary'
                ? 'Collected when you first apply.'
                : 'Needed once you are close to being placed. You do not need these yet.'}
            </CardDescription>
          </CardHeader>
          <CardContent className="divide-y">
            {groups[group].map((requirement) => (
              <DocumentRow
                key={requirement.id ?? requirement.requirement_type_id}
                requirement={requirement}
                highlighted={justChanged === requirement.requirement_type_id}
                opening={openingId === requirement.requirement_type_id}
                onOpen={() => openDocument(requirement)}
                onSend={() => setSending(requirement)}
              />
            ))}
          </CardContent>
        </Card>
      ))}

      <SendDocumentDialog
        requirement={sending}
        onOpenChange={(open) => !open && setSending(null)}
        onSent={(updated) => {
          setSending(null)
          replaceRequirement(updated)
        }}
      />
    </div>
  )
}

/**
 * One document, with its state said in words.
 *
 * The state and the detail line are both shown because the badge alone is a
 * label, not an explanation: "Upload successful" tells the applicant what
 * happened, and the line beneath tells them what happens next, which is the
 * question that otherwise becomes a phone call to the office.
 */
function DocumentRow({ requirement, highlighted, opening, onOpen, onSend }) {
  const meta = metaFor(requirement)
  const Icon = meta.icon

  return (
    <div
      className={cn(
        'flex flex-wrap items-center gap-3 rounded-md py-3 transition-colors duration-500',
        highlighted && 'bg-success/10 px-3'
      )}
    >
      <Icon className={cn('h-4 w-4 shrink-0', TONE_TEXT[meta.tone])} />

      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium">{requirement.requirement_name}</p>
        {requirement.rejection_reason ? (
          <p className="text-xs text-destructive">{requirement.rejection_reason}</p>
        ) : (
          <p className="text-xs text-muted-foreground">
            {meta.detail}
            {requirement.expiry_date && requirement.review_state === 'verified'
              ? ` Valid until ${formatDate(requirement.expiry_date)}.`
              : ''}
          </p>
        )}
      </div>

      <Badge tone={meta.tone}>{requirement.review_state_label}</Badge>

      {requirement.has_file && (
        <Button variant="ghost" size="sm" onClick={onOpen} disabled={opening}>
          {opening ? (
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
          ) : (
            <Download className="h-3.5 w-3.5" />
          )}
          {opening ? 'Opening…' : 'View'}
        </Button>
      )}

      {canSend(requirement) && (
        <Button variant="outline" size="sm" onClick={onSend}>
          <Upload className="h-3.5 w-3.5" />
          {requirement.has_file ? 'Replace' : 'Send'}
        </Button>
      )}
    </div>
  )
}

/**
 * Sending one document.
 *
 * The readability check is offered before sending, not after. Its whole value is
 * catching a photo too blurred to read while the applicant still has the
 * document in front of them — telling them afterwards would just mean doing it
 * twice.
 *
 * The work happens inside the dialog rather than over the page, which is what
 * keeps the reader's place in a long checklist: the page behind never unmounts,
 * so there is no scroll position to lose and none to restore.
 */
function SendDocumentDialog({ requirement, onOpenChange, onSent }) {
  const toast = useToast()
  const [file, setFile] = React.useState(null)
  const [expiry, setExpiry] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [busy, setBusy] = React.useState(false)
  const [checking, setChecking] = React.useState(false)
  const [check, setCheck] = React.useState(null)

  React.useEffect(() => {
    setFile(null)
    setExpiry('')
    setErrors({})
    setCheck(null)
  }, [requirement?.id])

  if (!requirement) return null

  const needsExpiry = requirement.has_expiry

  async function runCheck() {
    if (!file || checking) return

    setChecking(true)
    setCheck(null)

    const body = new FormData()
    body.append('file', file)

    try {
      const response = await uploadFile('/portal/documents/check-readable', body)
      setCheck(response.data)

      if (response.data.readable) {
        toast.success('That looks clear', response.message)
      } else {
        toast.warning('Hard to read', response.message)
      }
    } catch (error) {
      toast.error('Could not check the document', error.message)
    } finally {
      setChecking(false)
    }
  }

  async function handleSend() {
    // Guards the double-tap, which on a slow connection is the most ordinary
    // way to send the same document twice.
    if (busy) return

    if (!file) {
      setErrors({ file: ['Choose a photo or PDF of your document.'] })
      return
    }

    setBusy(true)
    setErrors({})

    const body = new FormData()
    body.append('file', file)
    if (expiry) body.append('expiry_date', expiry)

    try {
      const response = await uploadFile(
        `/portal/documents/${requirement.requirement_type_id}/upload`,
        body
      )

      // "Upload successful" and nothing stronger. The office has received the
      // file; nobody has looked at it yet, and saying otherwise would be a
      // guess dressed as a fact.
      toast.success('Upload successful', response.message)

      // The updated row comes back with the response, so the page can be
      // corrected without asking the server a second time.
      onSent(response.data)
    } catch (error) {
      if (error.isValidation) {
        setErrors(error.errors)
        toast.error('Check the form', 'Some details need correcting.')
      } else {
        toast.error(
          error.isConflict ? 'Already accepted' : 'Upload failed — please try again',
          error.message
        )
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={!!requirement} onOpenChange={(open) => !busy && onOpenChange(open)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Send {requirement.requirement_name}</DialogTitle>
          <DialogDescription>
            A clear photo or a PDF. Our staff will check it against your original when you visit.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <Field
            label="Photo or PDF"
            htmlFor="document_file"
            required
            error={errors.file?.[0]}
            hint="Up to 10 MB. JPG, PNG, WEBP, or PDF."
          >
            <Input
              id="document_file"
              type="file"
              accept=".pdf,.jpg,.jpeg,.png,.webp"
              disabled={busy}
              onChange={(e) => {
                setFile(e.target.files?.[0] ?? null)
                setCheck(null)
              }}
              className="cursor-pointer file:mr-3 file:cursor-pointer file:rounded file:border-0 file:bg-secondary file:px-2 file:py-1 file:text-xs file:font-medium"
            />
          </Field>

          {needsExpiry && (
            <Field
              label="Expiry date"
              htmlFor="expiry_date"
              required
              error={errors.expiry_date?.[0]}
              hint="The date printed on the document. This one expires, so we need it."
            >
              <Input
                id="expiry_date"
                type="date"
                value={expiry}
                disabled={busy}
                onChange={(e) => setExpiry(e.target.value)}
              />
            </Field>
          )}

          {/* Optional and clearly marked as slow, so the wait is a choice. */}
          {file && (
            <div className="rounded-lg border bg-muted/30 p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                  <p className="text-sm font-medium">Check it can be read</p>
                  <p className="text-xs text-muted-foreground">
                    Optional. Takes up to a minute and nothing is saved.
                  </p>
                </div>
                <Button variant="outline" size="sm" onClick={runCheck} disabled={checking || busy}>
                  {checking ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <ScanLine className="h-3.5 w-3.5" />
                  )}
                  {checking ? 'Reading…' : 'Check'}
                </Button>
              </div>

              {check && (
                <div className="mt-3 border-t pt-3">
                  {check.readable ? (
                    <>
                      <p className="flex items-center gap-1.5 text-sm text-success">
                        <CheckCircle2 className="h-3.5 w-3.5" />
                        Clear enough to read
                      </p>
                      {check.found?.length > 0 && (
                        <>
                          <p className="mt-2 text-xs text-muted-foreground">
                            We could make out these details. Check they match your document — if
                            anything is wrong, the photo may still be worth retaking.
                          </p>
                          <ul className="mt-1.5 space-y-0.5">
                            {check.found.map((item) => (
                              <li key={item.label} className="text-xs">
                                <span className="text-muted-foreground">{item.label}: </span>
                                <span className="font-medium">{item.value}</span>
                              </li>
                            ))}
                          </ul>
                        </>
                      )}
                    </>
                  ) : (
                    <p className="flex items-start gap-1.5 text-sm text-warning">
                      <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                      Hard to read. Try again in better light with the whole document in frame — you
                      can still send it either way.
                    </p>
                  )}
                </div>
              )}
            </div>
          )}
        </div>

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={busy}>
            Cancel
          </Button>
          <Button onClick={handleSend} disabled={busy || checking}>
            {busy && <Loader2 className="h-4 w-4 animate-spin" />}
            {busy ? 'Uploading…' : 'Send document'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
