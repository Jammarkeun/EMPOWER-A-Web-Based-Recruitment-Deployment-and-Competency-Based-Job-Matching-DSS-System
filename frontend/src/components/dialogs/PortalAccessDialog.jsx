import * as React from 'react'
import { Loader2 } from 'lucide-react'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Field } from '@/components/ui/form'
import { TemporaryPassword } from '@/components/TemporaryPassword'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

/**
 * Creates a portal login for an applicant or employee.
 *
 * Opened from the applicant and employee records, where the person is already
 * in context. (It lived in the Users page and was imported from there, which
 * tied the applicant screen's code to the user-administration screen's.)
 */
export function PortalAccessDialog({ open, onOpenChange, applicantId, employeeId, personName }) {
  const [email, setEmail] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)
  const [created, setCreated] = React.useState(null)
  const toast = useToast()

  React.useEffect(() => {
    if (open) {
      setEmail('')
      setErrors({})
      setCreated(null)
    }
  }, [open])

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)

    try {
      const response = await post('/users/portal-access', {
        applicant_id: applicantId,
        employee_id: employeeId,
        email,
      })
      setCreated(response.data)
      toast.success('Portal access created', `${personName} can now sign in with ${email}.`)
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not create portal access', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Portal access for {personName}</DialogTitle>
          <DialogDescription>
            Lets them check their own application status and documents, which removes most of the phone calls asking
            for an update.
          </DialogDescription>
        </DialogHeader>

        {created ? (
          <div className="space-y-4">
            <TemporaryPassword
              email={created.user.email}
              password={created.temporary_password}
              notice={created.notice}
            />

            <DialogFooter>
              <Button onClick={() => onOpenChange(false)}>Done</Button>
            </DialogFooter>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4" noValidate>
            <Field
              label="Email address"
              htmlFor="portal_email"
              required
              error={errors.email?.[0]}
              hint="They sign in with this address"
            >
              <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoFocus />
            </Field>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={submitting}>
                {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
                Create access
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  )
}
