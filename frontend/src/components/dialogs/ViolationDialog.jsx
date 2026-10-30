import * as React from 'react'
import { Loader2 } from 'lucide-react'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
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

/**
 * HR's one way of recording an official violation.
 *
 * Opened from the employee's header it starts empty. Opened from a verified
 * attendance review it arrives filled in from the DTR and tied to that review:
 * the date is the reviewed day, and the server refuses it unless the review
 * really is verified. Either way nothing is recorded until somebody saves it,
 * and it goes through the same endpoint and threshold check.
 */
export default function ViolationDialog({ open, onOpenChange, employeeId, initial, attendanceReviewId, onSaved }) {
  const blank = () => ({
    violation_date: todayISO(),
    violation_type: 'late',
    description: '',
    penalty: '',
  })
  const [form, setForm] = React.useState(blank)
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setForm({ ...blank(), ...(initial ?? {}) })
      setErrors({})
    }
  }, [open, initial])

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setSubmitting(true)

    try {
      await post(`/employees/${employeeId}/violations`, {
        ...form,
        ...(attendanceReviewId ? { attendance_review_id: attendanceReviewId } : {}),
      })
      toast.success('Violation recorded', 'Added to the employee’s permanent file.')
      onOpenChange(false)
      onSaved?.()
    } catch (error) {
      if (error.isValidation && Object.keys(error.errors).length) setErrors(error.errors)
      else toast.error('Could not record violation', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Record violation</DialogTitle>
          <DialogDescription>
            This becomes part of the employee's permanent record and may later support a termination,
            so describe what happened factually.
            {attendanceReviewId && ' Filled in from a verified attendance discrepancy — check it before saving.'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <FormGrid>
            <Field
              label="Date"
              htmlFor="violation_date"
              required
              error={errors.violation_date?.[0]}
              hint={attendanceReviewId ? 'The reviewed day.' : undefined}
            >
              <Input
                type="date"
                value={form.violation_date}
                onChange={(e) => setForm({ ...form, violation_date: e.target.value })}
                max={todayISO()}
                disabled={Boolean(attendanceReviewId)}
              />
            </Field>

            <Field label="Type" htmlFor="violation_type" required error={errors.violation_type?.[0]}>
              <Select
                value={form.violation_type}
                onChange={(e) => setForm({ ...form, violation_type: e.target.value })}
              >
                <option value="late">Late</option>
                <option value="absences">Absences</option>
                <option value="awol">AWOL</option>
                <option value="suspension">Suspension</option>
                <option value="misconduct">Misconduct</option>
                <option value="policy_violation">Policy violation</option>
              </Select>
            </Field>
          </FormGrid>

          <Field label="Description" htmlFor="description" required error={errors.description?.[0]}>
            <Textarea
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
              placeholder="What happened, when, and who reported it"
            />
          </Field>

          <Field label="Penalty" htmlFor="penalty" error={errors.penalty?.[0]}>
            <Input
              value={form.penalty}
              onChange={(e) => setForm({ ...form, penalty: e.target.value })}
              placeholder="e.g. Written warning"
            />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Record violation
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
