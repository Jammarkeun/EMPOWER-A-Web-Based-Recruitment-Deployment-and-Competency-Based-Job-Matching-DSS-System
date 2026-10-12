import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { CalendarClock, ChevronLeft, ChevronRight, Info } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { formatMinutes } from '@/components/AttendancePanel'
import { cn, formatDate } from '@/lib/utils'
import { Pagination } from './Applicants'

function currentMonth() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

function shiftMonth(month, delta) {
  const [year, m] = month.split('-').map(Number)
  const date = new Date(year, m - 1 + delta, 1)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

function monthBounds(month) {
  const [year, m] = month.split('-').map(Number)
  return { from: `${month}-01`, to: `${month}-${String(new Date(year, m, 0).getDate()).padStart(2, '0')}` }
}

/**
 * Attendance across the deployed workforce, one row per employee.
 *
 * The review panel asked for the DTR to be compared with the schedule; this is
 * that comparison for everybody at once, so HR can see who was absent, late,
 * or missing a punch this month without opening each record. Each row opens
 * the employee's own day-by-day comparison, where the DTR is encoded.
 *
 * Reported, never priced: nothing here is payroll.
 */
export default function Attendance() {
  const [searchParams, setSearchParams] = useSearchParams()

  const month = searchParams.get('month') ?? currentMonth()
  const search = searchParams.get('search') ?? ''
  const company = searchParams.get('client_company_id') ?? ''
  const onlyDiscrepancies = searchParams.get('only_discrepancies') === '1'
  const page = Number(searchParams.get('page') ?? 1)
  const debouncedSearch = useDebounced(search)

  const companies = useApi('/clients', { per_page: 100 }, { cacheKey: 'attendance-companies' })
  const { data, meta, loading, error, refetch } = useApi('/attendance', {
    ...monthBounds(month),
    search: debouncedSearch || undefined,
    client_company_id: company || undefined,
    only_discrepancies: onlyDiscrepancies ? 1 : undefined,
    page,
    per_page: 25,
  })

  function update(key, value) {
    const next = new URLSearchParams(searchParams)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setSearchParams(next)
  }

  const grace = meta?.late_grace_minutes ?? 0

  return (
    <div className="space-y-4">
      <PageHeader
        title="Attendance (DTR)"
        description="Each employee's daily time record compared with their schedule. Encoded from the client company's DTR."
      />

      <div className="flex flex-wrap items-center gap-2">
        <Button variant="outline" size="icon" onClick={() => update('month', shiftMonth(month, -1))} aria-label="Previous month">
          <ChevronLeft className="h-4 w-4" />
        </Button>
        <Input
          type="month"
          value={month}
          onChange={(e) => e.target.value && update('month', e.target.value)}
          className="h-9 w-40"
          aria-label="Month"
        />
        <Button variant="outline" size="icon" onClick={() => update('month', shiftMonth(month, 1))} aria-label="Next month">
          <ChevronRight className="h-4 w-4" />
        </Button>
        <span className="text-sm text-muted-foreground">
          {formatDate(monthBounds(month).from)} – {formatDate(monthBounds(month).to)}
        </span>
      </div>

      <FilterToolbar
        search={search}
        onSearchChange={(value) => update('search', value)}
        searchPlaceholder="Search by name, employee or biometrics no.…"
        filters={[
          {
            id: 'company',
            label: 'All client companies',
            value: company,
            onChange: (value) => update('client_company_id', value),
            options: [
              { value: '', label: 'All client companies' },
              ...(companies.data ?? []).map((c) => ({ value: String(c.id), label: c.company_name })),
            ],
          },
        ]}
        viewToggles={[
          {
            id: 'discrepancies',
            label: 'Needs attention only',
            active: onlyDiscrepancies,
            onToggle: () => update('only_discrepancies', onlyDiscrepancies ? '' : '1'),
          },
        ]}
        resultCount={meta?.total}
        resultLabel="employees"
        onClearFilters={() => setSearchParams(new URLSearchParams(month !== currentMonth() ? { month } : {}))}
      />

      <div className="flex gap-2.5 rounded-lg border bg-muted/40 px-3 py-2.5 text-xs text-muted-foreground">
        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
        <p>
          Absent means a scheduled day, now past, with no time-in or time-out on file. Late is counted
          from the scheduled start{grace > 0 ? ` after a ${grace}-minute allowance` : ', with no grace period'}.
          “Past end” is time recorded after the scheduled time-out — reported, not approved overtime.
        </p>
      </div>

      {loading ? (
        <div className="rounded-panel border bg-card">
          <SkeletonRows rows={8} columns={7} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={CalendarClock}
          title={onlyDiscrepancies ? 'Nothing needs attention' : 'No employees in this period'}
          description={
            onlyDiscrepancies
              ? 'Every employee with a schedule matched it on the days encoded so far.'
              : 'Employees appear here once they are deployed.'
          }
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Employee</TableHead>
                <TableHead className="text-right">Scheduled</TableHead>
                <TableHead className="text-right">Absent</TableHead>
                <TableHead className="text-right">Late</TableHead>
                <TableHead className="text-right">Left early</TableHead>
                <TableHead className="text-right">Past end</TableHead>
                <TableHead className="text-right">Missing punches</TableHead>
                <TableHead className="w-8" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((row) => {
                const s = row.summary
                const to = `/employees/${row.employee_id}?tab=attendance`

                return (
                  <TableRow key={row.employee_id} className="group">
                    <TableCell>
                      <Link to={to} className="block">
                        <p className="font-semibold text-foreground transition-colors group-hover:text-brand">
                          {row.full_name}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          <span className="font-mono">{row.employee_number}</span>
                          {row.company && ` · ${row.company}`}
                          {row.department && ` · ${row.department}`}
                        </p>
                      </Link>
                      <div className="mt-1 flex flex-wrap gap-1.5">
                        {row.employment_status !== 'active' && <StatusBadge status={row.employment_status} />}
                        {!row.has_schedule && <Badge tone="warning">No schedule set</Badge>}
                        {row.has_schedule && row.records_in_period === 0 && s.scheduled_days > 0 && (
                          <Badge tone="muted">No DTR encoded</Badge>
                        )}
                      </div>
                    </TableCell>
                    <Count value={s.scheduled_days} />
                    <Count value={s.days_absent} tone="destructive" />
                    <Count value={s.days_late} hint={s.late_minutes} tone="warning" />
                    <Count value={s.days_undertime} hint={s.undertime_minutes} tone="warning" />
                    <TableCell className="text-right tabular-nums text-sm text-muted-foreground">
                      {formatMinutes(s.overtime_minutes)}
                    </TableCell>
                    <Count value={s.incomplete_records} tone="destructive" />
                    <TableCell className="text-right">
                      <Link to={to} className="text-muted-foreground group-hover:text-brand" aria-label={`Open ${row.full_name}'s DTR`}>
                        <ChevronRight className="h-4 w-4" />
                      </Link>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>

          <Pagination meta={meta} onPage={(p) => update('page', String(p))} />
        </div>
      )}
    </div>
  )
}

function Count({ value, hint, tone }) {
  return (
    <TableCell className="text-right tabular-nums">
      <span
        className={cn(
          'text-sm',
          !value && 'text-muted-foreground',
          value > 0 && tone === 'destructive' && 'font-semibold text-destructive',
          value > 0 && tone === 'warning' && 'font-semibold text-warning'
        )}
      >
        {value || '—'}
      </span>
      {value > 0 && hint > 0 && <span className="block text-xs text-muted-foreground">{formatMinutes(hint)}</span>}
    </TableCell>
  )
}
