import * as React from 'react'
import { Link } from 'react-router-dom'
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  Download,
  Eye,
  FileText,
  Info,
  Loader2,
  Lock,
  Plus,
  Printer,
  Save,
  Send,
  Trash2,
} from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useFormDraft } from '@/hooks/useFormDraft'
import { useLeavePageWarning } from '@/components/UnsavedChanges'
import { post, put } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
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
import { cn, formatDate, formatDateTime } from '@/lib/utils'
import {
  BIO_DATA_STATES,
  BIO_DATA_STEPS,
  BLANK_EDUCATION,
  BLANK_EXPERIENCE,
  downloadBioDataPdf,
  stepOfField,
  toFormState,
} from '@/lib/bioData'

/**
 * The applicant's digital Bio-Data.
 *
 * Filled in here instead of uploaded as a separate file (the adviser's
 * recommendation). Saving keeps a draft only the applicant sees; nothing
 * reaches the office until they review it and send it, and even then it is
 * "submitted", never "verified" - only HR verifies.
 *
 * What the office already checked (name, birth date, address) and the
 * contact details kept under Profile are shown, not asked again.
 */
export default function PortalBioData() {
  const { data, loading, error, refetch, setData } = useApi('/portal/bio-data')

  if (loading) return <LoadingState label="Loading your Bio-Data…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const state = BIO_DATA_STATES[data.state] ?? BIO_DATA_STATES.not_started

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-[26px] font-normal leading-tight tracking-[-0.01em] sm:text-[32px]">My Bio-Data</h1>
          <p className="text-sm text-muted-foreground">
            Fill in your Bio-Data here instead of preparing and uploading a separate copy.
          </p>
        </div>
        <Badge tone={state.tone}>{state.label}</Badge>
      </div>

      <StatusCard data={data} />

      {data.can_edit ? (
        <BioDataEditor data={data} onChanged={setData} />
      ) : (
        <SubmittedView data={data} />
      )}

      {data.versions.length > 0 && <HistoryCard versions={data.versions} />}
    </div>
  )
}

const STATUS_TONES = {
  primary: { card: 'border-primary/30 bg-primary/[0.04]', icon: 'text-primary' },
  warning: { card: 'border-warning/40 bg-warning/5', icon: 'text-warning' },
  success: { card: 'border-success/40 bg-success/5', icon: 'text-success' },
  destructive: { card: 'border-destructive/40 bg-destructive/5', icon: 'text-destructive' },
}

/** Where things stand, in one plain sentence, with what to do next. */
function StatusCard({ data }) {
  const latest = data.versions[0]

  const message = {
    not_started: {
      icon: Info,
      tone: 'primary',
      title: 'Not started',
      body: 'We have filled in what the office already has on record. Check it, complete the rest, and send it when you are ready.',
    },
    draft: {
      icon: Save,
      tone: 'primary',
      title: 'Draft saved — not sent yet',
      body: data.draft_saved_at
        ? `Last saved ${formatDateTime(data.draft_saved_at)}. The office cannot see a draft.`
        : 'The office cannot see a draft.',
    },
    submitted: {
      icon: Send,
      tone: 'primary',
      title: 'Sent to the office',
      body: latest
        ? `Sent ${formatDateTime(latest.submitted_at)}. Our staff have not opened it yet.`
        : 'Your Bio-Data is with the office.',
    },
    under_review: {
      icon: Eye,
      tone: 'warning',
      title: 'Being reviewed',
      body: 'Our staff have opened your Bio-Data and are checking it.',
    },
    verified: {
      icon: CheckCircle2,
      tone: 'success',
      title: 'Accepted by the office',
      body: 'Your Bio-Data requirement is complete. This does not complete any of your other documents.',
    },
    needs_correction: {
      icon: AlertCircle,
      tone: 'destructive',
      title: 'The office asked for a correction',
      body: latest?.review_note ?? 'Please check your Bio-Data and send it again.',
    },
  }[data.state] ?? null

  if (!message) return null

  const Icon = message.icon
  const tone = STATUS_TONES[message.tone]

  return (
    <Card className={tone.card}>
      <CardContent className="space-y-2 pt-5">
        <div className="flex gap-3">
          <Icon className={cn('mt-0.5 h-4 w-4 shrink-0', tone.icon)} />
          <div className="space-y-1 text-sm">
            <p className="font-medium">{message.title}</p>
            <p className="text-muted-foreground">{message.body}</p>
            {data.lock_reason && data.state !== 'verified' && (
              <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                <Lock className="mt-0.5 h-3 w-3 shrink-0" />
                {data.lock_reason}
              </p>
            )}
          </div>
        </div>

        {/* Accepted before the form existed: still complete, form still asked for. */}
        {data.earlier_verification && (
          <p className="flex items-start gap-2 border-t pt-2 text-xs text-muted-foreground">
            <FileText className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            Your Bio-Data requirement is already complete from the copy the office accepted earlier, and it stays
            complete while the office reviews this form. Please fill in the form so your details are on record.
          </p>
        )}

        {/* Not decided by CDE yet; shown only if the office has switched it on. */}
        {data.signed_copy_required && (
          <p className="flex items-start gap-2 border-t pt-2 text-xs text-muted-foreground">
            <Printer className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            The office also needs a printed copy signed by you. Download the PDF once you have sent the form,
            sign it, and bring it on your visit.
          </p>
        )}
      </CardContent>
    </Card>
  )
}

