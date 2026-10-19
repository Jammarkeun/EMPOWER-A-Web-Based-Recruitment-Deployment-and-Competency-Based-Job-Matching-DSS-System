import * as React from 'react'
import { ArrowRight, Loader2 } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { Field, FormGrid } from '@/components/ui/form'
import { LoadingState, ErrorState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { formatDate, todayISO } from '@/lib/utils'

/** The earliest date a move may carry: the placement's start, or its last move. */
export function earliestReassignmentDate(deployment) {
  const moves = (deployment?.history ?? []).map((move) => move.effective_date).filter(Boolean)
  return [deployment?.deployment_date, ...moves].filter(Boolean).sort().at(-1) ?? null
}

function startingForm(deployment) {
  return {
    client_department_id: String(deployment?.client_department_id ?? ''),
    position_title: deployment?.position_title ?? '',
    supervisor_name: deployment?.supervisor_name ?? '',
    effective_date: todayISO(),
    remarks: '',
  }
}

/**
 * Checks the form the way the server will, so the officer hears about a
 * problem before the review step rather than after confirming. The server
 * checks everything again; this only saves a round trip.
 */
export function reassignmentProblems(form, deployment) {
  const problems = {}
  const earliest = earliestReassignmentDate(deployment)

  if (!form.client_department_id) problems.client_department_id = 'Choose the department.'
  if (!form.effective_date) problems.effective_date = 'Give the date the move took effect.'
  else if (form.effective_date > todayISO()) problems.effective_date = 'Record a reassignment once it has taken effect.'
  else if (earliest && form.effective_date < earliest) problems.effective_date = `The date cannot be before ${formatDate(earliest)}.`
  if (!form.remarks.trim()) problems.remarks = 'Give the reason for the reassignment.'

  const unchanged =
    String(deployment?.client_department_id) === form.client_department_id &&
    (form.position_title.trim() || deployment?.position_title) === deployment?.position_title &&
    (form.supervisor_name.trim() || null) === (deployment?.supervisor_name || null)
  if (unchanged) problems.client_department_id = 'Nothing would change. Choose a different department, position or supervisor.'

  return problems
}

/**
 * Moving a deployed worker to another department, position or supervisor at
 * the same client (audit FN-11).
 *
 * The client company is fixed: a move to another client is a new deployment
 * against that client's own request, so the request's headcount and the
 * client's coordinator stay right. The officer sees the change side by side
 * before confirming; the previous placement stays in the history either way.
 */
export default function ReassignDialog({ open, onOpenChange, employeeName, deployment, onSaved }) {
  const toast = useToast()
  const [step, setStep] = React.useState('edit')
  const [form, setForm] = React.useState(() => startingForm(deployment))
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)

  const clientId = deployment?.client_company_id
  const departments = useApi(`/clients/${clientId}/departments`, undefined, { enabled: open && Boolean(clientId) })
  const positions = useApi(`/clients/${clientId}/positions`, undefined, { enabled: open && Boolean(clientId) })

  React.useEffect(() => {
    if (open) {
      setStep('edit')
      setForm(startingForm(deployment))
      setErrors({})
    }
  }, [open, deployment])

  const activeDepartments = (departments.data ?? []).filter(
    (d) => d.status === 'active' || String(d.id) === String(deployment?.client_department_id)
  )
  const positionTitles = (positions.data?.positions ?? [])
    .filter((p) => p.status === 'active')
    .map((p) => p.position_title)
  const chosenDepartment = activeDepartments.find((d) => String(d.id) === form.client_department_id)

  function set(key, value) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function review(event) {
    event.preventDefault()
    const problems = reassignmentProblems(form, deployment)
    setErrors(problems)
    if (Object.keys(problems).length === 0) setStep('review')
  }

  async function confirm() {
    setSubmitting(true)
    try {
      const response = await post(`/deployments/${deployment.id}/reassign`, {
        client_department_id: Number(form.client_department_id),
        position_title: form.position_title.trim() || null,
        supervisor_name: form.supervisor_name.trim() || null,
        effective_date: form.effective_date,
        remarks: form.remarks.trim(),
      })
      toast.success('Reassignment recorded', `${employeeName} is now in ${response.data?.department_name ?? chosenDepartment?.department_name}.`)
      onOpenChange(false)
      onSaved?.(response.data)
    } catch (error) {
      // Back to the form with what was typed, so nothing has to be re-entered.
      if (error.isValidation) {
        setErrors(Object.fromEntries(Object.entries(error.errors).map(([key, messages]) => [key, messages[0]])))
        setStep('edit')
      } else {
        toast.error('Reassignment not recorded', error.message)
      }
    } finally {
      setSubmitting(false)
    }
  }

  const rows = [
    ['Department', deployment?.department_name, chosenDepartment?.department_name],
    ['Position', deployment?.position_title, form.position_title.trim() || deployment?.position_title],
    ['Supervisor', deployment?.supervisor_name || '—', form.supervisor_name.trim() || '—'],
  ]

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>{step === 'edit' ? 'Reassign within the client' : 'Review the reassignment'}</DialogTitle>
          <DialogDescription>
            {deployment?.company_name}. A move to another client is recorded as a new deployment against that client’s
            request, not here.
          </DialogDescription>
        </DialogHeader>

        {step === 'edit' ? (
          departments.loading || positions.loading ? (
            <LoadingState label="Loading departments…" />
          ) : departments.error ? (
            <ErrorState message={departments.error.message} onRetry={departments.refetch} />
          ) : (
            <form onSubmit={review} className="space-y-4" noValidate>
              <Field label="Department" htmlFor="client_department_id" required error={errors.client_department_id}>
                <Select
                  value={form.client_department_id}
                  onChange={(e) => set('client_department_id', e.target.value)}
                  placeholder="Choose a department"
                >
                  {activeDepartments.map((department) => (
                    <option key={department.id} value={String(department.id)}>
                      {department.department_name}
                      {String(department.id) === String(deployment?.client_department_id) ? ' (current)' : ''}
                    </option>
                  ))}
                </Select>
              </Field>

              <FormGrid>
                <Field
                  label="Position"
                  htmlFor="position_title"
                  error={errors.position_title}
                  hint="The client’s own title for the role."
                >
                  <Input
                    list="reassign-positions"
                    value={form.position_title}
                    onChange={(e) => set('position_title', e.target.value)}
                  />
                </Field>
                <datalist id="reassign-positions">
                  {positionTitles.map((title) => (
                    <option key={title} value={title} />
                  ))}
                </datalist>

                <Field label="Supervisor" htmlFor="supervisor_name" error={errors.supervisor_name}>
                  <Input value={form.supervisor_name} onChange={(e) => set('supervisor_name', e.target.value)} />
                </Field>
              </FormGrid>

              <Field
                label="Effective date"
                htmlFor="effective_date"
                required
                error={errors.effective_date}
                hint="The day the move took effect. It cannot be in the future."
              >
                <Input
                  type="date"
                  value={form.effective_date}
                  min={earliestReassignmentDate(deployment) ?? undefined}
                  max={todayISO()}
                  onChange={(e) => set('effective_date', e.target.value)}
                />
              </Field>

              <Field label="Reason" htmlFor="remarks" required error={errors.remarks}>
                <Textarea rows={3} value={form.remarks} onChange={(e) => set('remarks', e.target.value)} />
              </Field>

              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                  Cancel
                </Button>
                <Button type="submit">Review</Button>
              </DialogFooter>
            </form>
          )
        ) : (
          <div className="space-y-4">
            <table className="w-full text-sm">
              <caption className="sr-only">Current and new assignment</caption>
              <thead>
                <tr className="text-left text-xs text-muted-foreground">
                  <th className="py-1 font-medium" scope="col">
                    <span className="sr-only">Field</span>
                  </th>
                  <th className="py-1 font-medium" scope="col">Now</th>
                  <th className="py-1" scope="col">
                    <span className="sr-only">changes to</span>
                  </th>
                  <th className="py-1 font-medium" scope="col">After</th>
                </tr>
              </thead>
              <tbody>
                {rows.map(([label, before, after]) => (
                  <tr key={label} className="border-t">
                    <th scope="row" className="py-2 pr-3 text-left text-xs font-medium text-muted-foreground">
                      {label}
                    </th>
                    <td className="py-2 pr-2">{before || '—'}</td>
                    <td className="py-2 pr-2 text-muted-foreground">
                      <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                    </td>
                    <td className={before !== after ? 'py-2 font-medium' : 'py-2 text-muted-foreground'}>
                      {after || '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            <dl className="grid gap-2 rounded-row bg-inset p-3 text-sm sm:grid-cols-[auto,1fr] sm:gap-x-4">
              <dt className="text-xs text-muted-foreground">Effective</dt>
              <dd>{formatDate(form.effective_date)}</dd>
              <dt className="text-xs text-muted-foreground">Reason</dt>
              <dd className="whitespace-pre-line">{form.remarks.trim()}</dd>
            </dl>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setStep('edit')} disabled={submitting}>
                Back
              </Button>
              <Button onClick={confirm} disabled={submitting}>
                {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
                Confirm reassignment
              </Button>
            </DialogFooter>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
