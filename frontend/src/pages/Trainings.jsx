import * as React from 'react'
import { Plus, GraduationCap, Loader2 } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
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
import { formatDate } from '@/lib/utils'

export default function Trainings() {
  const { can } = useAuth()
  const [dialogOpen, setDialogOpen] = React.useState(false)
  const { data, loading, error, refetch } = useApi('/trainings', { per_page: 20 })

  return (
    <>
      <PageHeader
        title="Training"
        description="Orientation and skills sessions run before deployment."
        actions={
          can('training.create') && (
            <Button onClick={() => setDialogOpen(true)}>
              <Plus className="h-4 w-4" />
              Schedule training
            </Button>
          )
        }
      />

      <Card>
        {loading ? (
          <SkeletonRows rows={5} columns={5} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={GraduationCap}
            title="No training scheduled"
            description="Schedule a session and enrol applicants who are ready for deployment."
            action={
              can('training.create') ? (
                <Button size="sm" onClick={() => setDialogOpen(true)}>
                  <Plus className="h-4 w-4" />
                  Schedule training
                </Button>
              ) : null
            }
          />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Session</TableHead>
                <TableHead>Date</TableHead>
                <TableHead>Location</TableHead>
                <TableHead>Trainer</TableHead>
                <TableHead>Attendance</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((training) => (
                <TableRow key={training.id}>
                  <TableCell>
                    <p className="font-medium">{training.training_title}</p>
                    <p className="font-mono text-xs text-muted-foreground">{training.training_code}</p>
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-sm">{formatDate(training.training_date)}</TableCell>
                  <TableCell className="text-sm">{training.location}</TableCell>
                  <TableCell className="text-sm">{training.trainer_name}</TableCell>
                  <TableCell className="text-sm tabular-nums">
                    {training.attended_count ?? 0} / {training.enrollments_count ?? 0}
                    <p className="text-xs text-muted-foreground">{training.completed_count ?? 0} completed</p>
                  </TableCell>
                  <TableCell>
                    <StatusBadge status={training.status} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Card>

      <NewTrainingDialog open={dialogOpen} onOpenChange={setDialogOpen} onCreated={refetch} />
    </>
  )
}

function NewTrainingDialog({ open, onOpenChange, onCreated }) {
  const [form, setForm] = React.useState({
    training_title: '',
    training_date: new Date().toISOString().slice(0, 10),
    location: '',
    trainer_name: '',
  })
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (open) setErrors({})
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()
    setErrors({})
    setSubmitting(true)

    try {
      await post('/trainings', form)
      toast.success('Training scheduled')
      onOpenChange(false)
      onCreated()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not schedule training', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Schedule training</DialogTitle>
          <DialogDescription>
            Applicants who complete a session move to "Training Completed" automatically.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Field label="Title" htmlFor="training_title" required error={errors.training_title?.[0]}>
            <Input
              value={form.training_title}
              onChange={(e) => setForm({ ...form, training_title: e.target.value })}
              placeholder="e.g. Food Safety Orientation"
              autoFocus
            />
          </Field>

          <FormGrid>
            <Field label="Date" htmlFor="training_date" required error={errors.training_date?.[0]}>
              <Input
                type="date"
                value={form.training_date}
                onChange={(e) => setForm({ ...form, training_date: e.target.value })}
              />
            </Field>

            <Field label="Trainer" htmlFor="trainer_name" required error={errors.trainer_name?.[0]}>
              <Input
                value={form.trainer_name}
                onChange={(e) => setForm({ ...form, trainer_name: e.target.value })}
              />
            </Field>
          </FormGrid>

          <Field label="Location" htmlFor="location" required error={errors.location?.[0]}>
            <Input value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Schedule
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