function PdfButton({ draft = false, label = 'Download PDF' }) {
  const toast = useToast()
  const [busy, setBusy] = React.useState(false)

  async function handleClick() {
    if (busy) return
    setBusy(true)
    try {
      await downloadBioDataPdf(`/portal/bio-data/pdf${draft ? '?draft=1' : ''}`)
    } catch (err) {
      toast.error('Could not create the PDF', err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Button variant="outline" size="sm" onClick={handleClick} disabled={busy}>
      {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Download className="h-3.5 w-3.5" />}
      {busy ? 'Preparing…' : label}
    </Button>
  )
}

/** What was sent, while it cannot be changed. */
function SubmittedView({ data }) {
  const form = data.submitted ?? data.form

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <CardTitle className="text-base">{data.submitted ? 'What you sent' : 'Your Bio-Data'}</CardTitle>
            <CardDescription>
              {data.versions[0]
                ? `Version ${data.versions[0].version_number}, sent ${formatDate(data.versions[0].submitted_at)}.`
                : 'As the office has it on record.'}
            </CardDescription>
          </div>
          {data.versions.length > 0 && <PdfButton />}
        </div>
      </CardHeader>
      <CardContent>
        <BioDataSummary profile={data.profile} form={form} options={data.options} />
      </CardContent>
    </Card>
  )
}

function HistoryCard({ versions }) {
  const outcome = {
    verified: { label: 'Verified', tone: 'success' },
    needs_correction: { label: 'Returned for correction', tone: 'warning' },
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">History</CardTitle>
        <CardDescription>Every time you sent your Bio-Data, and what the office decided.</CardDescription>
      </CardHeader>
      <CardContent className="divide-y">
        {versions.map((v) => (
          <div key={v.version_number} className="flex flex-wrap items-start justify-between gap-2 py-2.5 text-sm">
            <div className="min-w-0">
              <p className="font-medium">Version {v.version_number}</p>
              <p className="text-xs text-muted-foreground">
                Sent {formatDateTime(v.submitted_at)}
                {v.reviewed_at && ` · reviewed ${formatDateTime(v.reviewed_at)}`}
              </p>
              {v.review_note && <p className="mt-0.5 text-xs text-destructive">{v.review_note}</p>}
            </div>
            <Badge tone={outcome[v.review_outcome]?.tone ?? 'info'}>
              {outcome[v.review_outcome]?.label ?? 'Waiting for the office'}
            </Badge>
          </div>
        ))}
      </CardContent>
    </Card>
  )
}

// ----------------------------------------------------------------- the form

/** Empty strings back to nulls, the shape the server stores. */
function toPayload(form) {
  const clean = (row) => Object.fromEntries(Object.entries(row).map(([k, v]) => [k, v === '' ? null : v]))
  const { educations, experiences, ...rest } = form

  return {
    ...clean(rest),
    educations: educations.map(clean),
    experiences: experiences.map(clean),
  }
}

/** Server errors arrive as { "experiences.0.end_date": ["..."] }; one message each. */
function firstErrors(errors) {
  return Object.fromEntries(Object.entries(errors ?? {}).map(([key, messages]) => [key, messages?.[0] ?? messages]))
}

function BioDataEditor({ data, onChanged }) {
  const toast = useToast()
  const { form, setForm, dirty, markSaved } = useFormDraft(data.form, toFormState)
  const [step, setStep] = React.useState(0)
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(false)
  const [confirming, setConfirming] = React.useState(false)
  const topRef = React.useRef(null)

  useLeavePageWarning(dirty)

  const missingByStep = React.useMemo(() => {
    const counts = BIO_DATA_STEPS.map(() => 0)
    Object.keys(data.missing ?? {}).forEach((key) => {
      counts[stepOfField(key)] += 1
    })
    return counts
  }, [data.missing])

  function goTo(index) {
    setStep(index)
    topRef.current?.scrollIntoView({ block: 'start', behavior: 'smooth' })
  }

  function set(field, value) {
    setForm((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field]: undefined }))
  }

  function setRow(list, index, field, value) {
    setForm((current) => ({
      ...current,
      [list]: current[list].map((row, i) => (i === index ? { ...row, [field]: value } : row)),
    }))
    setErrors((current) => ({ ...current, [`${list}.${index}.${field}`]: undefined }))
  }

  function addRow(list, blank) {
    setForm((current) => ({ ...current, [list]: [...current[list], { ...blank }] }))
  }

  function removeRow(list, index) {
    setForm((current) => ({ ...current, [list]: current[list].filter((_, i) => i !== index) }))
    setErrors({})
  }

  /** Saves the draft. Returns whether it saved, so Next and Review can wait on it. */
  async function save({ quiet = false } = {}) {
    if (saving) return false
    setSaving(true)

    try {
      const response = await put('/portal/bio-data/draft', toPayload(form))
      markSaved()
      onChanged(response.data)
      setErrors({})
      if (!quiet) toast.success('Draft saved', response.message)
      return true
    } catch (err) {
      if (err.isValidation) {
        setErrors(firstErrors(err.errors))
        const first = Object.keys(err.errors)[0]
        if (first) goTo(stepOfField(first))
        toast.error('Check the form', 'Some details need correcting before they can be saved.')
      } else {
        toast.error('Could not save your draft', err.message)
      }
      return false
    } finally {
      setSaving(false)
    }
  }

  async function next() {
    if (await save({ quiet: true })) goTo(Math.min(step + 1, BIO_DATA_STEPS.length - 1))
  }

  async function submit() {
    setSaving(true)
    try {
      const response = await post('/portal/bio-data/submit', { confirm: true })
      setConfirming(false)
      onChanged(response.data)
      toast.success('Bio-Data sent', response.message)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch (err) {
      setConfirming(false)
      if (err.isValidation) {
        setErrors(firstErrors(err.errors))
        const first = Object.keys(err.errors)[0]
        if (first && first !== 'confirm') goTo(stepOfField(first))
        toast.error('Not sent yet', 'Some details still need to be completed.')
      } else {
        toast.error('Could not send your Bio-Data', err.message)
      }
    } finally {
      setSaving(false)
    }
  }

  const isReview = step === BIO_DATA_STEPS.length - 1
  const missing = Object.entries(data.missing ?? {})

  return (
    <Card>
      <div ref={topRef} className="scroll-mt-24" />
      <CardHeader className="pb-3">
        <Stepper step={step} missingByStep={missingByStep} onStep={async (index) => {
          // Moving between steps keeps what was typed: save it first.
          if (!dirty || (await save({ quiet: true }))) goTo(index)
        }} />
      </CardHeader>

      <CardContent className="space-y-5">
        {step === 0 && <PersonalStep form={form} data={data} errors={errors} set={set} />}
        {step === 1 && <AddressStep form={form} data={data} errors={errors} set={set} />}
        {step === 2 && (
          <RowsStep
            title="Schools you attended"
            hint="Add at least one. Start with the highest level you reached."
            list="educations"
            rows={form.educations}
            blank={BLANK_EDUCATION}
            errors={errors}
            onAdd={addRow}
            onRemove={removeRow}
            addLabel="Add a school"
            emptyText="No schools added yet."
            renderRow={(row, index) => (
              <EducationFields row={row} index={index} options={data.options} errors={errors} setRow={setRow} />
            )}
          />
        )}
        {step === 3 && (
          <RowsStep
            title="Previous jobs"
            hint="Optional. Leave this empty if this will be your first job."
            list="experiences"
            rows={form.experiences}
            blank={BLANK_EXPERIENCE}
            errors={errors}
            onAdd={addRow}
            onRemove={removeRow}
            addLabel="Add a job"
            emptyText="No previous jobs added."
            renderRow={(row, index) => <ExperienceFields row={row} index={index} errors={errors} setRow={setRow} />}
          />
        )}
        {isReview && (
          <ReviewStep data={data} dirty={dirty} missing={missing} onFix={(key) => goTo(stepOfField(key))} />
        )}

        <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-4">
          <Button variant="ghost" onClick={() => goTo(step - 1)} disabled={step === 0 || saving}>
            <ArrowLeft className="h-4 w-4" />
            Back
          </Button>

          <div className="flex flex-wrap items-center gap-2">
            {!isReview && (
              <Button variant="outline" onClick={() => save()} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                Save draft
              </Button>
            )}

            {isReview ? (
              <Button onClick={() => setConfirming(true)} disabled={saving || dirty || missing.length > 0}>
                <Send className="h-4 w-4" />
                Submit Bio-Data
              </Button>
            ) : (
              <Button onClick={next} disabled={saving}>
                {step === BIO_DATA_STEPS.length - 2 ? 'Save and review' : 'Save and continue'}
                <ArrowRight className="h-4 w-4" />
              </Button>
            )}
          </div>
        </div>
      </CardContent>

      <ConfirmSubmitDialog open={confirming} busy={saving} onOpenChange={setConfirming} onConfirm={submit} />
    </Card>
  )
}

