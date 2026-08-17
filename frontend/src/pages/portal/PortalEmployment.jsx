import * as React from 'react'
import { Loader2, FileWarning } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
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
import { formatDate } from '@/lib/utils'

export default function PortalEmployment() {
  const { data, loading, error, refetch } = useApi('/portal/employment')
  const [resignOpen, setResignOpen] = React.useState(false)

  if (loading) return <LoadingState label="Loading your employment record…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const canResign = data.status === 'active' && !data.resignation

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">My employment</h1>
          <p className="text-sm text-muted-foreground">
            Employee number <span className="font-mono">{data.employee_number}</span>
          </p>
        </div>
        <StatusBadge status={data.status} />
      </div>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Current assignment</CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-3 sm:grid-cols-2">
            {[
              ['Company', data.current.company],
              ['Department', data.current.department],
              ['Position', data.current.position],
              ['Supervisor', data.current.supervisor],
              ['Start date', formatDate(data.hire_date)],
              ['Biometrics number', data.biometric_number],
            ]
              .filter(([, value]) => value)
              .map(([label, value]) => (
                <div key={label}>
                  <dt className="text-xs text-muted-foreground">{label}</dt>
                  <dd className="text-sm font-medium">{value}</dd>
                </div>
              ))}
          </dl>
        </CardContent>
      </Card>

      {data.deployments?.length > 1 && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Assignment history</CardTitle>
          </CardHeader>
          <CardContent className="divide-y">
            {data.deployments.map((deployment) => (
              <div key={deployment.code} className="py-2.5">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{deployment.position}</p>
                    <p className="text-xs text-muted-foreground">
                      {deployment.company}
                      {deployment.department && ` · ${deployment.department}`}
                    </p>
                  </div>
                  <Badge tone={deployment.status === 'active' ? 'success' : 'muted'}>
                    {deployment.status}
                  </Badge>
                </div>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {formatDate(deployment.from)}
                  {deployment.to ? ` to ${formatDate(deployment.to)}` : ' — present'}
                </p>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      {/*
        Disciplinary records are shown to the employee deliberately. A record
        they cannot see is one they cannot answer, and the agency's own process
        requires them to be informed of a violation anyway.
      */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Disciplinary record</CardTitle>
        </CardHeader>
        <CardContent>
          {!data.violations?.length ? (
            <EmptyState
              icon={FileWarning}
              title="No violations on record"
              description="Your disciplinary record is clear."
            />
          ) : (
            <div className="divide-y">
              {data.violations.map((violation, index) => (
                <div key={index} className="py-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone="warning">{violation.type}</Badge>
                    <span className="text-xs text-muted-foreground">{formatDate(violation.date)}</span>
                  </div>
                  <p className="mt-1.5 text-sm">{violation.description}</p>
                  {violation.penalty && (
                    <p className="mt-0.5 text-xs text-muted-foreground">Penalty: {violation.penalty}</p>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {data.resignation ? (
        <Card className="border-warning/40 bg-warning/5">
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Your resignation</CardTitle>
            <CardDescription>Filed {formatDate(data.resignation.filed_on)}</CardDescription>
          </CardHeader>
          <CardContent>
            <dl className="grid gap-3 sm:grid-cols-3">
              <div>
                <dt className="text-xs text-muted-foreground">Last working day</dt>
                <dd className="text-sm font-medium">{formatDate(data.resignation.exit_date)}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Clearance</dt>
                <dd className="text-sm font-medium capitalize">{data.resignation.clearance_status}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Status</dt>
                <dd className="text-sm font-medium capitalize">{data.resignation.status}</dd>
              </div>
            </dl>
            <p className="mt-3 text-xs text-muted-foreground">
              HR will contact you regarding clearance. Your employment continues until clearance is
              completed.
            </p>
          </CardContent>
        </Card>
      ) : canResign ? (
        <Card>
          <CardContent className="flex flex-wrap items-center justify-between gap-3 pt-5">
            <div>
              <p className="text-sm font-medium">Need to resign?</p>
              <p className="text-sm text-muted-foreground">
                You can file your resignation here. HR will then contact you about clearance.
              </p>
            </div>
            <Button variant="outline" onClick={() => setResignOpen(true)}>
              File resignation
            </Button>
          </CardContent>
        </Card>
      ) : null}

      <ResignationDialog open={resignOpen} onOpenChange={setResignOpen} onFiled={refetch} />
    </div>
  )
}

function ResignationDialog({ open, onOpenChange, onFiled }) {
  const [form, setForm] = React.useState({
    reason: '',
    filing_date: new Date().toISOString().slice(0, 10),
    rendering_days: '30',
    exit_date: '',
  })
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) setErrors({})
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)

    try {
      await post('/portal/resignation', {
        ...form,
        rendering_days: form.rendering_days || undefined,
        exit_date: form.exit_date || undefined,
      })
      toast.success('Resignation submitted', 'HR will contact you about clearance.')
      onOpenChange(false)
      onFiled()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not submit resignation', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>File your resignation</DialogTitle>
          <DialogDescription>
            This notifies HR. Your employment continues until clearance is completed, so please keep
            reporting for work until you are told otherwise.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Field label="Reason for resigning" htmlFor="reason" required error={errors.reason?.[0]}>
            <Textarea
              value={form.reason}
              onChange={(e) => setForm({ ...form, reason: e.target.value })}
              autoFocus
            />
          </Field>

          <FormGrid>
            <Field label="Date of filing" htmlFor="filing_date" required error={errors.filing_date?.[0]}>
              <Input
                type="date"
                value={form.filing_date}
                onChange={(e) => setForm({ ...form, filing_date: e.target.value })}
              />
            </Field>

            <Field
              label="Notice period"
              htmlFor="rendering_days"
              error={errors.rendering_days?.[0]}
              hint="Usually 30 days"
            >
              <Input
                type="number"
                value={form.rendering_days}
                onChange={(e) => setForm({ ...form, rendering_days: e.target.value })}
              />
            </Field>
          </FormGrid>

          <Field
            label="Proposed last working day"
            htmlFor="exit_date"
            error={errors.exit_date?.[0]}
            hint="HR will confirm this with you"
          >
            <Input
              type="date"
              value={form.exit_date}
              onChange={(e) => setForm({ ...form, exit_date: e.target.value })}
            />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Submit resignation
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
