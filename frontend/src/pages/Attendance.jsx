import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { CalendarClock, CalendarOff, ChevronLeft, ChevronRight, ClipboardCheck, FileSpreadsheet, Info, Upload, UserRound, X } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useApi, useDebounced } from '@/hooks/useApi'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { Pagination } from '@/components/Pagination'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import AttendanceDayDialog from '@/components/attendance/AttendanceDayDialog'
import DtrImportDialog from '@/components/attendance/DtrImportDialog'
import ExceptionDialog from '@/components/attendance/ExceptionDialog'
import {
  DATE_PATTERN,
  DISCREPANCIES,
  REVIEW_STATUS,
  currentMonth,
  discrepancyText,
  employeeAttendancePath,
  formatMinutes,
  monthBounds,
  monthOf,
  monthParam,
  shiftMonth,
  shiftText,
} from '@/lib/attendance'
import { cn, formatDate, formatDateTime } from '@/lib/utils'

const TABS = ['review', 'employees', 'imports', 'exceptions']

/**
 * Attendance (DTR): the one place the DTR is compared with the schedule.
 *
 * The review panel asked for the DTR to be compared with the schedule. This
 * page is that comparison for the whole workforce, in four parts that share a
 * single workflow:
 *
 * - Review queue: every day the comparison flags, for HR to verify, correct or
 *   close. A flagged day is a question, not a finding.
 * - Employees: each worker's month at a glance, and how much of it could be
 *   compared at all.
 * - DTR imports: the files the client sent, and what became of every row.
 * - Leave and holidays: what the agency already knows about a day.
 *
 * Each employee's own day-by-day view, schedule and hand encoding live on
 * their record; every employee shown here links there on the same month, and
 * the employee's tab links back to one day of this queue
 * (`?employee_id=&month=&review=`). Both read the same endpoints and the same
 * comparison, so the two views cannot disagree. Reported, never priced:
 * nothing here is payroll, and nothing records a violation by itself.
 */
export default function Attendance() {
  const { can } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = TABS.includes(searchParams.get('tab')) ? searchParams.get('tab') : 'review'
  const month = monthParam(searchParams.get('month'))

  function update(changes) {
    const next = new URLSearchParams(searchParams)
    Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)))
    if (!('page' in changes)) next.delete('page')
    setSearchParams(next)
  }

  return (
    <div className="space-y-4">
      <PageHeader
        title="Attendance (DTR)"
        description="The client's DTR compared with each employee's schedule. Differences are flagged for HR to review — never treated as findings on their own."
      />

      <Tabs value={tab} onValueChange={(value) => update({ tab: value === 'review' ? '' : value })}>
        <TabsList>
          <TabsTrigger value="review">Review queue</TabsTrigger>
          <TabsTrigger value="employees">Employees</TabsTrigger>
          {can('attendance.manage') && <TabsTrigger value="imports">DTR imports</TabsTrigger>}
          <TabsTrigger value="exceptions">Leave &amp; holidays</TabsTrigger>
        </TabsList>

        {tab !== 'imports' && tab !== 'exceptions' && (
          <MonthPicker month={month} onChange={(value) => update({ month: value === currentMonth() ? '' : value })} />
        )}

        <TabsContent value="review">
          <ReviewQueue month={month} searchParams={searchParams} update={update} />
        </TabsContent>
        <TabsContent value="employees">
          <EmployeeSummary month={month} searchParams={searchParams} update={update} />
        </TabsContent>
        {can('attendance.manage') && (
          <TabsContent value="imports">
            <Imports />
          </TabsContent>
        )}
        <TabsContent value="exceptions">
          <Exceptions />
        </TabsContent>
      </Tabs>
    </div>
  )
}

function MonthPicker({ month, onChange }) {
  const bounds = monthBounds(month)

  return (
    <div className="mt-4 flex flex-wrap items-center gap-2">
      <Button variant="outline" size="icon" onClick={() => onChange(shiftMonth(month, -1))} aria-label="Previous month">
        <ChevronLeft className="h-4 w-4" />
      </Button>
      <Input
        type="month"
        value={month}
        onChange={(e) => e.target.value && onChange(e.target.value)}
        className="h-9 w-40"
        aria-label="Month"
      />
      <Button variant="outline" size="icon" onClick={() => onChange(shiftMonth(month, 1))} aria-label="Next month">
        <ChevronRight className="h-4 w-4" />
      </Button>
      <span className="text-sm text-muted-foreground">
        {formatDate(bounds.from)} – {formatDate(bounds.to)}
      </span>
    </div>
  )
}

