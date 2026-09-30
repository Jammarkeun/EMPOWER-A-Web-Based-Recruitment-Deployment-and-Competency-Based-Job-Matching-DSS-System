import * as React from 'react'
import { useNavigate } from 'react-router-dom'
import { Loader2 } from 'lucide-react'
import { get, post } from '@/lib/api'
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
import { Select } from '@/components/ui/select'
import { Field, FormGrid } from '@/components/ui/form'

export default function NewJobRequestDialog({ open, onOpenChange, onCreated }) {
  const [companies, setCompanies] = React.useState([])
  const [departments, setDepartments] = React.useState([])
  const [positions, setPositions] = React.useState([])
  const [form, setForm] = React.useState(emptyForm())
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()
  const navigate = useNavigate()

  React.useEffect(() => {
    if (!open) return

    setForm(emptyForm())
    setErrors({})
    setDepartments([])

    // Only active clients are offered: an inactive company cannot raise a new
    // request, and the server rejects it anyway.
    get('/clients', { status: 'active', per_page: 100 })
      .then((response) => setCompanies(response.data))
      .catch((error) => toast.error('Could not load client companies', error.message))
  }, [open])

  React.useEffect(() => {
    if (!form.client_company_id) {
      setDepartments([])
      setPositions([])
      return
    }

    get(`/clients/${form.client_company_id}/departments`)
      .then((response) => setDepartments(response.data.filter((d) => d.status === 'active')))
      .catch((error) => {
        setDepartments([])
        // Worth saying out loud: an empty department list looks identical to a
        // client that genuinely has none, and the user cannot finish the form
        // either way.
        toast.error('Could not load departments', error.message)
      })

    // This client's own roles. A position list is never a blocker — a role that
    // is not there yet can simply be named — so a failure here stays quiet.
    get('/positions', { client_company_id: form.client_company_id, status: 'active' })
      .then((response) => setPositions(response.data.positions ?? []))
      .catch(() => setPositions([]))
  }, [form.client_company_id])

  function set(field, value) {
    setForm((current) => ({ ...current, [field]: value }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setSubmitting(true)

    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))

      // "new" is a marker for this form, not an identifier. Sent as-is it would
      // fail the server's exists rule with a message about a position the user
      // never chose.
      if (payload.job_position_id === 'new') delete payload.job_position_id

      // Naming a role and picking one are alternatives, so only the answer the
      // user actually gave is sent.
      if (payload.job_position_id) delete payload.position_title

      const response = await post('/job-requests', payload)

      toast.success('Request recorded', `${response.data.request_code} created. Set its criteria next.`)
      onOpenChange(false)
      onCreated?.()
      navigate(`/job-requests/${response.data.id}`)
    } catch (error) {
      if (error.isValidation) {
        setErrors(error.errors)
      } else {
        toast.error('Could not record request', error.message)
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Record manpower request</DialogTitle>
          <DialogDescription>
            Capture what the client has asked for. Competency criteria and weights are set on the
            request once it exists.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <FormGrid>
            <Field label="Client company" htmlFor="client_company_id" required error={errors.client_company_id?.[0]}>
              <Select
                value={form.client_company_id}
                onChange={(e) => {
                  set('client_company_id', e.target.value)
                  set('client_department_id', '')
                }}
                placeholder="Select a client"
              >
                {companies.map((company) => (
                  <option key={company.id} value={company.id}>
                    {company.company_name}
                  </option>
                ))}
              </Select>
            </Field>

            <Field
              label="Department"
              htmlFor="client_department_id"
              required
              error={errors.client_department_id?.[0]}
              hint={!form.client_company_id ? 'Select a client company first' : undefined}
            >
              <Select
                value={form.client_department_id}
                onChange={(e) => set('client_department_id', e.target.value)}
                placeholder="Select a department"
                disabled={!form.client_company_id}
              >
                {departments.map((department) => (
                  <option key={department.id} value={department.id}>
                    {department.department_name}
                  </option>
                ))}
              </Select>
            </Field>

            {/*
              Pick the client's existing role, or name a new one.
              Choosing from the list keeps the request pointing at the same
              position an applicant applied for; naming a new one creates it, so
              a role the client has not asked for before is on the application
              form from the moment it is first requested.
            */}
            <Field
              label="Position"
              htmlFor="job_position_id"
              required
              error={errors.job_position_id?.[0] ?? errors.position_title?.[0]}
              hint={
                !form.client_company_id
                  ? 'Select a client company first'
                  : positions.length === 0
                    ? 'This client has no positions yet — name the role below'
                    : undefined
              }
            >
              <Select
                id="job_position_id"
                value={form.job_position_id}
                onChange={(e) => set('job_position_id', e.target.value)}
                disabled={!form.client_company_id}
              >
                <option value="">
                  {positions.length === 0 ? 'Name a new role…' : 'Select a position'}
                </option>
                {positions.map((position) => (
                  <option key={position.id} value={position.id}>
                    {position.position_title}
                  </option>
                ))}
                <option value="new">Other — a role not listed here</option>
              </Select>
            </Field>

            {(form.job_position_id === '' || form.job_position_id === 'new') && (
              <Field
                label="New position title"
                htmlFor="position_title"
                required
                error={errors.position_title?.[0]}
                hint="Added to this client's positions and offered to applicants."
              >
                <Input
                  id="position_title"
                  value={form.position_title}
                  onChange={(e) => set('position_title', e.target.value)}
                  placeholder="e.g. Forklift Operator"
                />
              </Field>
            )}

            <Field label="Workers needed" htmlFor="workers_needed" required error={errors.workers_needed?.[0]}>
              <Input
                type="number"
                min="1"
                value={form.workers_needed}
                onChange={(e) => set('workers_needed', e.target.value)}
              />
            </Field>

            <Field label="Date requested" htmlFor="date_requested" required error={errors.date_requested?.[0]}>
              <Input
                type="date"
                value={form.date_requested}
                onChange={(e) => set('date_requested', e.target.value)}
              />
            </Field>

            <Field label="Deployment deadline" htmlFor="deployment_deadline" error={errors.deployment_deadline?.[0]}>
              <Input
                type="date"
                value={form.deployment_deadline}
                onChange={(e) => set('deployment_deadline', e.target.value)}
              />
            </Field>

            <Field label="Required education" htmlFor="required_education" error={errors.required_education?.[0]}>
              <Input
                value={form.required_education}
                onChange={(e) => set('required_education', e.target.value)}
                placeholder="e.g. High School Graduate"
              />
            </Field>

            <Field
              label="Required experience"
              htmlFor="required_experience_months"
              error={errors.required_experience_months?.[0]}
              hint="In months"
            >
              <Input
                type="number"
                min="0"
                value={form.required_experience_months}
                onChange={(e) => set('required_experience_months', e.target.value)}
              />
            </Field>

            <Field label="Minimum age" htmlFor="age_min" error={errors.age_min?.[0]}>
              <Input type="number" value={form.age_min} onChange={(e) => set('age_min', e.target.value)} />
            </Field>

            <Field label="Maximum age" htmlFor="age_max" error={errors.age_max?.[0]}>
              <Input type="number" value={form.age_max} onChange={(e) => set('age_max', e.target.value)} />
            </Field>
          </FormGrid>

          <Field label="Physical requirement" htmlFor="physical_requirement" error={errors.physical_requirement?.[0]}>
            <Input
              value={form.physical_requirement}
              onChange={(e) => set('physical_requirement', e.target.value)}
              placeholder="e.g. Able to lift 15 kg and stand for an 8-hour shift"
            />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Record request
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function emptyForm() {
  return {
    client_company_id: '',
    client_department_id: '',
    job_position_id: '',
    position_title: '',
    workers_needed: '1',
    date_requested: new Date().toISOString().slice(0, 10),
    deployment_deadline: '',
    required_education: '',
    required_experience_months: '',
    age_min: '',
    age_max: '',
    physical_requirement: '',
  }
}
