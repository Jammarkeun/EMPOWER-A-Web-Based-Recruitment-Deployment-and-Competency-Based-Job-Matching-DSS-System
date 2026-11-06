import { AlertTriangle, CircleAlert, Info } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { CHECK_LEVEL, SCHEDULE_STATUS, WEEKDAYS } from '@/lib/attendance'
import { cn, formatDate, formatDateTime } from '@/lib/utils'

/*
 * A coordinator's schedule submission, read the same way by HR (who decides)
 * and the coordinator (who wrote it): the proposal against the schedule it
 * would replace, what the server's checks found, and every step so far.
 */

export function ScheduleStatusBadge({ status }) {
  const meta = SCHEDULE_STATUS[status]
  return <Badge tone={meta?.tone ?? 'muted'}>{meta?.label ?? status}</Badge>
}

function daysText(days) {
  if (!days?.length) return '—'
  return WEEKDAYS.filter(([value]) => days.includes(value)).map(([, label]) => label).join(' ')
}

function restDaysText(days) {
  const rest = WEEKDAYS.filter(([value]) => !(days ?? []).includes(value)).map(([, label]) => label)
  return rest.length ? rest.join(' ') : 'None'
}

function shift(s) {
  if (!s) return '—'
  return `${s.shift_start}–${s.shift_end}${s.crosses_midnight ? ' (ends next day)' : ''}`
}

function breakText(s) {
  if (!s?.break_minutes) return 'None'
  return `${s.break_start ? `${s.break_start}, ` : ''}${s.break_minutes} min`
}

function period(s) {
  if (!s) return '—'
  return `${formatDate(s.effective_from)} – ${s.effective_to ? formatDate(s.effective_to) : 'until changed'}`
}

/**
 * The proposed schedule beside the approved one it would replace, with the
 * rows that differ marked - the change HR is asked to approve.
 */
export function ScheduleCompare({ current, proposed, changes = [] }) {
  const rows = [
    ['Applies', period, 'period'],
    ['Work days', (s) => daysText(s?.work_days), 'work_days'],
    ['Rest days', (s) => (s ? restDaysText(s.work_days) : '—'), 'work_days'],
    ['Shift', shift, ['shift_start', 'shift_end']],
    ['Break (not deducted)', breakText, ['break_start', 'break_minutes']],
  ]
  const changed = (keys) => [].concat(keys).some((k) => changes.includes(k))

  return (
    <div className="overflow-x-auto rounded-md border">
      <table className="w-full text-sm">
        <thead className="bg-muted/40 text-xs text-muted-foreground">
          <tr>
            <th scope="col" className="px-3 py-2 text-left font-medium" />
            <th scope="col" className="px-3 py-2 text-left font-medium">Approved now</th>
            <th scope="col" className="px-3 py-2 text-left font-medium">Proposed</th>
          </tr>
        </thead>
        <tbody>
          {rows.map(([label, render, keys]) => {
            const differs = current && keys !== 'period' && changed(keys)
            return (
              <tr key={label} className="border-t">
                <th scope="row" className="whitespace-nowrap px-3 py-2 text-left text-xs font-medium text-muted-foreground">
                  {label}
                </th>
                <td className="px-3 py-2 text-muted-foreground">{current ? render(current) : label === 'Applies' ? 'No approved schedule' : '—'}</td>
                <td className={cn('px-3 py-2', differs && 'font-semibold text-foreground')}>
                  {render(proposed)}
                  {differs && <span className="sr-only"> (changed)</span>}
                  {differs && (
                    <Badge tone="info" className="ml-2">
                      Changed
                    </Badge>
                  )}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

const CHECK_ICONS = { error: CircleAlert, warning: AlertTriangle, info: Info }

/** What the server found about the proposal, as of now. */
export function ScheduleChecks({ checks }) {
  if (!checks?.length) return null

  return (
    <ul className="space-y-2" aria-label="Checks on this schedule">
      {checks.map((check) => {
        const Icon = CHECK_ICONS[check.level] ?? Info
        const meta = CHECK_LEVEL[check.level] ?? CHECK_LEVEL.info
        return (
          <li
            key={check.code}
            className={cn(
              'flex gap-2.5 rounded-md border px-3 py-2 text-sm',
              check.level === 'error' && 'border-destructive/40 bg-destructive/[0.05]',
              check.level === 'warning' && 'border-warning/40 bg-warning/[0.06]'
            )}
          >
            <Icon
              className={cn(
                'mt-0.5 h-4 w-4 shrink-0',
                check.level === 'error' ? 'text-destructive' : check.level === 'warning' ? 'text-warning' : 'text-muted-foreground'
              )}
              aria-hidden="true"
            />
            <p>
              <span className="font-medium">{meta.label}: </span>
              {check.message}
            </p>
          </li>
        )
      })}
    </ul>
  )
}

const FIELD_LABELS = {
  effective_from: 'Start',
  effective_to: 'End',
  work_days: 'Work days',
  shift_start: 'Shift start',
  shift_end: 'Shift end',
  break_start: 'Break start',
  break_minutes: 'Break (min)',
  remarks: 'Remarks',
  source_note: 'Source',
}

function fieldValue(field, value) {
  if (value == null || value === '') return '—'
  if (field === 'work_days') return daysText(value)
  return String(value)
}

/** Every step: who, when, and what they wrote or changed. */
export function ScheduleHistory({ events }) {
  if (!events?.length) return null

  return (
    <ol className="space-y-3 border-l pl-4">
      {events.map((event) => (
        <li key={event.id} className="relative text-sm">
          <span className="absolute -left-[21px] top-1.5 h-2 w-2 rounded-full bg-border" aria-hidden="true" />
          <p className="font-medium">{event.label}</p>
          <p className="text-xs text-muted-foreground">
            {event.by} · {formatDateTime(event.at)}
          </p>
          {event.note && event.label !== 'Edited' && <p className="mt-0.5 text-sm">“{event.note}”</p>}
          {event.changes && (
            <ul className="mt-1 space-y-0.5 text-xs text-muted-foreground">
              {Object.entries(event.changes).map(([field, change]) => (
                <li key={field}>
                  {FIELD_LABELS[field] ?? field}: {fieldValue(field, change.before)} → {fieldValue(field, change.after)}
                </li>
              ))}
            </ul>
          )}
        </li>
      ))}
    </ol>
  )
}
