import * as React from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { FileWarning, ChevronRight, X } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Select } from '@/components/ui/select'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { formatDate, humanise } from '@/lib/utils'

/**
 * Every resignation and termination, opened to the case on the employee's
 * record.
 *
 * "Needs action" is the default view: cases still waiting on somebody - a
 * resignation awaiting clearance, a termination request awaiting an
 * administrator's review or HR's clearance. Closed cases, withdrawn ones
 * included, stay in the full history.
 *
 * Terminations can also be looked at by stage, which is how the two people
 * involved work through them: the administrator looks for requests awaiting
 * review, HR for those returned to it or approved and awaiting clearance.
 * Each termination opens its own case page, where notifications about it lead.
 */
const STATUS_LABELS = {
  for_review: 'Awaiting review',
  info_requested: 'Waiting on employee',
}

const TERMINATION_STAGES = [
  ['', 'Every stage'],
  ['awaiting_review', 'Awaiting administrator review'],
  ['returned', 'With HR (draft or returned)'],
  ['approved', 'Approved - clearance and final processing'],
  ['finalized', 'Finalised'],
  ['closed', 'Rejected or withdrawn'],
]

/** The groups a dashboard figure opens this list on, in the figure's words. */
const STATUS_FILTERS = {
  awaiting_clearance: 'resignations awaiting clearance',
  info_requested: 'resignations waiting on the employee',
  for_review: 'terminations awaiting review',
}

