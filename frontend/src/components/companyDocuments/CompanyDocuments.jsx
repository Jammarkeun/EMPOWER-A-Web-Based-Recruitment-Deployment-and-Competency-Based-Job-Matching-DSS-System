import * as React from 'react'
import {
  Download,
  ExternalLink,
  FileImage,
  FileText,
  FolderOpen,
  Loader2,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  X,
} from 'lucide-react'
import { api, del, get, patch, upload } from '@/lib/api'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { Field, FormGrid } from '@/components/ui/form'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import { COMPANY_DOCUMENT_ACCEPT, companyDocumentFileProblem, formatBytes } from '@/lib/uploads'
import { cn, formatDate, todayISO } from '@/lib/utils'

/**
 * A client company's own documents: its BIR registration, permits, licences -
 * whatever CDE keeps on file for it.
 *
 * Each document carries the title the uploader typed. There is no checklist of
 * document types and no document every company must have, so an empty list is
 * simply empty, never a warning.
 */

const FILE_HINT = 'Up to 10 MB. A PDF, a scan or photo, or a Word or Excel file.'
const FILE_INPUT_CLASS =
  'cursor-pointer file:mr-3 file:cursor-pointer file:rounded file:border-0 file:bg-secondary file:px-2 file:py-1 file:text-xs file:font-medium'

/** A new, empty document entry for the forms below. */
export function blankDocument() {
  return {
    key: Math.random().toString(36).slice(2),
    title: '',
    file: null,
    description: '',
    issued_on: '',
    expires_on: '',
  }
}

/**
 * What is wrong with a document entry before it is sent, by field. The server
 * checks everything again, including what the file actually contains.
 */
export function documentProblems(doc, { needsFile = true } = {}) {
  const problems = {}
  if (!doc.title.trim()) problems.title = ['Give the document a title, such as "BIR Certificate of Registration".']
  else if (doc.title.trim().length > 150) problems.title = ['Keep the title to 150 characters or fewer.']

  if (needsFile) {
    const fileProblem = companyDocumentFileProblem(doc.file)
    if (fileProblem) problems.file = [fileProblem]
  }

  if (doc.issued_on && doc.issued_on > todayISO()) problems.issued_on = ['The issue date cannot be in the future.']
  if (doc.issued_on && doc.expires_on && doc.expires_on < doc.issued_on) {
    problems.expires_on = ['The expiry date cannot be before the issue date.']
  }
  if (doc.description.length > 1000) problems.description = ['Keep the remarks to 1,000 characters or fewer.']

  return problems
}

/** Sends one document entry to a company. */
export function uploadCompanyDocument(clientId, doc, onProgress) {
  const body = new FormData()
  body.append('title', doc.title.trim())
  body.append('file', doc.file)
  if (doc.description.trim()) body.append('description', doc.description.trim())
  if (doc.issued_on) body.append('issued_on', doc.issued_on)
  if (doc.expires_on) body.append('expires_on', doc.expires_on)

  return upload(`/clients/${clientId}/documents`, body, { onProgress })
}

export function UploadProgress({ value, label = 'Uploading' }) {
  return (
    <div className="space-y-1" role="status" aria-live="polite">
      <div className="flex justify-between text-xs text-muted-foreground">
        <span>{label}…</span>
        <span className="tabular-nums">{value}%</span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-muted">
        <div className="h-full rounded-full bg-primary transition-[width]" style={{ width: `${value}%` }} />
      </div>
    </div>
  )
}

/**
 * The title, file and optional details of one document. Shared by the
 * company's Documents tab and the Add client form, so both ask the same way.
 */