/** Where the applicant is, and which steps still have something missing. */
function Stepper({ step, missingByStep, onStep }) {
  return (
    <nav aria-label="Bio-Data steps">
      <p className="mb-2 text-xs text-muted-foreground">
        Step {step + 1} of {BIO_DATA_STEPS.length}: <span className="font-medium text-foreground">{BIO_DATA_STEPS[step].label}</span>
      </p>
      <ol className="grid grid-cols-5 gap-1.5">
        {BIO_DATA_STEPS.map((s, index) => {
          const incomplete = missingByStep[index] > 0
          return (
            <li key={s.key}>
              <button
                type="button"
                onClick={() => onStep(index)}
                aria-current={index === step ? 'step' : undefined}
                title={incomplete ? `${s.label}: ${missingByStep[index]} still to complete` : s.label}
                className={cn(
                  'h-1.5 w-full rounded-full transition-colors',
                  index === step ? 'bg-brand' : incomplete ? 'bg-warning/60' : 'bg-success/60'
                )}
              >
                <span className="sr-only">
                  {s.label}
                  {incomplete ? ` (${missingByStep[index]} still to complete)` : ''}
                </span>
              </button>
            </li>
          )
        })}
      </ol>
    </nav>
  )
}

function ReadOnlyFacts({ rows, note }) {
  return (
    <div className="space-y-2 rounded-lg border bg-muted/30 p-3">
      <dl className="grid gap-3 sm:grid-cols-2">
        {rows.map(([label, value]) => (
          <div key={label}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className={cn('text-sm', value ? 'font-medium' : 'text-muted-foreground')}>{value || 'Not on record'}</dd>
          </div>
        ))}
      </dl>
      <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
        <Lock className="mt-0.5 h-3 w-3 shrink-0" />
        {note}
      </p>
    </div>
  )
}

