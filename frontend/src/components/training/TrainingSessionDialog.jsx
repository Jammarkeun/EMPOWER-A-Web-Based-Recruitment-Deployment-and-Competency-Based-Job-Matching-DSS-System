import * as React from 'react'
import { Loader2 } from 'lucide-react'
import { patch, post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Field, FormGrid } from '@/components/ui/form'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { todayISO } from '@/lib/utils'

const EMPTY = { training_title: '', training_date: '', location: '', trainer_name: '', remarks: '' }

function formFrom(session) {
  if (!session) return { ...EMPTY, training_date: todayISO() }
  return {
    training_title: session.training_title ?? '',
    training_date: session.training_date ?? '',
    location: session.location ?? '',
    trainer_name: session.trainer_name ?? '',
    remarks: session.remarks ?? '',
  }
}

/**
 * Scheduling a session, and correcting its details afterwards.
 *
 * One form for both, so a field added to a session is added once. Editing
 * sends only what changed: the server checks each change against what is
 * already recorded (a session with attendance on it cannot be moved into the
 * future, for instance), and an untouched field should never be the reason a
 * save is refused. On a refusal the form keeps what was typed.
 */
export default function TrainingSessionDialog({ open, onOpenChange, session = null, onSaved }) {
  const editing = Boolean(session)
  const toast = useToast()
  const [form, setForm] = React.useState(() => formFrom(session))
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)

  React.useEffect(() => {
    if (open) {
      setForm(formFrom(session))
      setErrors({})
    }
  }, [open, session])

  function set(key, value) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function changes() {
    const original = formFrom(session)
    return Object.fromEntries(
      Object.entries(form).filter(([key, value]) => value !== original[key])
    )
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})

    const body = editing ? changes() : form
    if (editing && Object.keys(body).length === 0) {
      onOpenChange(false)
      return
    }

    setSubmitting(true)
    try {
      const response = editing ? await patch(`/trainings/${session.id}`, body) : await post('/trainings', body)
      toast.success(editing ? 'Session updated' : 'Training scheduled')
      onOpenChange(false)
      onSaved?.(response.data)
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error(editing ? 'Session not updated' : 'Could not schedule training', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{editing ? 'Edit session details' : 'Schedule training'}</DialogTitle>
          <DialogDescription>
            {editing
              ? 'Corrections are recorded in the audit trail. Attendance already recorded keeps the session date from moving into the future.'
              : 'Enrol applicants from the session’s page. Each moves to “Training Completed” once their completion is recorded on the roster.'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Field label="Title" htmlFor="training_title" required error={errors.training_title?.[0]}>
            <Input
              value={form.training_title}
              onChange={(e) => set('training_title', e.target.value)}
              placeholder="e.g. Food Safety Orientation"
              autoFocus
            />
          </Field>

          <FormGrid>
            <Field label="Date" htmlFor="training_date" required error={errors.training_date?.[0]}>
              <Input type="date" value={form.training_date} onChange={(e) => set('training_date', e.target.value)} />
            </Field>

            <Field label="Trainer" htmlFor="trainer_name" required error={errors.trainer_name?.[0]}>
              <Input value={form.trainer_name} onChange={(e) => set('trainer_name', e.target.value)} />
            </Field>
          </FormGrid>

          <Field
            label="Location"
            htmlFor="location"
            required
            error={errors.location?.[0]}
            hint="For training the client hosts, name the client’s site."
          >
            <Input value={form.location} onChange={(e) => set('location', e.target.value)} />
          </Field>

          <Field label="Remarks" htmlFor="remarks" error={errors.remarks?.[0]}>
            <Textarea rows={3} value={form.remarks} onChange={(e) => set('remarks', e.target.value)} />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              {editing ? 'Save changes' : 'Schedule'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