export function DocumentFields({ doc, onChange, errors = {}, idPrefix, withFile = true, disabled = false }) {
  const [showDetails, setShowDetails] = React.useState(
    Boolean(doc.description || doc.issued_on || doc.expires_on)
  )
  const set = (key) => (e) => onChange({ ...doc, [key]: e.target.value })
  const id = (name) => `${idPrefix}-${name}`
  const hasDetailErrors = Boolean(errors.description || errors.issued_on || errors.expires_on)

  return (
    <div className="space-y-3">
      <Field
        label="Document title"
        htmlFor={id('title')}
        required
        error={errors.title?.[0]}
        hint="Type any title, e.g. BIR Registration Certificate, Mayor's Permit."
      >
        <Input value={doc.title} onChange={set('title')} maxLength={150} disabled={disabled} />
      </Field>

      {withFile && (
        <Field label="File" htmlFor={id('file')} required error={errors.file?.[0]} hint={FILE_HINT}>
          <Input
            type="file"
            accept={COMPANY_DOCUMENT_ACCEPT}
            disabled={disabled}
            onChange={(e) => onChange({ ...doc, file: e.target.files?.[0] ?? null })}
            className={FILE_INPUT_CLASS}
          />
        </Field>
      )}
      {withFile && doc.file && (
        <p className="-mt-1 text-xs text-muted-foreground">
          Selected: <span className="font-medium text-foreground">{doc.file.name}</span> · {formatBytes(doc.file.size)}
        </p>
      )}

      {showDetails || hasDetailErrors ? (
        <div className="space-y-3 rounded-lg border border-dashed p-3">
          <FormGrid>
            <Field label="Issue date" htmlFor={id('issued')} error={errors.issued_on?.[0]}>
              <Input type="date" max={todayISO()} value={doc.issued_on} onChange={set('issued_on')} disabled={disabled} />
            </Field>
            <Field
              label="Expiry date"
              htmlFor={id('expires')}
              error={errors.expires_on?.[0]}
              hint="Leave empty if the document does not expire."
            >
              <Input type="date" value={doc.expires_on} onChange={set('expires_on')} disabled={disabled} />
            </Field>
          </FormGrid>
          <Field label="Remarks" htmlFor={id('description')} error={errors.description?.[0]}>
            <Textarea
              value={doc.description}
              onChange={set('description')}
              rows={2}
              maxLength={1000}
              placeholder="Anything worth noting, e.g. the registration number"
              disabled={disabled}
            />
          </Field>
        </div>
      ) : (
        <button
          type="button"
          className="text-xs font-medium text-primary hover:underline disabled:opacity-50"
          onClick={() => setShowDetails(true)}
          disabled={disabled}
        >
          + Add issue date, expiry date or remarks (optional)
        </button>
      )}
    </div>
  )
}

/** "Expired 31 Dec 2025" / "Expires 31 Dec 2030", only from a recorded date. */
export function ExpiryBadge({ doc }) {
  if (!doc.expires_on) return null

  return doc.is_expired ? (
    <Badge tone="warning">Expired {formatDate(doc.expires_on)}</Badge>
  ) : (
    <Badge tone="muted">Expires {formatDate(doc.expires_on)}</Badge>
  )
}

function fromSaved(doc) {
  return {
    key: String(doc.id),
    title: doc.title ?? '',
    file: null,
    description: doc.description ?? '',
    issued_on: doc.issued_on ?? '',
    expires_on: doc.expires_on ?? '',
  }
}

/** The file name the server gave the download, or the saved one. */
function downloadName(header, fallback) {
  const encoded = /filename\*=utf-8''([^;]+)/i.exec(header ?? '')
  if (encoded) {
    try {
      return decodeURIComponent(encoded[1])
    } catch {
      // Fall through to the plain name.
    }
  }
  const plain = /filename="?([^";]+)"?/i.exec(header ?? '')
  return plain ? plain[1] : fallback
}

