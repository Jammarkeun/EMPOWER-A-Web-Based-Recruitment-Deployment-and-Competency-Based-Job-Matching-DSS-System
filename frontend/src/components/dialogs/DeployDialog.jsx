import * as React from 'react'
import { Loader2, AlertTriangle } from 'lucide-react'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
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
import { Field, FormGrid } from '@/components/ui/form'

/**
 * Records a deployment, converting the applicant into an employee.
 *
 * The most consequential action in the system and the one the agency cannot
 * cleanly reverse, so the dialog states plainly what will happen before the user
 * confirms. The employee number is left blank by default and generated on the
 * server, which avoids two staff assigning the same one by hand.
 */
export default function DeployDialog({ applicant, jobRequest, onOpenChange, onDeployed }) {
  const [form, setForm] = React.useState({
    supervisor_name: '',
    employee_number: '',
    biometric_number: '',
    deployment_date: new Date().toISOString().slice(0, 10),
    remarks: '',
  })
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  const open = !!applicant

  React.useEffect(() => {
    if (open) {
      setForm({
        supervisor_name: '',
        employee_number: '',
        biometric_number: '',
        deployment_date: new Date().toISOString().slice(0, 10),
        remarks: '',
      })
      setErrors({})
    }
  }, [open])

  function set(field, value) {
    setForm((current) => ({ ...current, [field]: value }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setSubmitting(true)

    try {
      const payload = {
        applicant_id: applicant.applicant_id,
        job_request_id: jobRequest.id,
        deployment_date: form.deployment_date,
        ...Object.fromEntries(
          Object.entries(form).filter(([key, value]) => key !== 'deployment_date' && value !== '')
        ),
      }

      const response = await post('/deployments', payload)

      toast.success(
        'Deployment recorded',
        `${applicant.applicant_name} is now an active employee (${response.data.employee_number ?? 'number assigned'}).`
      )
      onDeployed?.()
    } catch (error) {
      if (error.isValidation) {
        setErrors(error.errors)
      } else {
        // Business-rule refusals name the exact blocker, such as an outstanding
        // medical certificate, so the message is shown as the server wrote it.
        toast.error('Deployment refused', error.message)
      }
    } finally {
      setSubmitting(false)
    }
  }

  if (!applicant) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Deploy {applicant.applicant_name}</DialogTitle>
          <DialogDescription>
            To {jobRequest.position_title} at {jobRequest.company_name} · {jobRequest.department_name}
          </DialogDescription>
        </DialogHeader>

        <div className="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/5 p-3">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
          <p className="text-xs text-muted-foreground">
            This converts the applicant into an employee, creates their 201 file, and increases the
            request's filled count. Their recruitment history is kept. This cannot be undone from the
            interface.
          </p>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <FormGrid>
            <Field label="Deployment date" htmlFor="deployment_date" required error={errors.deployment_date?.[0]}>
              <Input
                type="date"
                value={form.deployment_date}
                onChange={(e) => set('deployment_date', e.target.value)}
              />
            </Field>

            <Field label="Supervisor" htmlFor="supervisor_name" error={errors.supervisor_name?.[0]}>
              <Input value={form.supervisor_name} onChange={(e) => set('supervisor_name', e.target.value)} />
            </Field>

            <Field
              label="Employee number"
              htmlFor="employee_number"
              error={errors.employee_number?.[0]}
              hint="Leave blank to generate automatically"
            >
              <Input value={form.employee_number} onChange={(e) => set('employee_number', e.target.value)} />
            </Field>

            <Field label="Biometrics number" htmlFor="biometric_number" error={errors.biometric_number?.[0]}>
              <Input value={form.biometric_number} onChange={(e) => set('biometric_number', e.target.value)} />
            </Field>
          </FormGrid>

          <Field label="Remarks" htmlFor="remarks" error={errors.remarks?.[0]}>
            <Input value={form.remarks} onChange={(e) => set('remarks', e.target.value)} />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              {submitting ? 'Recording…' : 'Record deployment'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
