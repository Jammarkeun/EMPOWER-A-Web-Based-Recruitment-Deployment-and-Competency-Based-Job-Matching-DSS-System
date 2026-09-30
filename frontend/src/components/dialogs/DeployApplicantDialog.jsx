import * as React from 'react'
import { Loader2, Building2, Info } from 'lucide-react'
import { get, post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Field } from '@/components/ui/form'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { formatDate } from '@/lib/utils'

/**
 * Deploying an applicant from their own record.
 *
 * A deployment is not just a status: it says which client, which department,
 * which position and from what date, and the employee record created from it
 * carries all of that. Those facts cannot be inferred from a status change, so
 * the one step in the process that needs information asks for it — and then
 * everything downstream happens on its own.
 *
 * Once this is submitted the system creates the deployment, creates the employee
 * record linked to this same applicant, decrements the request's outstanding
 * headcount, and advances the applicant's status. No second data entry, and no
 * duplicate person.
 */
export default function DeployApplicantDialog({ applicant, open, onOpenChange, onDeployed }) {
  const toast = useToast()

  const [requests, setRequests] = React.useState(null)
  const [form, setForm] = React.useState({
    job_request_id: '',
    position_title: '',
    supervisor_name: '',
    employee_number: '',
    deployment_date: new Date().toISOString().slice(0, 10),
    remarks: '',
  })
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)

  React.useEffect(() => {
    if (!open) return

    setErrors({})
    setRequests(null)

    // Only open requests can take a worker; a fulfilled one has no headcount
    // left, and offering it would only produce a refusal from the server.
    get('/job-requests', { only_open: 1, per_page: 100 })
      .then((response) => {
        setRequests(response.data)
        setForm((current) => ({
          ...current,
          job_request_id: response.data[0]?.id ? String(response.data[0].id) : '',
          position_title: response.data[0]?.position_title ?? '',
        }))
      })
      .catch((error) => {
        setRequests([])
        toast.error('Could not load open requests', error.message)
      })
  }, [open, toast])

  const selected = requests?.find((r) => String(r.id) === String(form.job_request_id))

  function set(field, value) {
    setForm((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field]: undefined }))
  }

  async function handleSubmit(event) {
    event.preventDefault()

    if (!form.job_request_id) {
      setErrors({ job_request_id: ['Choose the request this applicant is filling.'] })
      return
    }

    setSubmitting(true)
    setErrors({})

    try {
      await post('/deployments', {
        applicant_id: applicant.id,
        job_request_id: Number(form.job_request_id),
        position_title: form.position_title || undefined,
        supervisor_name: form.supervisor_name || undefined,
        employee_number: form.employee_number || undefined,
        deployment_date: form.deployment_date,
        remarks: form.remarks || undefined,
      })

      toast.success(
        `${applicant.full_name} deployed`,
        'They now appear in Deployments and Employees.'
      )
      onOpenChange(false)
      onDeployed()
    } catch (error) {
      if (error.isValidation) {
        setErrors(error.errors)
        toast.error('Check the form', 'Some details need correcting.')
      } else {
        // A 409 means a business rule blocked it — most often documents still
        // outstanding. The server names the reason, so it is shown as given.
        toast.error(
          error.isConflict ? 'This applicant cannot be deployed yet' : 'Could not deploy',
          error.message
        )
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Deploy {applicant?.full_name}</DialogTitle>
          <DialogDescription>
            Recording this creates their employee record and adds them to Deployments
            automatically. Nothing needs entering twice.
          </DialogDescription>
        </DialogHeader>

        {requests === null ? (
          <div className="flex items-center justify-center py-10">
            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
          </div>
        ) : requests.length === 0 ? (
          <div className="flex gap-2.5 rounded-lg border border-warning/40 bg-warning/5 p-3 text-sm">
            <Info className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
            <p>
              There are no open manpower requests to deploy against. Record the client&rsquo;s
              request first, then come back.
            </p>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            <Field
              label="Which request are they filling?"
              htmlFor="job_request_id"
              required
              error={errors.job_request_id?.[0]}
            >
              <Select
                value={form.job_request_id}
                onChange={(e) => {
                  const next = requests.find((r) => String(r.id) === e.target.value)
                  set('job_request_id', e.target.value)
                  if (next?.position_title) set('position_title', next.position_title)
                }}
              >
                {requests.map((request) => (
                  <option key={request.id} value={request.id}>
                    {request.company} — {request.position_title}
                    {` (${request.workers_fulfilled}/${request.workers_needed} filled)`}
                  </option>
                ))}
              </Select>
            </Field>

            {selected && (
              <div className="flex items-start gap-2.5 rounded-lg border bg-muted/40 px-3 py-2.5 text-xs">
                <Building2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                <div>
                  <p className="font-medium text-foreground">{selected.company}</p>
                  <p className="text-muted-foreground">
                    {selected.department ? `${selected.department} · ` : ''}
                    {selected.workers_needed - selected.workers_fulfilled} still needed
                    {selected.deadline ? ` · due ${formatDate(selected.deadline)}` : ''}
                  </p>
                </div>
              </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Position" htmlFor="position_title" error={errors.position_title?.[0]}>
                <Input
                  value={form.position_title}
                  onChange={(e) => set('position_title', e.target.value)}
                  placeholder="As on the request"
                />
              </Field>

              <Field
                label="Start date"
                htmlFor="deployment_date"
                required
                error={errors.deployment_date?.[0]}
              >
                <Input
                  type="date"
                  value={form.deployment_date}
                  onChange={(e) => set('deployment_date', e.target.value)}
                />
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Supervisor" htmlFor="supervisor_name" error={errors.supervisor_name?.[0]}>
                <Input
                  value={form.supervisor_name}
                  onChange={(e) => set('supervisor_name', e.target.value)}
                  placeholder="Optional"
                />
              </Field>

              <Field
                label="Employee number"
                htmlFor="employee_number"
                error={errors.employee_number?.[0]}
                hint="Left blank, one is generated."
              >
                <Input
                  value={form.employee_number}
                  onChange={(e) => set('employee_number', e.target.value)}
                  placeholder="Optional"
                />
              </Field>
            </div>

            <DialogFooter>
              <Button type="button" variant="ghost" onClick={() => onOpenChange(false)} disabled={submitting}>
                Cancel
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
                {submitting ? 'Deploying…' : 'Deploy'}
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  )
}
