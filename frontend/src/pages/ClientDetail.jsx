import * as React from 'react'
import { useParams, Link } from 'react-router-dom'
import { Plus, Loader2, Building2 } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { StatusBadge } from '@/components/ui/badge'
import { Field, FormGrid } from '@/components/ui/form'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

export default function ClientDetail() {
  const { id } = useParams()
  const { can } = useAuth()
  const { data, loading, error, refetch } = useApi(`/clients/${id}`)
  const [dialogOpen, setDialogOpen] = React.useState(false)

  if (loading) return <LoadingState label="Loading client…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  return (
    <>
      <PageHeader
        breadcrumbs={[{ label: 'Client companies', to: '/clients' }, { label: data.company_name }]}
        title={data.company_name}
        description={`${data.business_type} · ${data.office_address}`}
        actions={
          can('clients.update') && (
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Add department
            </Button>
          )
        }
      />

      <div className="mb-5 flex flex-wrap items-center gap-2">
        <StatusBadge status={data.status} />
        <span className="font-mono text-xs text-muted-foreground">{data.company_code}</span>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle>Contact details</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="space-y-3">
              {[
                ['Contact person', data.contact_person],
                ['Contact number', data.contact_number],
                ['Email', data.email],
                ['Office address', data.office_address],
                ['Notes', data.notes],
              ]
                .filter(([, v]) => v)
                .map(([label, value]) => (
                  <div key={label}>
                    <dt className="text-xs text-muted-foreground">{label}</dt>
                    <dd className="text-sm">{value}</dd>
                  </div>
                ))}
            </dl>
          </CardContent>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Departments</CardTitle>
            <CardDescription>
              Manpower requests are raised against a specific department, so the deployment record
              shows exactly where a worker was placed.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {!data.departments?.length ? (
              <EmptyState
                icon={Building2}
                title="No departments yet"
                description="Add the departments this client places workers in."
                action={
                  can('clients.update') ? (
                    <Button size="sm" onClick={() => setDialogOpen(true)}>
                      Add department
                    </Button>
                  ) : null
                }
              />
            ) : (
              <ul className="divide-y">
                {data.departments.map((department) => (
                  <li key={department.id} className="flex items-center justify-between gap-3 py-2.5">
                    <div>
                      <p className="text-sm font-medium">{department.department_name}</p>
                      <p className="font-mono text-xs text-muted-foreground">{department.department_code}</p>
                    </div>
                    <div className="flex items-center gap-3">
                      <span className="text-xs text-muted-foreground">
                        {department.job_requests_count ?? 0} requests
                      </span>
                      <StatusBadge status={department.status} />
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>

      <NewDepartmentDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        clientId={id}
        onCreated={refetch}
      />
    </>
  )
}

function NewDepartmentDialog({ open, onOpenChange, clientId, onCreated }) {
  const [form, setForm] = React.useState({ department_code: '', department_name: '' })
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setForm({ department_code: '', department_name: '' })
      setErrors({})
    }
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setSubmitting(true)

    try {
      await post(`/clients/${clientId}/departments`, form)
      toast.success('Department added')
      onOpenChange(false)
      onCreated()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not add department', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add department</DialogTitle>
          <DialogDescription>
            Names are unique per client, so several companies can each have a "Packaging" department.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <FormGrid>
            <Field label="Department name" htmlFor="department_name" required error={errors.department_name?.[0]}>
              <Input
                value={form.department_name}
                onChange={(e) => setForm({ ...form, department_name: e.target.value })}
                placeholder="e.g. Production"
                autoFocus
              />
            </Field>

            <Field
              label="Short code"
              htmlFor="department_code"
              required
              error={errors.department_code?.[0]}
              hint="Used on request codes"
            >
              <Input
                value={form.department_code}
                onChange={(e) => setForm({ ...form, department_code: e.target.value.toUpperCase() })}
                placeholder="e.g. PROD"
              />
            </Field>
          </FormGrid>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Add department
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
