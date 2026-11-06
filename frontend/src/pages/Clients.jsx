import * as React from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import {
  AlertTriangle,
  Building2,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Loader2,
  Plus,
  RotateCcw,
  Search,
} from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Input, PhoneInput } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { formProblems } from '@/lib/validation'
import { Field, FormGrid } from '@/components/ui/form'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Pagination, useClampPage } from '@/components/Pagination'
import { CompanyLocationField } from '@/components/map/CompanyLocation'
import {
  DocumentDraftRow,
  blankDocument,
  documentProblems,
  uploadCompanyDocument,
} from '@/components/companyDocuments/CompanyDocuments'
import { formatBytes } from '@/lib/uploads'
import { cn, formatDate } from '@/lib/utils'

export default function Clients() {
  const { can } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const [dialogOpen, setDialogOpen] = React.useState(false)

  const search = searchParams.get('search') ?? ''
  const page = Number(searchParams.get('page') ?? 1)
  const debouncedSearch = useDebounced(search)

  const { data, meta, loading, error, refetch } = useApi('/clients', {
    search: debouncedSearch || undefined,
    page,
    per_page: 20,
  })

  function updateFilter(key, value) {
    const next = new URLSearchParams(searchParams)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setSearchParams(next)
  }

  useClampPage(meta, (p) => updateFilter('page', String(p)))

  return (
    <>
      <PageHeader
        title="Client companies"
        description="The businesses CDE Manpower Services supplies workers to."
        actions={
          can('clients.create') && (
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Add client
            </Button>
          )
        }
      />

      <Card className="mb-4">
        <CardContent className="pt-5">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => updateFilter('search', e.target.value)}
              placeholder="Search by company name or contact person"
              className="pl-9"
              aria-label="Search client companies"
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        {loading ? (
          <SkeletonRows rows={5} columns={7} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={Building2}
            title={search ? 'No client companies match your search' : 'No client companies'}
            description={
              search
                ? 'Search for part of the company name, its code, or the contact person.'
                : 'Add a client company before recording their manpower requests.'
            }
            action={
              can('clients.create') && !search ? (
                <Button size="sm" onClick={() => setDialogOpen(true)}>
                  <Plus className="h-4 w-4" />
                  Add client
                </Button>
              ) : null
            }
          />
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Company</TableHead>
                  <TableHead>Business type</TableHead>
                  <TableHead>Contact</TableHead>
                  <TableHead>Departments</TableHead>
                  <TableHead>Deployed</TableHead>
                  <TableHead>Documents</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((client) => (
                  <TableRow key={client.id}>
                    <TableCell>
                      <Link to={`/clients/${client.id}`} className="font-medium text-primary hover:underline">
                        {client.company_name}
                      </Link>
                      <p className="font-mono text-xs text-muted-foreground">{client.company_code}</p>
                    </TableCell>
                    <TableCell className="text-sm">{client.business_type}</TableCell>
                    <TableCell className="text-sm">
                      {client.contact_person || '—'}
                      {client.contact_number && (
                        <p className="text-xs text-muted-foreground">{client.contact_number}</p>
                      )}
                    </TableCell>
                    <TableCell className="text-sm tabular-nums">{client.departments_count ?? 0}</TableCell>
                    <TableCell className="text-sm tabular-nums">{client.deployed_count ?? 0}</TableCell>
                    <TableCell className="text-sm">
                      {/* A count, not a verdict: companies keep different
                          documents, and none on file is not a fault. */}
                      <Link
                        to={`/clients/${client.id}?tab=documents`}
                        className={client.documents_count ? 'text-primary hover:underline' : 'text-muted-foreground hover:underline'}
                      >
                        {client.documents_count
                          ? `${client.documents_count} document${client.documents_count === 1 ? '' : 's'}`
                          : 'None uploaded'}
                      </Link>
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={client.status} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            <Pagination meta={meta} onPage={(p) => updateFilter('page', String(p))} />
          </>
        )}
      </Card>

      <NewClientDialog open={dialogOpen} onOpenChange={setDialogOpen} onCreated={refetch} />
    </>
  )
}


const BLANK_COMPANY = {
  company_name: '',
  business_type: '',
  contact_person: '',
  contact_number: '',
  email: '',
  office_address: '',
  // Empty until somebody pins it; never filled in for them.
  latitude: null,
  longitude: null,
}

const STEPS = [
  ['details', 'Company details'],
  ['documents', 'Documents'],
  ['review', 'Review'],
]

/** A document entry nobody has started filling in, which is dropped rather than refused. */
function untouched(draft) {
  return !draft.title.trim() && !draft.file && !draft.description.trim() && !draft.issued_on && !draft.expires_on
}

/**
 * Registers a client company, with any documents it has on file.
 *
 * Three steps - details, documents, review - then one save. The company is
 * created first and each document is uploaded to it separately, so one bad
 * file never costs the company record: a document that fails is named, kept as
 * entered, and can be retried against the company already saved, never by
 * creating it again. Documents are optional; companies do not all have the
 * same ones.
 */
export function NewClientDialog({ open, onOpenChange, onCreated }) {
  const navigate = useNavigate()
  const toast = useToast()
  const [step, setStep] = React.useState('details')
  const [form, setForm] = React.useState(BLANK_COMPANY)
  const [errors, setErrors] = React.useState({})
  const [drafts, setDrafts] = React.useState([])
  // The company once saved. From then on, only its documents are sent.
  const [company, setCompany] = React.useState(null)
  const [submitting, setSubmitting] = React.useState(false)
  const sending = React.useRef(false)

  React.useEffect(() => {
    if (open) {
      setStep('details')
      setForm(BLANK_COMPANY)
      setErrors({})
      setDrafts([])
      setCompany(null)
    }
  }, [open])

  function updateDraft(key, changes) {
    setDrafts((current) => current.map((d) => (d.key === key ? { ...d, ...changes } : d)))
  }

  function toDocuments(event) {
    event?.preventDefault()

    // Named beside the field before sending; the server checks again.
    const problems = formProblems(form, { phones: ['contact_number'] })
    if (!form.company_name.trim()) problems.company_name = ['Enter the company name.']
    if (!form.business_type.trim()) problems.business_type = ['Enter the business type.']
    if (!form.office_address.trim()) problems.office_address = ['Enter the office address.']
    setErrors(problems)
    if (Object.keys(problems).length) return

    setStep('documents')
  }

  function toReview() {
    const kept = drafts.filter((d) => !untouched(d))
    const checked = kept.map((d) => ({ ...d, errors: documentProblems(d) }))
    setDrafts(checked)
    if (checked.some((d) => Object.keys(d.errors).length)) return

    setStep('review')
  }

  /** Uploads the given entries one by one; returns how many failed. */
  async function uploadAll(companyId, pending) {
    let failed = 0

    for (const draft of pending) {
      updateDraft(draft.key, { status: 'uploading', progress: 0, error: null, errors: {} })
      try {
        await uploadCompanyDocument(companyId, draft, (progress) => updateDraft(draft.key, { progress }))
        updateDraft(draft.key, { status: 'saved' })
      } catch (error) {
        failed += 1
        updateDraft(draft.key, {
          status: 'failed',
          error: error.isValidation
            ? 'Not uploaded: correct the fields above, then retry.'
            : `Not uploaded: ${error.message}`,
          errors: error.isValidation ? error.errors : {},
        })
      }
    }

    return failed
  }

  function finish(saved, total, failed) {
    setStep('done')
    if (failed === 0) {
      toast.success('Client added', `${saved.company_name} was added with ${total} document${total === 1 ? '' : 's'}.`)
    } else {
      toast.warning(
        'Client added, some documents were not',
        `${failed} of ${total} document${total === 1 ? '' : 's'} could not be uploaded.`
      )
    }
  }

  async function handleSubmit() {
    // A second click while saving does nothing, so the company is never
    // registered twice.
    if (sending.current) return
    sending.current = true
    setSubmitting(true)

    try {
      let saved = company

      if (!saved) {
        try {
          const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
          const response = await post('/clients', payload)
          saved = response.data
          setCompany(saved)
          onCreated()
        } catch (error) {
          if (error.isValidation) {
            setErrors(error.errors)
            setStep('details')
          } else {
            toast.error('Could not add client', error.message)
          }
          return
        }
      }

      if (drafts.length === 0) {
        toast.success('Client added', `${form.company_name} is now available for manpower requests.`)
        onOpenChange(false)
        return
      }

      const failed = await uploadAll(saved.id, drafts.filter((d) => d.status !== 'saved'))
      finish(saved, drafts.length, failed)
    } finally {
      sending.current = false
      setSubmitting(false)
    }
  }

  async function retryFailed() {
    if (sending.current) return

    const failedDrafts = drafts.filter((d) => d.status === 'failed')
    const checked = failedDrafts.map((d) => ({ ...d, errors: documentProblems(d) }))
    if (checked.some((d) => Object.keys(d.errors).length)) {
      setDrafts((current) => current.map((d) => checked.find((c) => c.key === d.key) ?? d))
      return
    }

    sending.current = true
    setSubmitting(true)
    try {
      const failed = await uploadAll(company.id, failedDrafts)
      finish(company, drafts.length, failed)
    } finally {
      sending.current = false
      setSubmitting(false)
    }
  }

  const savedCount = drafts.filter((d) => d.status === 'saved').length
  const failedCount = drafts.filter((d) => d.status === 'failed').length
  const stepIndex = STEPS.findIndex(([key]) => key === step)

  return (
    <Dialog open={open} onOpenChange={(next) => !submitting && onOpenChange(next)}>
      <DialogContent className={step === 'details' ? undefined : 'max-w-2xl'}>
        <DialogHeader>
          <DialogTitle>Add client company</DialogTitle>
          <DialogDescription>
            Client companies do not use the system directly. HR maintains their details here.
          </DialogDescription>
        </DialogHeader>

        {step !== 'done' && (
          <ol className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs" aria-label="Steps">
            {STEPS.map(([key, label], index) => (
              <li
                key={key}
                aria-current={key === step ? 'step' : undefined}
                className={cn(
                  'flex items-center gap-1.5',
                  key === step ? 'font-semibold text-foreground' : 'text-muted-foreground'
                )}
              >
                <span
                  className={cn(
                    'flex h-5 w-5 items-center justify-center rounded-full border text-[10px]',
                    index <= stepIndex ? 'border-primary bg-primary text-primary-foreground' : 'border-border'
                  )}
                >
                  {index + 1}
                </span>
                {label}
              </li>
            ))}
          </ol>
        )}

        {step === 'details' && (
          <form onSubmit={toDocuments} className="space-y-4" noValidate>
            <Field label="Company name" htmlFor="company_name" required error={errors.company_name?.[0]}>
              <Input
                value={form.company_name}
                onChange={(e) => setForm({ ...form, company_name: e.target.value })}
                autoFocus
              />
            </Field>

            <FormGrid>
              <Field label="Business type" htmlFor="business_type" required error={errors.business_type?.[0]}>
                <Input
                  value={form.business_type}
                  onChange={(e) => setForm({ ...form, business_type: e.target.value })}
                  placeholder="e.g. Food Manufacturing"
                />
              </Field>

              <Field label="Contact person" htmlFor="contact_person" error={errors.contact_person?.[0]}>
                <Input
                  value={form.contact_person}
                  onChange={(e) => setForm({ ...form, contact_person: e.target.value })}
                />
              </Field>

              <Field label="Contact number" htmlFor="contact_number" error={errors.contact_number?.[0]}>
                <PhoneInput
                  value={form.contact_number}
                  onChange={(e) => setForm({ ...form, contact_number: e.target.value })}
                />
              </Field>

              <Field label="Email" htmlFor="email" error={errors.email?.[0]}>
                <Input
                  type="email"
                  value={form.email}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                />
              </Field>
            </FormGrid>

            <Field label="Office address" htmlFor="office_address" required error={errors.office_address?.[0]}>
              <Input
                value={form.office_address}
                onChange={(e) => setForm({ ...form, office_address: e.target.value })}
              />
            </Field>

            <CompanyLocationField
              latitude={form.latitude}
              longitude={form.longitude}
              companyName={form.company_name}
              error={errors.latitude?.[0] ?? errors.longitude?.[0]}
              onChange={(location) => setForm((current) => ({ ...current, ...location }))}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit">
                Next: documents
                <ChevronRight className="h-4 w-4" />
              </Button>
            </DialogFooter>
          </form>
        )}

        {step === 'documents' && (
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Optional. Add any documents this company has on file — for example its BIR registration, a
              business permit or a licence — and give each one a title. You can also add documents later from
              the company&apos;s Documents tab.
            </p>

            {drafts.length > 0 && (
              <ul className="space-y-3">
                {drafts.map((draft, index) => (
                  <DocumentDraftRow
                    key={draft.key}
                    draft={draft}
                    index={index}
                    onChange={(next) => updateDraft(draft.key, next)}
                    onRemove={() => setDrafts((current) => current.filter((d) => d.key !== draft.key))}
                  />
                ))}
              </ul>
            )}

            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => setDrafts((current) => [...current, blankDocument()])}
            >
              <Plus className="h-4 w-4" />
              {drafts.length ? 'Add another document' : 'Add a document'}
            </Button>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setStep('details')}>
                <ChevronLeft className="h-4 w-4" />
                Back
              </Button>
              <Button type="button" onClick={toReview}>
                Next: review
                <ChevronRight className="h-4 w-4" />
              </Button>
            </DialogFooter>
          </div>
        )}

        {step === 'review' && (
          <div className="space-y-4">
            <dl className="grid gap-x-4 gap-y-2 rounded-lg border p-3 text-sm sm:grid-cols-[10rem_1fr]">
              <dt className="text-muted-foreground">Company name</dt>
              <dd className="font-medium">{form.company_name}</dd>
              <dt className="text-muted-foreground">Business type</dt>
              <dd>{form.business_type}</dd>
              <dt className="text-muted-foreground">Contact</dt>
              <dd>{[form.contact_person, form.contact_number, form.email].filter(Boolean).join(' · ') || '—'}</dd>
              <dt className="text-muted-foreground">Office address</dt>
              <dd>{form.office_address}</dd>
              <dt className="text-muted-foreground">Location</dt>
              <dd>{form.latitude !== null ? 'Pinned on the map' : 'Not pinned'}</dd>
            </dl>

            <div>
              <p className="mb-2 text-sm font-medium">
                Documents to upload <span className="text-muted-foreground">({drafts.length})</span>
              </p>
              {drafts.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  None. You can add documents later from the company&apos;s Documents tab.
                </p>
              ) : (
                <ul className="space-y-2">
                  {drafts.map((draft, index) =>
                    draft.status ? (
                      // Sending: each entry shows how its upload is going.
                      <DocumentDraftRow key={draft.key} draft={draft} index={index} locked />
                    ) : (
                      <li
                        key={draft.key}
                        className="flex flex-wrap items-baseline justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                      >
                        <span className="font-medium">{draft.title}</span>
                        <span className="break-all text-xs text-muted-foreground">
                          {draft.file?.name} · {formatBytes(draft.file?.size)}
                          {draft.expires_on ? ` · expires ${formatDate(draft.expires_on)}` : ''}
                        </span>
                      </li>
                    )
                  )}
                </ul>
              )}
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setStep('documents')} disabled={submitting}>
                <ChevronLeft className="h-4 w-4" />
                Back
              </Button>
              <Button type="button" onClick={handleSubmit} disabled={submitting}>
                {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
                Add client
              </Button>
            </DialogFooter>
          </div>
        )}

        {step === 'done' && company && (
          <div className="space-y-4">
            {failedCount === 0 ? (
              <div className="flex gap-2 rounded-lg border border-success/40 bg-success/[0.06] px-3 py-2.5 text-sm" role="status">
                <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-success" />
                <p>
                  <span className="font-medium">{company.company_name}</span> was added with {savedCount} document
                  {savedCount === 1 ? '' : 's'}.
                </p>
              </div>
            ) : (
              <div className="flex gap-2 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-sm" role="alert">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
                <p>
                  <span className="font-medium">{company.company_name}</span> was saved. {savedCount} of{' '}
                  {drafts.length} documents were uploaded; {failedCount} {failedCount === 1 ? 'was' : 'were'} not.
                  Correct {failedCount === 1 ? 'it' : 'them'} below and retry — the company will not be added twice —
                  or add {failedCount === 1 ? 'it' : 'them'} later from the company&apos;s Documents tab.
                </p>
              </div>
            )}

            <ul className="space-y-3">
              {drafts.map((draft, index) => (
                <DocumentDraftRow
                  key={draft.key}
                  draft={draft}
                  index={index}
                  locked={submitting}
                  onChange={(next) => updateDraft(draft.key, next)}
                />
              ))}
            </ul>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                Close
              </Button>
              <Button
                type="button"
                variant={failedCount ? 'outline' : 'default'}
                disabled={submitting}
                onClick={() => {
                  onOpenChange(false)
                  navigate(`/clients/${company.id}?tab=documents`)
                }}
              >
                Open company documents
              </Button>
              {failedCount > 0 && (
                <Button type="button" onClick={retryFailed} disabled={submitting}>
                  {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <RotateCcw className="h-4 w-4" />}
                  Retry failed uploads
                </Button>
              )}
            </DialogFooter>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
