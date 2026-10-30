import * as React from 'react'
import { Loader2 } from 'lucide-react'
import { get, post } from '@/lib/api'
import { useApi } from '@/hooks/useApi'
import { useToast } from '@/components/ui/toast'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Field, FormGrid } from '@/components/ui/form'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { EXCEPTION_TYPES } from '@/lib/attendance'
import { todayISO } from '@/lib/utils'

/**
 * Records leave, a holiday, or a day the client did not operate.
 *
 * For one employee (opened from their DTR tab), or for everybody placed at a
 * client - optionally one department - which is how a holiday or a plant
 * shutdown is entered once instead of per worker. A scheduled day it covers
 * is shown as leave or holiday rather than as a gap in the DTR.
 */
export default function ExceptionDialog({ open, onOpenChange, employee, onSaved }) {
  const toast = useToast()
  const fixedEmployee = Boolean(employee)
  const blank = React.useCallback(
    () => ({
      scope: fixedEmployee ? 'employee' : 'client',
      client_company_id: '',
      client_department_id: '',
      exception_type: fixedEmployee ? 'approved_leave' : 'holiday',
      date_from: todayISO(),
      date_to: todayISO(),
      remarks: '',
    }),
    [fixedEmployee]
  )

  const [form, setForm] = React.useState(blank)
  const [errors, setErrors] = React.useState({})
  const [saving, setSaving] = React.useState(false)
  const [departments, setDepartments] = React.useState([])

  const companies = useApi('/clients', { per_page: 100 }, { cacheKey: 'attendance-companies', enabled: open && !fixedEmployee })

  React.useEffect(() => {
    if (open) {
      setForm(blank())
      setErrors({})
    }
  }, [open, blank])

  React.useEffect(() => {
    if (!form.client_company_id) {
      setDepartments([])
      return
    }
    get(`/clients/${form.client_company_id}/departments`)
      .then((response) => setDepartments(response.data ?? []))
      .catch(() => setDepartments([]))
  }, [form.client_company_id])

  function set(field, value) {
    setForm((current) => ({ ...current, [field]: value, ...(field === 'client_company_id' ? { client_department_id: '' } : {}) }))
  }

  async function submit(event) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      const body = {
        scope: form.scope,
        exception_type: form.exception_type,
        date_from: form.date_from,
        date_to: form.date_to,
        remarks: form.remarks || null,
        ...(form.scope === 'employee'
          ? { employee_id: employee.id }
          : { client_company_id: form.client_company_id || null, client_department_id: form.client_department_id || null }),
      }
      const response = await post('/attendance/exceptions', body)
      toast.success(response.message, 'Scheduled days it covers are no longer counted as gaps in the DTR.')
      onOpenChange(false)
      onSaved?.()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Could not record the exception', error.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={(value) => !saving && onOpenChange(value)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{fixedEmployee ? 'Record leave or exception' : 'Record a holiday or client non-working day'}</DialogTitle>
          <DialogDescription>
            {fixedEmployee
              ? `For ${employee.full_name}. Record only leave or exceptions the agency has confirmed.`
              : 'Applies to everybody placed at the client on those days, or to one department.'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-4" noValidate>
          {!fixedEmployee && (
            <FormGrid>
              <Field label="Client company" htmlFor="exception_client" required error={errors.client_company_id?.[0]}>
                <Select id="exception_client" value={form.client_company_id} onChange={(e) => set('client_company_id', e.target.value)}>
                  <option value="">Choose a client…</option>
                  {(companies.data ?? []).map((c) => (
                    <option key={c.id} value={String(c.id)}>
                      {c.company_name}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Department" htmlFor="exception_department" error={errors.client_department_id?.[0]} hint="Leave blank for the whole client.">
                <Select
                  id="exception_department"
                  value={form.client_department_id}
                  onChange={(e) => set('client_department_id', e.target.value)}
                  disabled={!departments.length}
                >
                  <option value="">Whole client</option>
                  {departments.map((d) => (
                    <option key={d.id} value={String(d.id)}>
                      {d.department_name}
                    </option>
                  ))}
                </Select>
              </Field>
            </FormGrid>
          )}

          <Field label="Type" htmlFor="exception_type" required error={errors.exception_type?.[0]}>
            <Select id="exception_type" value={form.exception_type} onChange={(e) => set('exception_type', e.target.value)}>
              {EXCEPTION_TYPES.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </Select>
          </Field>

          <FormGrid>
            <Field label="From" htmlFor="exception_from" required error={errors.date_from?.[0]}>
              <Input type="date" value={form.date_from} onChange={(e) => set('date_from', e.target.value)} />
            </Field>
            <Field label="To" htmlFor="exception_to" required error={errors.date_to?.[0]}>
              <Input type="date" value={form.date_to} min={form.date_from} onChange={(e) => set('date_to', e.target.value)} />
            </Field>
          </FormGrid>

          <Field
            label="Remarks"
            htmlFor="exception_remarks"
            required={form.exception_type === 'other'}
            error={errors.remarks?.[0]}
          >
            <Input
              value={form.remarks}
              onChange={(e) => set('remarks', e.target.value)}
              placeholder="e.g. Leave form approved by the client supervisor"
            />
          </Field>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              Record
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
