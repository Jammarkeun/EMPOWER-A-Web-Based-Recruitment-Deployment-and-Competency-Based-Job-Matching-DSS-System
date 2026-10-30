import * as React from 'react'
import { Loader2, MessageSquare, Send } from 'lucide-react'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { Field } from '@/components/ui/form'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { ReportStatus } from '@/pages/coordinator/CoordinatorEmployeeDetail'
import { formatDateTime } from '@/lib/utils'

/**
 * The notes on an incident report, oldest first, and a box to add one.
 *
 * Shared by the coordinator and the office. The office can also mark the case
 * pending or resolved with its note ("case pending", "case resolved", as CDE
 * put it); the coordinator can only add a follow-up. Notes are never edited or
 * removed, so the thread reads as it happened.
 */
export default function IncidentThread({ report, endpoint, canChangeStatus, onChanged }) {
  const toast = useToast()
  const [note, setNote] = React.useState('')
  const [status, setStatus] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [busy, setBusy] = React.useState(false)

  const statusChoices = [
    report.status !== 'under_review' && { value: 'under_review', label: report.status === 'resolved' ? 'Reopen — case pending' : 'Case pending' },
    report.status !== 'resolved' && { value: 'resolved', label: 'Case resolved' },
  ].filter(Boolean)

  async function submit(event) {
    event.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      const response = await post(endpoint, {
        note: note.trim() || null,
        ...(canChangeStatus && status ? { status } : {}),
      })
      toast.success(response.message)
      setNote('')
      setStatus('')
      onChanged()
    } catch (err) {
      if (err.isValidation) setErrors(err.errors)
      else toast.error('Could not add the note', err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <MessageSquare className="h-4 w-4" /> Notes
        </CardTitle>
        <CardDescription>
          {canChangeStatus
            ? 'Answer the coordinator. Marking the case pending or resolved is recorded with your note.'
            : 'The office answers here. You can add a follow-up; only the office closes a case.'}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4 text-sm">
        {report.notes?.length ? (
          <ol className="space-y-3">
            {report.notes.map((n) => (
              <li key={n.id} className="rounded-row bg-inset px-3 py-2.5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-medium">
                    {n.author}
                    <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                      {n.author_role === 'coordinator' ? 'Coordinator' : 'Office'} · {formatDateTime(n.created_at)}
                    </span>
                  </p>
                  {n.status_to && <ReportStatus status={n.status_to} label={n.status_to_label} />}
                </div>
                <p className="mt-1 whitespace-pre-line">{n.note}</p>
              </li>
            ))}
          </ol>
        ) : (
          <p className="text-muted-foreground">No notes yet.</p>
        )}

        {report.can_add_note && (
          <form onSubmit={submit} className="space-y-3 border-t pt-4" noValidate>
            {canChangeStatus && (
              <Field label="Mark the case" htmlFor="incident_status" hint="Optional. Leave it to add a note only.">
                <Select value={status} onChange={(e) => setStatus(e.target.value)} placeholder="Keep the current status">
                  {statusChoices.map((c) => (
                    <option key={c.value} value={c.value}>
                      {c.label}
                    </option>
                  ))}
                </Select>
              </Field>
            )}
            <Field label="Note" htmlFor="incident_note" error={errors.note?.[0]}>
              <Textarea
                rows={3}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder={canChangeStatus ? 'e.g. Case resolved: truck repaired, workers back on the 2 PM shift.' : 'e.g. Client confirms all workers returned.'}
              />
            </Field>
            <div className="flex justify-end">
              <Button type="submit" disabled={busy}>
                {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                Add note
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  )
}