function useCompanies() {
  return useApi('/clients', { per_page: 100 }, { cacheKey: 'attendance-companies' })
}

function companyFilter(companies, value, onChange) {
  return {
    id: 'company',
    label: 'All client companies',
    value,
    onChange,
    options: [
      { value: '', label: 'All client companies' },
      ...(companies.data ?? []).map((c) => ({ value: String(c.id), label: c.company_name })),
    ],
  }
}

/**
 * A link where the user may follow it, plain text where they may not: the
 * employee profile needs employees.view, which attendance.view does not imply.
 */
function MaybeLink({ to, className, children }) {
  return to ? (
    <Link to={to} className={className}>
      {children}
    </Link>
  ) : (
    <span className={className}>{children}</span>
  )
}

/** An employee's name, linking to their own DTR on the given month. */
function EmployeeName({ id, name, month }) {
  const { can } = useAuth()

  return (
    <MaybeLink to={can('employees.view') ? employeeAttendancePath(id, month) : null} className="font-semibold hover:text-brand">
      {name}
    </MaybeLink>
  )
}

// ------------------------------------------------------------ review queue

function ReviewQueue({ month, searchParams, update }) {
  const companies = useCompanies()
  const search = searchParams.get('search') ?? ''
  const company = searchParams.get('client_company_id') ?? ''
  const discrepancy = searchParams.get('discrepancy') ?? ''
  const status = searchParams.get('status') ?? 'open'
  const page = Number(searchParams.get('page') ?? 1)
  const debouncedSearch = useDebounced(search)
  const { can } = useAuth()

  // Arriving from an employee's tab: the queue narrowed to them, and one day
  // of it opened. Anything malformed in the address is simply ignored.
  const employeeId = /^\d+$/.test(searchParams.get('employee_id') ?? '') ? searchParams.get('employee_id') : ''
  const linkedDay = employeeId && DATE_PATTERN.test(searchParams.get('review') ?? '') ? searchParams.get('review') : ''
  const [picked, setPicked] = React.useState(null)
  const open = picked ?? (linkedDay ? { employeeId: Number(employeeId), date: linkedDay } : null)

  function closeDay() {
    setPicked(null)
    if (linkedDay) update({ review: '' })
  }

  const { data, meta, loading, error, refetch } = useApi('/attendance/discrepancies', {
    ...monthBounds(month),
    employee_id: employeeId || undefined,
    search: debouncedSearch || undefined,
    client_company_id: company || undefined,
    discrepancy: discrepancy || undefined,
    status,
    page,
    per_page: 25,
  })

  const counts = meta?.status_counts ?? {}
  const gaps = meta?.insufficient_data

  return (
    <div className="mt-4 space-y-4">
      <FilterToolbar
        search={search}
        onSearchChange={(value) => update({ search: value })}
        searchPlaceholder="Search by name, employee or biometrics no.…"
        filters={[
          companyFilter(companies, company, (value) => update({ client_company_id: value })),
          {
            id: 'discrepancy',
            label: 'Any discrepancy',
            value: discrepancy,
            onChange: (value) => update({ discrepancy: value }),
            options: [
              { value: '', label: 'Any discrepancy' },
              ...Object.entries(DISCREPANCIES).map(([value, d]) => ({ value, label: d.label })),
            ],
          },
          {
            id: 'status',
            label: 'Open reviews',
            value: status === 'open' ? '' : status,
            onChange: (value) => update({ status: value }),
            options: [
              { value: '', label: 'Open reviews' },
              ...Object.entries(REVIEW_STATUS).map(([value, s]) => ({ value, label: `${s.label}${counts[value] ? ` (${counts[value]})` : ''}` })),
            ],
          },
        ]}
        resultCount={meta?.total}
        resultLabel="days"
        onClearFilters={() => update({ search: '', client_company_id: '', discrepancy: '', status: '', employee_id: '', review: '' })}
      />

      {employeeId && (
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <Badge tone="info">
            <UserRound className="h-3.5 w-3.5" />
            {data?.[0]?.employee.full_name ?? 'One employee'} only
          </Badge>
          {can('employees.view') && (
            <Link to={employeeAttendancePath(employeeId, month)} className="text-xs text-brand hover:underline">
              Back to their DTR
            </Link>
          )}
          <Button variant="ghost" size="sm" onClick={() => update({ employee_id: '', review: '' })}>
            <X className="h-3.5 w-3.5" />
            Show everyone
          </Button>
        </div>
      )}

      {gaps && (gaps.no_dtr > 0 || gaps.no_schedule > 0 || gaps.awaiting_dtr_days > 0) && (
        <div className="flex gap-2.5 rounded-lg border border-warning/40 bg-warning/[0.06] px-3 py-2.5 text-xs">
          <Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" />
          <p>
            Not enough data to compare everything this month:
            {gaps.no_dtr > 0 && ` ${gaps.no_dtr} employee(s) have no DTR on file at all;`}
            {gaps.no_schedule > 0 && ` ${gaps.no_schedule} have no schedule;`}
            {gaps.awaiting_dtr_days > 0 && ` ${gaps.awaiting_dtr_days} scheduled day(s) are awaiting the DTR.`}{' '}
            These are not flagged here — missing data is not an absence. Import the client’s DTR to compare them.
          </p>
        </div>
      )}

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={8} columns={7} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={ClipboardCheck}
          title={status === 'open' ? 'Nothing waiting for review' : 'No days in this view'}
          description="Days appear here when the DTR on file and the schedule disagree."
        />
      ) : (
        <div className="space-y-3">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Employee</TableHead>
                  <TableHead>Scheduled</TableHead>
                  <TableHead>DTR</TableHead>
                  <TableHead>Discrepancy</TableHead>
                  <TableHead>Review</TableHead>
                  <TableHead className="w-0" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((row) => {
                  const reviewStatus = REVIEW_STATUS[row.review_status]
                  const types = row.discrepancies.length ? row.discrepancies : row.review?.discrepancy_types ?? []

                  return (
                    <TableRow key={`${row.employee.id}-${row.date}`}>
                      <TableCell className="whitespace-nowrap">
                        <span className="inline-block w-9 text-xs text-muted-foreground">{row.weekday}</span>
                        {formatDate(row.date)}
                      </TableCell>
                      <TableCell>
                        <EmployeeName id={row.employee.id} name={row.employee.full_name} month={monthOf(row.date)} />
                        <p className="text-xs text-muted-foreground">
                          <span className="font-mono">{row.employee.employee_number}</span>
                          {row.company && ` · ${row.company}`}
                          {row.department && ` · ${row.department}`}
                        </p>
                      </TableCell>
                      <TableCell className="whitespace-nowrap text-sm">{shiftText(row.schedule)}</TableCell>
                      <TableCell className="whitespace-nowrap font-mono text-sm">
                        {row.time_in ?? '—'} – {row.time_out ?? '—'}
                        {row.time_out && row.time_out_next_day && ' (+1)'}
                      </TableCell>
                      <TableCell>
                        <div className={cn('flex flex-wrap gap-1.5', !row.still_flagged && 'opacity-60')}>
                          {types.map((type) => (
                            <Badge key={type} tone={DISCREPANCIES[type]?.tone ?? 'warning'}>
                              {discrepancyText(type, row)}
                            </Badge>
                          ))}
                        </div>
                        {!row.still_flagged && <p className="mt-1 text-xs text-muted-foreground">No longer shown by the current data</p>}
                      </TableCell>
                      <TableCell>
                        {reviewStatus && <Badge tone={reviewStatus.tone}>{reviewStatus.label}</Badge>}
                        {row.review?.employee_violation_id && <p className="mt-1 text-xs text-muted-foreground">Violation recorded</p>}
                      </TableCell>
                      <TableCell className="text-right">
                        <Button variant="ghost" size="sm" onClick={() => setPicked({ employeeId: row.employee.id, date: row.date })}>
                          Review
                        </Button>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>
          <Pagination meta={meta} onPage={(p) => update({ page: String(p) })} />
        </div>
      )}

      <AttendanceDayDialog
        key={open ? `${open.employeeId}-${open.date}` : 'none'}
        open={open !== null}
        onOpenChange={(value) => !value && closeDay()}
        employeeId={open?.employeeId}
        date={open?.date}
        onChanged={refetch}
        employeeHref={open && can('employees.view') ? employeeAttendancePath(open.employeeId, monthOf(open.date)) : undefined}
      />
    </div>
  )
}

// --------------------------------------------------------------- employees

function EmployeeSummary({ month, searchParams, update }) {
  const { can } = useAuth()
  const canOpenEmployee = can('employees.view')
  const companies = useCompanies()
  const search = searchParams.get('search') ?? ''
  const company = searchParams.get('client_company_id') ?? ''
  const onlyDiscrepancies = searchParams.get('only_discrepancies') === '1'
  const page = Number(searchParams.get('page') ?? 1)
  const debouncedSearch = useDebounced(search)

  const { data, meta, loading, error, refetch } = useApi('/attendance', {
    ...monthBounds(month),
    search: debouncedSearch || undefined,
    client_company_id: company || undefined,
    only_discrepancies: onlyDiscrepancies ? 1 : undefined,
    page,
    per_page: 25,
  })

  return (
    <div className="mt-4 space-y-4">
      <FilterToolbar
        search={search}
        onSearchChange={(value) => update({ search: value })}
        searchPlaceholder="Search by name, employee or biometrics no.…"
        filters={[companyFilter(companies, company, (value) => update({ client_company_id: value }))]}
        viewToggles={[
          {
            id: 'discrepancies',
            label: 'With discrepancies only',
            active: onlyDiscrepancies,
            onToggle: () => update({ only_discrepancies: onlyDiscrepancies ? '' : '1' }),
          },
        ]}
        resultCount={meta?.total}
        resultLabel="employees"
        onClearFilters={() => update({ search: '', client_company_id: '', only_discrepancies: '' })}
      />

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={8} columns={7} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState
          icon={CalendarClock}
          title={onlyDiscrepancies ? 'No discrepancies this month' : 'No employees in this period'}
          description={onlyDiscrepancies ? 'Every day compared so far matched the schedule.' : 'Employees appear here once they are deployed.'}
        />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Employee</TableHead>
                <TableHead className="text-right">Scheduled</TableHead>
                <TableHead className="text-right">Compared</TableHead>
                <TableHead className="text-right">Late</TableHead>
                <TableHead className="text-right">Early departure</TableHead>
                <TableHead className="text-right">Missing punches</TableHead>
                <TableHead className="text-right">No DTR record</TableHead>
                <TableHead className="w-8" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((row) => {
                const s = row.summary
                const to = canOpenEmployee ? employeeAttendancePath(row.employee_id, month) : null

                return (
                  <TableRow key={row.employee_id} className="group">
                    <TableCell>
                      <MaybeLink to={to} className="block">
                        <p className="font-semibold text-foreground transition-colors group-hover:text-brand">{row.full_name}</p>
                        <p className="text-xs text-muted-foreground">
                          <span className="font-mono">{row.employee_number}</span>
                          {row.company && ` · ${row.company}`}
                          {row.department && ` · ${row.department}`}
                        </p>
                      </MaybeLink>
                      <div className="mt-1 flex flex-wrap gap-1.5">
                        {row.employment_status !== 'active' && <StatusBadge status={row.employment_status} />}
                        {s.data_status === 'no_schedule' && <Badge tone="warning">No schedule set</Badge>}
                        {s.data_status === 'no_dtr' && <Badge tone="muted">No DTR on file</Badge>}
                        {s.data_status === 'partial' && <Badge tone="muted">{s.awaiting_dtr_days} day(s) awaiting DTR</Badge>}
                      </div>
                    </TableCell>
                    <Count value={s.scheduled_days} />
                    <Count value={s.compared_days} />
                    <Count value={s.late_days} hint={s.late_minutes} tone="warning" />
                    <Count value={s.early_departure_days} hint={s.early_departure_minutes} tone="warning" />
                    <Count value={s.missing_punch_days} tone="destructive" />
                    <Count value={s.no_record_days} tone="destructive" />
                    <TableCell className="text-right">
                      {to && (
                        <Link to={to} className="text-muted-foreground group-hover:text-brand" aria-label={`Open ${row.full_name}'s DTR`}>
                          <ChevronRight className="h-4 w-4" />
                        </Link>
                      )}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
          <Pagination meta={meta} onPage={(p) => update({ page: String(p) })} />
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

// ----------------------------------------------------------------- imports

const IMPORT_STATUS = {
  draft: ['Draft — not imported', 'warning'],
  completed: ['Imported', 'success'],
  discarded: ['Discarded', 'muted'],
}

function Imports() {
  const [page, setPage] = React.useState(1)
  const [dialog, setDialog] = React.useState(null)
  const { data, meta, loading, error, refetch } = useApi('/attendance/imports', { page, per_page: 20 })

  return (
    <div className="mt-4 space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-2xl text-sm text-muted-foreground">
          Each file the client sent, who brought it in, and what happened to every row. A file is imported only after
          its rows have been checked, and the same file cannot be imported twice.
        </p>
        <Button onClick={() => setDialog({ draftId: null })}>
          <Upload className="h-4 w-4" />
          Import DTR file
        </Button>
      </div>

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={5} columns={6} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState icon={FileSpreadsheet} title="No DTR files imported yet" description="Import a CSV or Excel file of the client's DTR to compare it with the schedules." />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Import</TableHead>
                <TableHead>Client and period</TableHead>
                <TableHead className="text-right">Added</TableHead>
                <TableHead className="text-right">Replaced</TableHead>
                <TableHead className="text-right">Skipped</TableHead>
                <TableHead className="text-right">Problems</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-0" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((row) => (
                <TableRow key={row.id}>
                  <TableCell>
                    <p className="font-mono text-sm font-semibold">{row.import_code}</p>
                    <p className="text-xs text-muted-foreground">
                      {row.original_filename} · {row.uploaded_by} · {formatDateTime(row.uploaded_at)}
                    </p>
                    {row.source_note && <p className="text-xs text-muted-foreground">{row.source_note}</p>}
                  </TableCell>
                  <TableCell className="text-sm">
                    {row.company}
                    <span className="block text-xs text-muted-foreground">
                      {formatDate(row.period_from)} – {formatDate(row.period_to)}
                    </span>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{row.imported_rows}</TableCell>
                  <TableCell className="text-right tabular-nums">{row.replaced_rows}</TableCell>
                  <TableCell className="text-right tabular-nums">{row.unchanged_rows + row.skipped_rows}</TableCell>
                  <TableCell className={cn('text-right tabular-nums', row.error_rows > 0 && 'font-semibold text-destructive')}>
                    {row.error_rows}
                  </TableCell>
                  <TableCell>
                    <Badge tone={IMPORT_STATUS[row.status]?.[1]}>{IMPORT_STATUS[row.status]?.[0]}</Badge>
                    {row.completed_by && <p className="mt-1 text-xs text-muted-foreground">by {row.completed_by}</p>}
                  </TableCell>
                  <TableCell className="text-right">
                    {row.status === 'draft' && (
                      <Button variant="ghost" size="sm" onClick={() => setDialog({ draftId: row.id })}>
                        Continue
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <Pagination meta={meta} onPage={setPage} />
        </div>
      )}

      <DtrImportDialog
        key={dialog?.draftId ?? 'new'}
        open={dialog !== null}
        onOpenChange={(value) => !value && setDialog(null)}
        draftId={dialog?.draftId}
        onDone={refetch}
      />
    </div>
  )
}

// -------------------------------------------------------------- exceptions

function Exceptions() {
  const { can } = useAuth()
  const toast = useToast()
  const companies = useCompanies()
  const [company, setCompany] = React.useState('')
  const [withdrawn, setWithdrawn] = React.useState(false)
  const [page, setPage] = React.useState(1)
  const [adding, setAdding] = React.useState(false)
  const [voiding, setVoiding] = React.useState(null)
  const [reason, setReason] = React.useState('')

  const { data, meta, loading, error, refetch } = useApi('/attendance/exceptions', {
    client_company_id: company || undefined,
    include_withdrawn: withdrawn ? 1 : undefined,
    page,
    per_page: 25,
  })

  async function withdraw() {
    if (reason.trim().length < 5) {
      toast.error('Give a reason', 'Say why the exception is being withdrawn.')
      return
    }
    try {
      await post(`/attendance/exceptions/${voiding.id}/void`, { reason })
      toast.success('Exception withdrawn', 'The days it covered are compared normally again.')
      setVoiding(null)
      refetch()
    } catch (err) {
      toast.error('Could not withdraw the exception', err.message)
    }
  }

  return (
    <div className="mt-4 space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <FilterToolbar
          filters={[companyFilter(companies, company, (value) => { setCompany(value); setPage(1) })]}
          viewToggles={[{ id: 'withdrawn', label: 'Include withdrawn', active: withdrawn, onToggle: () => setWithdrawn(!withdrawn) }]}
          resultCount={meta?.total}
          resultLabel="exceptions"
          onClearFilters={() => {
            setCompany('')
            setWithdrawn(false)
          }}
        />
        {can('attendance.manage') && (
          <Button onClick={() => setAdding(true)}>
            <CalendarOff className="h-4 w-4" />
            Record holiday or closure
          </Button>
        )}
      </div>
      <p className="text-xs text-muted-foreground">
        A scheduled day covered here is shown as leave or holiday instead of a gap in the DTR. Leave for one employee is
        recorded from their Attendance tab.
      </p>

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={5} columns={5} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState icon={CalendarOff} title="No leave or holidays recorded" />
      ) : (
        <div className="space-y-3">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Dates</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Applies to</TableHead>
                <TableHead>Recorded</TableHead>
                <TableHead className="w-0" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((row) => (
                <TableRow key={row.id} className={cn(row.voided_at && 'text-muted-foreground')}>
                  <TableCell className="whitespace-nowrap">
                    {formatDate(row.date_from)}
                    {row.date_to !== row.date_from && ` – ${formatDate(row.date_to)}`}
                  </TableCell>
                  <TableCell>
                    <Badge tone={row.voided_at ? 'muted' : 'info'}>{row.label}</Badge>
                    {row.remarks && <p className="mt-1 text-xs text-muted-foreground">{row.remarks}</p>}
                    {row.voided_at && <p className="mt-1 text-xs">Withdrawn: {row.void_reason}</p>}
                  </TableCell>
                  <TableCell className="text-sm">
                    {row.scope === 'employee' ? (
                      <MaybeLink to={can('employees.view') ? employeeAttendancePath(row.employee_id, monthOf(row.date_from)) : null} className="hover:text-brand">
                        {row.employee_name} <span className="font-mono text-xs text-muted-foreground">{row.employee_number}</span>
                      </MaybeLink>
                    ) : (
                      <>
                        {row.company}
                        <span className="block text-xs text-muted-foreground">{row.department ?? 'Whole client'}</span>
                      </>
                    )}
                  </TableCell>
                  <TableCell className="text-xs text-muted-foreground">
                    {row.recorded_by} · {formatDateTime(row.recorded_at)}
                  </TableCell>
                  <TableCell className="text-right">
                    {can('attendance.manage') && !row.voided_at && (
                      <Button variant="ghost" size="sm" onClick={() => { setReason(''); setVoiding(row) }}>
                        Withdraw
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <Pagination meta={meta} onPage={setPage} />
        </div>
      )}

      <ExceptionDialog open={adding} onOpenChange={setAdding} onSaved={refetch} />
      <ConfirmActionDialog
        open={voiding !== null}
        onOpenChange={(value) => !value && setVoiding(null)}
        title="Withdraw this exception?"
        description="The days it covered will be compared with the schedule again. It stays on file, marked withdrawn."
        details={voiding ? [['Exception', `${voiding.label}, ${formatDate(voiding.date_from)}`]] : []}
        confirmLabel="Withdraw"
        tone="warning"
        onConfirm={withdraw}
      >
        <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason, e.g. Leave was cancelled" aria-label="Reason for withdrawing" />
      </ConfirmActionDialog>
    </div>
  )
}