export default function Separations() {
  const [searchParams, setSearchParams] = useSearchParams()
  // In the address bar, so a dashboard figure opens exactly the cases it counted.
  const type = searchParams.get('type') ?? ''
  const status = searchParams.get('status') ?? ''
  const stage = searchParams.get('stage') ?? ''
  const [source, setSource] = React.useState('')
  const [openOnly, setOpenOnly] = React.useState(true)
  const { data, loading, error, refetch } = useApi('/separations', {
    type: type || undefined,
    status: status || undefined,
    stage: stage || undefined,
    source: source || undefined,
    open_only: openOnly ? 1 : undefined,
  })

  function updateFilter(key, next) {
    const params = new URLSearchParams(searchParams)
    if (next) params.set(key, next)
    else params.delete(key)
    setSearchParams(params)
  }

  return (
    <>
      <PageHeader
        title="Separations"
        description="Resignations and terminations across the deployed workforce. Each is processed on the employee's record."
      />

      <Card className="mb-4">
        <CardContent className="flex flex-wrap items-center gap-3 pt-5">
          <div className="flex items-center rounded-control border border-border bg-muted/30 p-0.5 text-xs font-medium">
            {[
              [true, 'Needs action'],
              [false, 'All, including closed'],
            ].map(([value, label]) => (
              <button
                key={label}
                type="button"
                onClick={() => setOpenOnly(value)}
                className={
                  openOnly === value
                    ? 'rounded-[4px] bg-card px-2.5 py-1 font-semibold text-foreground shadow-xs'
                    : 'rounded-[4px] px-2.5 py-1 text-muted-foreground hover:text-foreground'
                }
              >
                {label}
              </button>
            ))}
          </div>
          <Select
            value={type}
            onChange={(e) => {
              const params = new URLSearchParams(searchParams)
              if (e.target.value) params.set('type', e.target.value)
              else params.delete('type')
              // A termination stage means nothing for resignations.
              if (e.target.value !== 'termination') params.delete('stage')
              setSearchParams(params)
            }}
            placeholder="All separations"
            aria-label="Filter by separation type"
            className="sm:w-56"
          >
            <option value="resignation">Resignations</option>
            <option value="termination">Terminations</option>
          </Select>
          <Select
            value={source}
            onChange={(e) => setSource(e.target.value)}
            placeholder="Filed anywhere"
            aria-label="Filter by where it was filed"
            className="sm:w-56"
          >
            <option value="portal">Requested in the portal</option>
            <option value="office">Filed at the office</option>
          </Select>
          {type === 'termination' && (
            <Select
              value={stage}
              onChange={(e) => updateFilter('stage', e.target.value)}
              aria-label="Filter terminations by stage"
              className="sm:w-72"
            >
              {TERMINATION_STAGES.map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          )}
        </CardContent>
      </Card>

      {status && (
        <div className="mb-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          <span>
            Showing{' '}
            <span className="font-medium text-foreground">
              {STATUS_FILTERS[status] ?? humanise(status).toLowerCase()}
            </span>
          </span>
          <Button variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={() => updateFilter('status', '')}>
            <X className="h-3 w-3" />
            Show every case
          </Button>
        </div>
      )}

      <Card>
        {loading ? (
          <SkeletonRows rows={5} columns={6} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={FileWarning}
            title={status || stage ? 'No cases in this group' : openOnly ? 'Nothing waiting' : 'No separations recorded'}
            description={
              status || stage
                ? 'Show every case to see the rest.'
                : openOnly
                  ? 'No resignation is awaiting clearance and no termination request is awaiting review or clearance.'
                  : "Resignations and terminations are filed from an employee's record."
            }
          />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Employee</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Reason</TableHead>
                <TableHead>Filed</TableHead>
                <TableHead>Exit date</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-0" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((record) => {
                // A termination is worked on its own case page; a resignation on the employee's record.
                const caseLink =
                  record.type === 'termination' ? `/separations/terminations/${record.id}` : `/employees/${record.employee_id}`

                return (
                <TableRow key={`${record.type}-${record.id}`}>
                  <TableCell>
                    <Link
                      to={`/employees/${record.employee_id}`}
                      className="font-medium text-primary hover:underline"
                    >
                      {record.employee_name}
                    </Link>
                    <p className="font-mono text-xs text-muted-foreground">{record.employee_number}</p>
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-col items-start gap-1">
                      <Badge tone={record.type === 'termination' ? 'destructive' : 'muted'}>
                        {humanise(record.type)}
                      </Badge>
                      {record.source === 'portal' && <Badge tone="info">Portal request</Badge>}
                      {record.reference && <span className="font-mono text-xs text-muted-foreground">{record.reference}</span>}
                    </div>
                  </TableCell>
                  <TableCell className="max-w-xs text-sm">
                    {record.reason}
                    {record.filed_by && (
                      <span className="block text-xs text-muted-foreground">Requested by {record.filed_by}</span>
                    )}
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                    {formatDate(record.date)}
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                    {formatDate(record.exit_date)}
                    {/* What the employee asked for, until HR confirms a date. */}
                    {!record.exit_date && record.requested_exit_date && (
                      <span className="block text-xs">Requested {formatDate(record.requested_exit_date)}</span>
                    )}
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-col gap-1">
                      <StatusBadge
                        status={record.stage ?? record.status}
                        label={record.stage_label ?? STATUS_LABELS[record.status]}
                      />
                      {record.type === 'termination' && record.status === 'for_review' && (
                        <span className="text-xs font-medium text-status-warning-text">Pending administrator review</span>
                      )}
                      {record.clearance_outstanding > 0 && (
                        <span className="text-xs text-warning">
                          {record.clearance_outstanding} clearance item{record.clearance_outstanding === 1 ? '' : 's'} to resolve
                        </span>
                      )}
                      {record.clearance_status && record.clearance_status !== 'cleared' && (
                        <span className="text-xs text-warning">
                          Clearance {humanise(record.clearance_status).toLowerCase()}
                        </span>
                      )}
                      {record.notice && (
                        <span className="text-xs text-muted-foreground">
                          {record.notice.acknowledged
                            ? 'Notice: receipt confirmed'
                            : record.notice.viewed
                              ? 'Notice: opened, not confirmed'
                              : 'Notice: not opened yet'}
                        </span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="text-right">
                    <Button variant={record.is_open ? 'outline' : 'ghost'} size="sm" asChild>
                      <Link to={caseLink}>
                        {record.is_open ? 'Review' : 'Open'}
                        <ChevronRight className="h-3.5 w-3.5" />
                      </Link>
                    </Button>
                  </TableCell>
                </TableRow>
                )
              })}
            </TableBody>
          </Table>
        )}
      </Card>
    </>
  )
}