function PersonalStep({ form, data, errors, set }) {
  const p = data.profile
  const name = [p.first_name, p.middle_name, p.last_name, p.suffix].filter(Boolean).join(' ')

  return (
    <div className="space-y-4">
      <ReadOnlyFacts
        rows={[
          ['Name', name],
          ['Date of birth', p.birth_date ? `${formatDate(p.birth_date)}${p.age ? ` (${p.age} years)` : ''}` : null],
        ]}
        note="Checked against your documents at the office. Ask our staff if either needs correcting."
      />

      <fieldset className="space-y-1.5">
        <legend className="text-sm font-medium">
          Sex <span className="text-destructive">*</span>
        </legend>
        <div className="flex flex-wrap gap-2" role="radiogroup">
          {data.options.sex.map((option) => (
            <label
              key={option.value}
              className={cn(
                'flex cursor-pointer items-center gap-2 rounded-control border px-4 py-2.5 text-sm',
                form.sex === option.value ? 'border-brand bg-brand/5' : 'border-border'
              )}
            >
              <input
                type="radio"
                name="sex"
                value={option.value}
                checked={form.sex === option.value}
                onChange={() => set('sex', option.value)}
                className="accent-primary"
              />
              {option.label}
            </label>
          ))}
        </div>
        {errors.sex && <p className="text-xs font-medium text-destructive">{errors.sex}</p>}
      </fieldset>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Civil status" htmlFor="civil_status" required error={errors.civil_status}>
          <Select value={form.civil_status} onChange={(e) => set('civil_status', e.target.value)} placeholder="Choose one">
            {data.options.civil_status.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Citizenship" htmlFor="nationality" required error={errors.nationality} hint="For example, Filipino.">
          <Input value={form.nationality} maxLength={80} onChange={(e) => set('nationality', e.target.value)} />
        </Field>

        <Field label="Height (cm)" htmlFor="height_cm" error={errors.height_cm} hint="Optional. In centimetres, e.g. 160.">
          <Input type="number" inputMode="decimal" min={100} max={250} step="0.01" value={form.height_cm} onChange={(e) => set('height_cm', e.target.value)} />
        </Field>

        <Field label="Weight (kg)" htmlFor="weight_kg" error={errors.weight_kg} hint="Optional. In kilograms, e.g. 55.">
          <Input type="number" inputMode="decimal" min={30} max={300} step="0.01" value={form.weight_kg} onChange={(e) => set('weight_kg', e.target.value)} />
        </Field>
      </div>
    </div>
  )
}

function AddressStep({ form, data, errors, set }) {
  const p = data.profile

  return (
    <div className="space-y-4">
      <ReadOnlyFacts
        rows={[
          ['Present address', p.present_address],
          ['Contact number', p.contact_number],
          ['Email address', p.email],
        ]}
        note="Your present address was checked at the office. Change your contact number or email under Profile."
      />

      <p className="text-xs">
        <Link to="/portal/profile" className="text-primary underline-offset-4 hover:underline">
          Update your contact details in Profile
        </Link>
      </p>

      <Field
        label="Provincial address"
        htmlFor="provincial_address"
        error={errors.provincial_address}
        hint="Optional. Your home address in the province, if different from where you live now."
      >
        <Input value={form.provincial_address} maxLength={255} onChange={(e) => set('provincial_address', e.target.value)} />
      </Field>
    </div>
  )
}

function RowsStep({ title, hint, list, rows, blank, errors, onAdd, onRemove, addLabel, emptyText, renderRow }) {
  return (
    <div className="space-y-3">
      <div>
        <p className="text-sm font-medium">{title}</p>
        <p className="text-xs text-muted-foreground">{hint}</p>
        {errors[list] && <p className="mt-1 text-xs font-medium text-destructive">{errors[list]}</p>}
      </div>

      {rows.length === 0 && <p className="rounded-md border border-dashed px-3 py-4 text-center text-sm text-muted-foreground">{emptyText}</p>}

      {rows.map((row, index) => (
        <div key={index} className="space-y-3 rounded-lg border p-3">
          <div className="flex items-center justify-between">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              {list === 'educations' ? 'School' : 'Job'} {index + 1}
            </p>
            <Button variant="ghost" size="sm" onClick={() => onRemove(list, index)} aria-label={`Remove entry ${index + 1}`}>
              <Trash2 className="h-3.5 w-3.5" />
              Remove
            </Button>
          </div>
          {renderRow(row, index)}
        </div>
      ))}

      <Button variant="outline" size="sm" onClick={() => onAdd(list, blank)}>
        <Plus className="h-3.5 w-3.5" />
        {addLabel}
      </Button>
    </div>
  )
}

function EducationFields({ row, index, options, errors, setRow }) {
  const id = (field) => `educations_${index}_${field}`
  const error = (field) => errors[`educations.${index}.${field}`]

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label="Level" htmlFor={id('education_level')} required error={error('education_level')}>
        <Select value={row.education_level} onChange={(e) => setRow('educations', index, 'education_level', e.target.value)} placeholder="Choose a level">
          {options.education_level.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      </Field>
      <Field label="School" htmlFor={id('school_name')} required error={error('school_name')}>
        <Input value={row.school_name} maxLength={190} onChange={(e) => setRow('educations', index, 'school_name', e.target.value)} />
      </Field>
      <Field label="Course or program" htmlFor={id('course_program')} error={error('course_program')} hint="Optional.">
        <Input value={row.course_program} maxLength={190} onChange={(e) => setRow('educations', index, 'course_program', e.target.value)} />
      </Field>
      <Field label="Year graduated" htmlFor={id('graduation_year')} error={error('graduation_year')} hint="Optional. Leave blank if you did not finish.">
        <Input type="number" inputMode="numeric" min={1950} max={new Date().getFullYear() + 6} value={row.graduation_year} onChange={(e) => setRow('educations', index, 'graduation_year', e.target.value)} />
      </Field>
      <Field label="Honors" htmlFor={id('honors')} error={error('honors')} hint="Optional." className="sm:col-span-2">
        <Input value={row.honors} maxLength={120} onChange={(e) => setRow('educations', index, 'honors', e.target.value)} />
      </Field>
    </div>
  )
}

function ExperienceFields({ row, index, errors, setRow }) {
  const id = (field) => `experiences_${index}_${field}`
  const error = (field) => errors[`experiences.${index}.${field}`]
  const today = new Date().toISOString().slice(0, 10)

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label="Employer" htmlFor={id('company_name')} required error={error('company_name')}>
        <Input value={row.company_name} maxLength={190} onChange={(e) => setRow('experiences', index, 'company_name', e.target.value)} />
      </Field>
      <Field label="Position" htmlFor={id('position_title')} required error={error('position_title')}>
        <Input value={row.position_title} maxLength={150} onChange={(e) => setRow('experiences', index, 'position_title', e.target.value)} />
      </Field>
      <Field label="Started" htmlFor={id('start_date')} required error={error('start_date')}>
        <Input type="date" max={today} value={row.start_date} onChange={(e) => setRow('experiences', index, 'start_date', e.target.value)} />
      </Field>
      <Field label="Ended" htmlFor={id('end_date')} error={error('end_date')} hint="Leave blank if you still work there.">
        <Input type="date" min={row.start_date || undefined} max={today} value={row.end_date} onChange={(e) => setRow('experiences', index, 'end_date', e.target.value)} />
      </Field>
      <Field label="What you did" htmlFor={id('responsibilities')} error={error('responsibilities')} hint="Optional. A sentence or two." className="sm:col-span-2">
        <Textarea rows={2} maxLength={1000} value={row.responsibilities} onChange={(e) => setRow('experiences', index, 'responsibilities', e.target.value)} />
      </Field>
    </div>
  )
}

