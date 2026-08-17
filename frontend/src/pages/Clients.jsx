import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Plus, Search, Building2, Loader2 } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { Field, FormGrid } from '@/components/ui/form'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Pagination } from './Applicants'

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
          <SkeletonRows rows={5} columns={5} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={Building2}
            title="No client companies"
            description="Add a client company before recording their manpower requests."
            action={
              can('clients.create') ? (
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
                    <TableCell className="text-sm tabular-nums">{client.employees_count ?? 0}</TableCell>
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

function NewClientDialog({ open, onOpenChange, onCreated }) {
  const [form, setForm] = React.useState({
    company_name: '',
    business_type: '',
    contact_person: '',
    contact_number: '',
    email: '',
    office_address: '',
  })
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setForm({
        company_name: '',
        business_type: '',
        contact_person: '',
        contact_number: '',
        email: '',
        office_address: '',
      })
      setErrors({})
    }
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setSubmitting(true)

    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
      await post('/clients', payload)
      toast.success('Client added', `${form.company_name} is now available for manpower requests.`)
      onOpenChange(false)
      onCreated()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not add client', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add client company</DialogTitle>
          <DialogDescription>
            Client companies do not use the system directly. HR maintains their details here.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
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
              <Input
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

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Add client
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
