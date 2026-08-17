import * as React from 'react'
import { useNavigate } from 'react-router-dom'
import { Loader2, ScanLine, AlertTriangle, Sparkles, X } from 'lucide-react'
import { get, post, upload } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Field, FormGrid } from '@/components/ui/form'

const EMPTY = {
  source_channel: 'walk_in',
  first_name: '',
  middle_name: '',
  last_name: '',
  sex: '',
  birth_date: '',
  contact_number: '',
  email: '',
  present_address: '',
  preferred_position: '',
  application_date: new Date().toISOString().slice(0, 10),
}

/**
 * Registration form for a new applicant.
 *
 * Only the fields HR can reliably capture at the counter are required. The rest
 * of the profile - education, experience, ratings - is filled in during
 * screening, so demanding it here would block the initial record from being
 * created at all.
 */
export default function NewApplicantDialog({ open, onOpenChange, onCreated }) {
  const [form, setForm] = React.useState(EMPTY)
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()
  const navigate = useNavigate()

  // Document reading state. `scanned` records which fields came from a document
  // and how confident the reading was, so the form can mark them for checking.
  const [scanAvailable, setScanAvailable] = React.useState(false)
  const [scanning, setScanning] = React.useState(false)
  const [scanned, setScanned] = React.useState({})
  const [scanMeta, setScanMeta] = React.useState(null)

  React.useEffect(() => {
    if (open) {
      setForm(EMPTY)
      setErrors({})
      setScanned({})
      setScanMeta(null)

      // Only offer the scan button if the service is actually running, rather
      // than showing a control that fails when pressed.
      get('/document-scan/status')
        .then((response) => setScanAvailable(response.data.available))
        .catch(() => setScanAvailable(false))
    }
  }, [open])

  function set(field, value) {
    setForm((current) => ({ ...current, [field]: value }))

    // Once HR edits a field it is theirs, so the "check this" highlight is
    // cleared. Leaving it up after correction would train people to ignore it.
    setScanned((current) => {
      if (!current[field]) return current
      const next = { ...current }
      delete next[field]
      return next
    })
  }

  async function handleScan(file) {
    if (!file) return

    setScanning(true)
    const formData = new FormData()
    formData.append('file', file)
    formData.append('document_type', 'auto')

    try {
      const response = await upload('/document-scan', formData)

      if (!response.data.readable) {
        toast.error('Could not read the document', response.message)
        return
      }

      const fields = response.data.fields
      const applied = {}
      const flags = {}

      for (const [name, data] of Object.entries(fields)) {
        applied[name] = data.value
        flags[name] = data
      }

      setForm((current) => ({ ...current, ...applied }))
      setScanned(flags)
      setScanMeta(response.data.meta)

      const needsCheck = Object.values(fields).filter((f) => f.needs_review).length
      toast.success(
        `Read ${Object.keys(fields).length} field(s) from the document`,
        needsCheck > 0
          ? `${needsCheck} need checking before you save.`
          : 'Please still check them against the document.'
      )
    } catch (error) {
      toast.error('Could not read the document', error.message)
    } finally {
      setScanning(false)
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setSubmitting(true)

    try {
      // Blank optional fields are stripped rather than sent as empty strings,
      // which would fail the date and email rules on the server.
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))

      const response = await post('/applicants', payload)

      toast.success(
        'Applicant registered',
        `${response.data.full_name} was added as ${response.data.applicant_code}.`
      )

      onOpenChange(false)
      onCreated?.()
      navigate(`/applicants/${response.data.id}`)
    } catch (error) {
      if (error.isValidation) {
        setErrors(error.errors)
        toast.error('Please check the form', 'Some fields need correcting.')
      } else {
        toast.error('Could not register applicant', error.message)
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Register applicant</DialogTitle>
          <DialogDescription>
            Capture the details taken at the counter. The document checklist is created
            automatically, and the rest of the profile can be completed during screening.
          </DialogDescription>
        </DialogHeader>

        {scanAvailable && (
          <ScanPanel
            scanning={scanning}
            onScan={handleScan}
            meta={scanMeta}
            fieldCount={Object.keys(scanned).length}
            onClear={() => {
              setScanned({})
              setScanMeta(null)
            }}
          />
        )}

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <FormGrid>
            <Field
              label="First name"
              htmlFor="first_name"
              required
              error={errors.first_name?.[0]}
              hint={hintFor(scanned.first_name)}
            >
              <Input value={form.first_name} onChange={(e) => set('first_name', e.target.value)} autoFocus />
            </Field>

            <Field
              label="Last name"
              htmlFor="last_name"
              required
              error={errors.last_name?.[0]}
              hint={hintFor(scanned.last_name)}
            >
              <Input value={form.last_name} onChange={(e) => set('last_name', e.target.value)} />
            </Field>

            <Field
              label="Middle name"
              htmlFor="middle_name"
              error={errors.middle_name?.[0]}
              hint={hintFor(scanned.middle_name)}
            >
              <Input value={form.middle_name} onChange={(e) => set('middle_name', e.target.value)} />
            </Field>

            <Field label="Sex" htmlFor="sex" error={errors.sex?.[0]}>
              <Select value={form.sex} onChange={(e) => set('sex', e.target.value)} placeholder="Not specified">
                <option value="female">Female</option>
                <option value="male">Male</option>
              </Select>
            </Field>

            <Field
              label="Date of birth"
              htmlFor="birth_date"
              error={errors.birth_date?.[0]}
              hint="Used for the age criterion during matching"
            >
              <Input type="date" value={form.birth_date} onChange={(e) => set('birth_date', e.target.value)} />
            </Field>

            <Field label="How did they apply?" htmlFor="source_channel" required error={errors.source_channel?.[0]}>
              <Select value={form.source_channel} onChange={(e) => set('source_channel', e.target.value)}>
                <option value="walk_in">Walk-in</option>
                <option value="messenger">Messenger</option>
                <option value="email">Email</option>
              </Select>
            </Field>

            <Field label="Contact number" htmlFor="contact_number" error={errors.contact_number?.[0]}>
              <Input value={form.contact_number} onChange={(e) => set('contact_number', e.target.value)} placeholder="09XX XXX XXXX" />
            </Field>

            <Field label="Email address" htmlFor="email" error={errors.email?.[0]}>
              <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
            </Field>
          </FormGrid>

          <Field
            label="Present address"
            htmlFor="present_address"
            required
            error={errors.present_address?.[0]}
            hint={hintFor(scanned.present_address) ?? 'Required so distance from the worksite can be scored'}
          >
            <Input value={form.present_address} onChange={(e) => set('present_address', e.target.value)} />
          </Field>

          <FormGrid>
            <Field label="Position sought" htmlFor="preferred_position" error={errors.preferred_position?.[0]}>
              <Input value={form.preferred_position} onChange={(e) => set('preferred_position', e.target.value)} />
            </Field>

            <Field label="Application date" htmlFor="application_date" required error={errors.application_date?.[0]}>
              <Input
                type="date"
                value={form.application_date}
                onChange={(e) => set('application_date', e.target.value)}
                max={new Date().toISOString().slice(0, 10)}
              />
            </Field>
          </FormGrid>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              {submitting ? 'Registering…' : 'Register applicant'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/**
 * Upload panel for reading a document.
 *
 * Positioned above the form rather than beside a single field, because one scan
 * fills several fields at once and the user should understand that before values
 * start appearing.
 */
function ScanPanel({ scanning, onScan, meta, fieldCount, onClear }) {
  return (
    <div className="rounded-md border border-primary/30 bg-primary/[0.04] p-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-start gap-2.5">
          <Sparkles className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
          <div>
            <p className="text-sm font-medium">Read from a document</p>
            <p className="text-xs text-muted-foreground">
              Upload the applicant&apos;s resume or ID to fill the form automatically. Every value
              still needs checking.
            </p>
          </div>
        </div>

        {fieldCount > 0 ? (
          <button
            type="button"
            onClick={onClear}
            className="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
          >
            <X className="h-3.5 w-3.5" />
            Clear highlights
          </button>
        ) : (
          <label className="cursor-pointer">
            <input
              type="file"
              className="sr-only"
              accept=".pdf,.jpg,.jpeg,.png,.webp"
              disabled={scanning}
              onChange={(e) => onScan(e.target.files?.[0])}
            />
            <span className="inline-flex h-8 items-center gap-1.5 rounded-md border bg-card px-3 text-xs font-medium hover:bg-accent">
              {scanning ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ScanLine className="h-3.5 w-3.5" />}
              {scanning ? 'Reading…' : 'Upload document'}
            </span>
          </label>
        )}
      </div>

      {scanning && (
        <p className="mt-2 text-xs text-muted-foreground">
          This can take up to a minute the first time, while the reader starts up.
        </p>
      )}

      {meta?.low_resolution && (
        <div className="mt-2 flex items-start gap-2 rounded border border-warning/40 bg-warning/5 p-2">
          <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
          <p className="text-xs text-muted-foreground">
            The image was low resolution, so the reading may be unreliable. A clearer photograph
            usually gives much better results.
          </p>
        </div>
      )}

      {fieldCount > 0 && (
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <Badge tone="info">{fieldCount} field(s) filled from the document</Badge>
          {meta?.recognition_confidence != null && (
            <span className="text-xs text-muted-foreground">
              Text clarity {Math.round(meta.recognition_confidence * 100)}%
            </span>
          )}
        </div>
      )}
    </div>
  )
}

/**
 * Hint shown under a field that was filled from a document.
 *
 * Low-confidence values say so explicitly. Names and addresses land there almost
 * every time, which is intended — they are exactly the values worth checking
 * against the original before saving.
 */
function hintFor(scan) {
  if (!scan) return undefined

  return scan.needs_review
    ? 'Read from the document — please check this against the original'
    : 'Read from the document'
}