export default function CompanyDocuments({ clientId, companyName }) {
  const { can } = useAuth()
  const toast = useToast()
  const canEdit = can('clients.update')
  const canRemove = can('clients.delete')

  const [search, setSearch] = React.useState('')
  const { data, loading, error, refetch, setData } = useApi(`/clients/${clientId}/documents`)

  const [adding, setAdding] = React.useState(false)
  const [editing, setEditing] = React.useState(null)
  const [replacing, setReplacing] = React.useState(null)
  const [removing, setRemoving] = React.useState(null)
  const [busy, setBusy] = React.useState(null)

  const documents = data ?? []
  const term = search.trim().toLowerCase()
  const shown = term
    ? documents.filter((d) => d.title.toLowerCase().includes(term) || d.file_name?.toLowerCase().includes(term))
    : documents

  function upsert(saved) {
    setData((current) => {
      const rest = (current ?? []).filter((d) => d.id !== saved.id)
      return [...rest, saved].sort((a, b) => a.title.localeCompare(b.title) || a.id - b.id)
    })
  }

  async function open(doc) {
    setBusy(`open-${doc.id}`)
    try {
      const response = await get(`/clients/${clientId}/documents/${doc.id}/link`)
      const opened = window.open(response.data.url, '_blank')
      if (opened) {
        try {
          opened.opener = null
        } catch {
          // Already navigated away; nothing to detach.
        }
      } else {
        toast.warning('Your browser blocked the document', 'Allow pop-ups for this site, then try again.')
      }
    } catch (err) {
      toast.error('Could not open the document', err.message)
    } finally {
      setBusy(null)
    }
  }

  async function download(doc) {
    setBusy(`download-${doc.id}`)
    try {
      const response = await api.get(`/clients/${clientId}/documents/${doc.id}/download`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = downloadName(response.headers?.['content-disposition'], doc.file_name)
      document.body.appendChild(link)
      link.click()
      link.remove()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (err) {
      toast.error('Could not download the document', err.message)
    } finally {
      setBusy(null)
    }
  }

  async function remove() {
    const doc = removing
    try {
      const response = await del(`/clients/${clientId}/documents/${doc.id}`)
      setData((current) => (current ?? []).filter((d) => d.id !== doc.id))
      toast.success('Document removed', response.message)
      setRemoving(null)
    } catch (err) {
      // The confirmation stays open, so removing can be tried again.
      toast.error('Could not remove the document', err.message)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <CardTitle className="text-base">Company documents</CardTitle>
            <CardDescription>
              Documents CDE keeps on file for this company, such as its BIR registration, permits or
              licences. Each one has the title you give it; companies do not all need the same documents.
            </CardDescription>
          </div>
          {canEdit && (
            <Button size="sm" onClick={() => setAdding(true)}>
              <Plus className="h-4 w-4" />
              Add document
            </Button>
          )}
        </div>
      </CardHeader>

      <CardContent className="space-y-3">
        {loading ? (
          <LoadingState label="Loading company documents…" />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : documents.length === 0 ? (
          <EmptyState
            icon={FolderOpen}
            title="No company documents yet"
            description={
              canEdit
                ? 'Nothing has been uploaded for this company. Add any document you keep on file for it.'
                : 'Nothing has been uploaded for this company.'
            }
            action={
              canEdit ? (
                <Button size="sm" onClick={() => setAdding(true)}>
                  <Plus className="h-4 w-4" />
                  Add document
                </Button>
              ) : null
            }
          />
        ) : (
          <>
            {documents.length > 3 && (
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search by title or file name"
                  className="pl-9"
                  aria-label="Search company documents"
                />
              </div>
            )}

            {shown.length === 0 ? (
              <EmptyState
                title="No documents match your search"
                description="Search for part of the document's title or its file name."
              />
            ) : (
              <ul className="divide-y" aria-label={`Documents of ${companyName ?? 'this company'}`}>
                {shown.map((doc) => {
                  const Icon = doc.file_type === 'Image' ? FileImage : FileText
                  return (
                    <li key={doc.id} className="flex flex-wrap items-start gap-3 py-3">
                      <Icon className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                      <div className="min-w-0 flex-1 basis-56">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="text-sm font-medium">{doc.title}</p>
                          <Badge tone="outline" className="text-[10px]">
                            {doc.file_type}
                          </Badge>
                          <ExpiryBadge doc={doc} />
                        </div>
                        <p className="mt-0.5 break-all text-xs text-muted-foreground">
                          <span className="sr-only">File: </span>
                          {doc.file_name} · {formatBytes(doc.file_size_bytes)}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          Uploaded {formatDate(doc.uploaded_at)}
                          {doc.uploaded_by ? ` by ${doc.uploaded_by}` : ''}
                          {doc.issued_on ? ` · Issued ${formatDate(doc.issued_on)}` : ''}
                        </p>
                        {doc.description && <p className="mt-1 text-xs">{doc.description}</p>}
                      </div>

                      <div className="flex flex-wrap items-center gap-1">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => open(doc)}
                          disabled={busy === `open-${doc.id}`}
                          aria-label={`Open ${doc.title}`}
                        >
                          {busy === `open-${doc.id}` ? (
                            <Loader2 className="h-3.5 w-3.5 animate-spin" />
                          ) : (
                            <ExternalLink className="h-3.5 w-3.5" />
                          )}
                          Open
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => download(doc)}
                          disabled={busy === `download-${doc.id}`}
                          aria-label={`Download ${doc.title}`}
                          title="Download"
                        >
                          {busy === `download-${doc.id}` ? (
                            <Loader2 className="h-3.5 w-3.5 animate-spin" />
                          ) : (
                            <Download className="h-3.5 w-3.5" />
                          )}
                        </Button>
                        {canEdit && (
                          <>
                            <Button
                              variant="ghost"
                              size="icon"
                              onClick={() => setEditing(doc)}
                              aria-label={`Edit ${doc.title}`}
                              title="Edit title and details"
                            >
                              <Pencil className="h-3.5 w-3.5" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon"
                              onClick={() => setReplacing(doc)}
                              aria-label={`Replace the file of ${doc.title}`}
                              title="Replace file"
                            >
                              <RefreshCw className="h-3.5 w-3.5" />
                            </Button>
                          </>
                        )}
                        {canRemove && (
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => setRemoving(doc)}
                            aria-label={`Remove ${doc.title}`}
                            title="Remove"
                            className="text-destructive hover:text-destructive"
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </Button>
                        )}
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
          </>
        )}
      </CardContent>

      <AddDocumentDialog
        open={adding}
        onOpenChange={setAdding}
        clientId={clientId}
        companyName={companyName}
        onSaved={upsert}
      />
      <EditDocumentDialog
        document={editing}
        onOpenChange={(next) => !next && setEditing(null)}
        clientId={clientId}
        onSaved={upsert}
      />
      <ReplaceFileDialog
        document={replacing}
        onOpenChange={(next) => !next && setReplacing(null)}
        clientId={clientId}
        onSaved={upsert}
      />
      <ConfirmActionDialog
        open={Boolean(removing)}
        onOpenChange={(next) => !next && setRemoving(null)}
        title={`Remove "${removing?.title}"?`}
        description="It will no longer be listed with this company's documents."
        consequences={[
          'Other documents of this company are not affected.',
          'The file and its record are kept on the server, and the removal is recorded in the audit trail.',
        ]}
        confirmLabel="Remove document"
        onConfirm={remove}
      />
    </Card>
  )
}

/**
 * Adds one document. On a failure the entries stay as typed, so trying again
 * is one click.
 */
function AddDocumentDialog({ open, onOpenChange, clientId, companyName, onSaved }) {
  const toast = useToast()
  const [doc, setDoc] = React.useState(blankDocument)
  const [errors, setErrors] = React.useState({})
  const [progress, setProgress] = React.useState(null)
  const [failure, setFailure] = React.useState(null)
  const sending = React.useRef(false)

  React.useEffect(() => {
    if (open) {
      setDoc(blankDocument())
      setErrors({})
      setProgress(null)
      setFailure(null)
    }
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()
    // A second click while the first is still sending does nothing.
    if (sending.current) return

    const problems = documentProblems(doc)
    setErrors(problems)
    if (Object.keys(problems).length) return

    sending.current = true
    setFailure(null)
    setProgress(0)

    try {
      const response = await uploadCompanyDocument(clientId, doc, setProgress)
      onSaved(response.data)
      toast.success('Document saved', response.message)
      onOpenChange(false)
    } catch (err) {
      if (err.isValidation) setErrors(err.errors)
      else setFailure(err.message)
    } finally {
      sending.current = false
      setProgress(null)
    }
  }

  const uploading = progress !== null

  return (
    <Dialog open={open} onOpenChange={(next) => !uploading && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add document</DialogTitle>
          <DialogDescription>
            {companyName ? `Saved to ${companyName}. ` : ''}Only people who may view client companies can open it.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <DocumentFields doc={doc} onChange={setDoc} errors={errors} idPrefix="add-doc" disabled={uploading} />

          {uploading && <UploadProgress value={progress} />}
          {failure && (
            <p className="rounded-lg border border-destructive/40 bg-destructive/[0.06] px-3 py-2 text-sm" role="alert">
              Upload failed: {failure} Your entries are kept — try again.
            </p>
          )}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={uploading}>
              Cancel
            </Button>
            <Button type="submit" disabled={uploading}>
              {uploading && <Loader2 className="h-4 w-4 animate-spin" />}
              {failure ? 'Try again' : 'Upload document'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/** Corrects the title or details. The file stays as it is. */
function EditDocumentDialog({ document: saved, onOpenChange, clientId, onSaved }) {
  const toast = useToast()
  const [doc, setDoc] = React.useState(() => fromSaved(saved ?? {}))
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(false)

  React.useEffect(() => {
    if (saved) {
      setDoc(fromSaved(saved))
      setErrors({})
    }
  }, [saved])

  async function handleSubmit(event) {
    event.preventDefault()
    if (saving) return

    const problems = documentProblems(doc, { needsFile: false })
    setErrors(problems)
    if (Object.keys(problems).length) return

    setSaving(true)
    try {
      const response = await patch(`/clients/${clientId}/documents/${saved.id}`, {
        title: doc.title.trim(),
        description: doc.description.trim() || null,
        issued_on: doc.issued_on || null,
        expires_on: doc.expires_on || null,
      })
      onSaved(response.data)
      toast.success('Document updated', response.message)
      onOpenChange(false)
    } catch (err) {
      if (err.isValidation) setErrors(err.errors)
      else toast.error('Could not update the document', err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={Boolean(saved)} onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit document details</DialogTitle>
          <DialogDescription>
            The file stays the same: {saved?.file_name}. To change the file itself, use Replace file.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          {saved && (
            <DocumentFields
              key={saved.id}
              doc={doc}
              onChange={setDoc}
              errors={errors}
              idPrefix="edit-doc"
              withFile={false}
              disabled={saving}
            />
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              Save changes
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/** Puts a new file in place of one document's current file. */
function ReplaceFileDialog({ document: saved, onOpenChange, clientId, onSaved }) {
  const toast = useToast()
  const [file, setFile] = React.useState(null)
  const [error, setError] = React.useState(null)
  const [progress, setProgress] = React.useState(null)
  const sending = React.useRef(false)

  React.useEffect(() => {
    setFile(null)
    setError(null)
    setProgress(null)
  }, [saved])

  async function handleSubmit(event) {
    event.preventDefault()
    if (sending.current) return

    const problem = companyDocumentFileProblem(file)
    setError(problem)
    if (problem) return

    sending.current = true
    setProgress(0)
    const body = new FormData()
    body.append('file', file)

    try {
      const response = await upload(`/clients/${clientId}/documents/${saved.id}/file`, body, { onProgress: setProgress })
      onSaved(response.data)
      toast.success('File replaced', response.message)
      onOpenChange(false)
    } catch (err) {
      setError(err.errors?.file?.[0] ?? `Upload failed: ${err.message} Try again.`)
    } finally {
      sending.current = false
      setProgress(null)
    }
  }

  const uploading = progress !== null

  return (
    <Dialog open={Boolean(saved)} onOpenChange={(next) => !uploading && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Replace file</DialogTitle>
          <DialogDescription>
            The new file takes the place of {saved?.file_name} under the title “{saved?.title}”. No other document
            changes.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Field label="New file" htmlFor="replace-doc-file" required error={error} hint={FILE_HINT}>
            <Input
              type="file"
              accept={COMPANY_DOCUMENT_ACCEPT}
              disabled={uploading}
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              className={FILE_INPUT_CLASS}
            />
          </Field>
          {uploading && <UploadProgress value={progress} />}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={uploading}>
              Cancel
            </Button>
            <Button type="submit" disabled={uploading}>
              {uploading && <Loader2 className="h-4 w-4 animate-spin" />}
              Replace file
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

/**
 * One document waiting to be uploaded with a new company: its fields while it
 * can still be changed, and how its upload went once sent.
 */
export function DocumentDraftRow({ draft, index, onChange, onRemove, locked = false }) {
  const status = draft.status ?? 'pending'
  const editable = !locked && status !== 'saved' && status !== 'uploading'

  return (
    <li
      className={cn(
        'rounded-lg border p-3',
        status === 'failed' && 'border-destructive/50 bg-destructive/[0.03]',
        status === 'saved' && 'border-success/40 bg-success/[0.04]'
      )}
    >
      <div className="mb-2 flex items-center justify-between gap-2">
        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Document {index + 1}</p>
        <div className="flex items-center gap-2">
          {status === 'saved' && <Badge tone="success">Saved</Badge>}
          {status === 'failed' && <Badge tone="danger">Not uploaded</Badge>}
          {editable && onRemove && (
            <Button
              type="button"
              variant="ghost"
              size="icon"
              onClick={onRemove}
              aria-label={`Remove document ${index + 1} from the list`}
            >
              <X className="h-3.5 w-3.5" />
            </Button>
          )}
        </div>
      </div>

      {status === 'saved' ? (
        <p className="text-sm">
          <span className="font-medium">{draft.title}</span>
          <span className="text-muted-foreground"> · {draft.file?.name}</span>
        </p>
      ) : (
        <DocumentFields
          doc={draft}
          onChange={onChange}
          errors={draft.errors}
          idPrefix={`draft-${draft.key}`}
          disabled={!editable}
        />
      )}

      {status === 'uploading' && (
        <div className="mt-3">
          <UploadProgress value={draft.progress ?? 0} />
        </div>
      )}
      {status === 'failed' && draft.error && (
        <p className="mt-2 text-xs font-medium text-destructive" role="alert">
          {draft.error}
        </p>
      )}
    </li>
  )
}