/**
 * The saved Bio-Data, exactly as it would be sent. Built from the server's
 * copy, not from what is on screen, so what is reviewed is what is submitted.
 */
function ReviewStep({ data, dirty, missing, onFix }) {
  return (
    <div className="space-y-4">
      {dirty && (
        <p className="rounded-md border border-warning/40 bg-warning/5 px-3 py-2 text-sm">
          You have changes that are not saved. Go back and save them before submitting.
        </p>
      )}

      {missing.length > 0 ? (
        <div className="rounded-md border border-warning/40 bg-warning/5 px-3 py-2.5">
          <p className="text-sm font-medium">Still to complete before you can submit</p>
          <ul className="mt-1.5 space-y-1">
            {missing.map(([key, message]) => (
              <li key={key} className="text-sm">
                <button type="button" onClick={() => onFix(key)} className="text-left text-primary underline-offset-4 hover:underline">
                  {message}
                </button>
              </li>
            ))}
          </ul>
        </div>
      ) : (
        <p className="flex items-center gap-2 text-sm text-success">
          <CheckCircle2 className="h-4 w-4" />
          Everything required is filled in. Check the details below, then submit.
        </p>
      )}

      <div className="flex justify-end">
        <PdfButton draft label="Preview PDF" />
      </div>

      <BioDataSummary profile={data.profile} form={data.form} options={data.options} />
    </div>
  )
}

function ConfirmSubmitDialog({ open, busy, onOpenChange, onConfirm }) {
  const [agreed, setAgreed] = React.useState(false)

  React.useEffect(() => {
    if (open) setAgreed(false)
  }, [open])

  return (
    <Dialog open={open} onOpenChange={(next) => !busy && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Send your Bio-Data to the office?</DialogTitle>
          <DialogDescription>
            You will not be able to change it while the office reviews it. If something needs fixing, they will
            send it back to you.
          </DialogDescription>
        </DialogHeader>

        <label className="flex cursor-pointer items-start gap-2.5 rounded-md border p-3 text-sm">
          <input
            type="checkbox"
            checked={agreed}
            onChange={(e) => setAgreed(e.target.checked)}
            className="mt-0.5 h-4 w-4 shrink-0 accent-primary"
          />
          I confirm that the information in my Bio-Data is true and correct.
        </label>

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={busy}>
            Keep editing
          </Button>
          <Button onClick={onConfirm} disabled={!agreed || busy}>
            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
            {busy ? 'Sending…' : 'Submit Bio-Data'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
