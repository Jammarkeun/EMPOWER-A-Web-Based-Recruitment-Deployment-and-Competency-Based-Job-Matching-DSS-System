import { Input, NumberInput } from '@/components/ui/input'
import { Field, FormGrid } from '@/components/ui/form'
import { WEEKDAYS } from '@/lib/attendance'
import { cn } from '@/lib/utils'

/** Whether the shift as typed ends on the next day (a night shift). */
export function crossesMidnight(form) {
  return Boolean(form.shift_end && form.shift_start && form.shift_end <= form.shift_start)
}

/**
 * The days and hours of a schedule: work days, shift and break.
 *
 * Shared by HR's own schedule dialog and the coordinator's schedule form, so
 * a schedule is written the same way whoever writes it. A day not ticked is a
 * rest day; a shift that ends at or before its start runs into the next day.
 */
export default function ScheduleFields({ form, setForm, errors = {}, idPrefix = '' }) {
  const id = (name) => `${idPrefix}${name}`
  const set = (key) => (event) => setForm((f) => ({ ...f, [key]: event.target.value }))

  function toggleDay(day) {
    setForm((f) => ({
      ...f,
      work_days: f.work_days.includes(day) ? f.work_days.filter((d) => d !== day) : [...f.work_days, day].sort(),
    }))
  }

  return (
    <>
      <Field
        label="Work days"
        htmlFor={id('work_days')}
        required
        error={errors.work_days?.[0]}
        hint="Days not ticked are rest days."
      >
        <div className="flex flex-wrap gap-1.5" role="group" aria-label="Work days">
          {WEEKDAYS.map(([value, label]) => (
            <button
              key={value}
              type="button"
              onClick={() => toggleDay(value)}
              aria-pressed={form.work_days.includes(value)}
              className={cn(
                'h-8 w-12 rounded-control border text-xs font-medium transition-colors',
                form.work_days.includes(value)
                  ? 'border-primary bg-primary text-primary-foreground'
                  : 'bg-card text-muted-foreground hover:bg-accent'
              )}
            >
              {label}
            </button>
          ))}
        </div>
      </Field>

      <FormGrid>
        <Field label="Shift starts" htmlFor={id('shift_start')} required error={errors.shift_start?.[0]}>
          <Input type="time" value={form.shift_start} onChange={set('shift_start')} />
        </Field>
        <Field
          label="Shift ends"
          htmlFor={id('shift_end')}
          required
          error={errors.shift_end?.[0]}
          hint={crossesMidnight(form) ? 'Ends the next day (night shift).' : undefined}
        >
          <Input type="time" value={form.shift_end} onChange={set('shift_end')} />
        </Field>
      </FormGrid>

      <FormGrid>
        <Field label="Break starts" htmlFor={id('break_start')} error={errors.break_start?.[0]} hint="Optional.">
          <Input type="time" value={form.break_start} onChange={set('break_start')} />
        </Field>
        <Field label="Break length (minutes)" htmlFor={id('break_minutes')} error={errors.break_minutes?.[0]} hint="Shown only; never deducted.">
          <NumberInput value={form.break_minutes} onChange={set('break_minutes')} min={1} max={600} />
        </Field>
      </FormGrid>
    </>
  )
}

/** The fields as the API takes them. */
export function scheduleBody(form) {
  return {
    work_days: form.work_days,
    shift_start: form.shift_start,
    shift_end: form.shift_end,
    break_start: form.break_start || null,
    break_minutes: form.break_minutes === '' || form.break_minutes == null ? null : Number(form.break_minutes),
    remarks: form.remarks?.trim() || null,
  }
}
