import * as React from 'react'
import { ScrollText } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Select } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { formatDateTime, humanise } from '@/lib/utils'
import { Pagination } from './Applicants'

const ACTION_TONES = {
  login: 'muted',
  logout: 'muted',
  create: 'success',
  update: 'info',
  delete: 'destructive',
  deployment: 'success',
  violation: 'warning',
  resignation: 'warning',
  termination: 'destructive',
  evaluate: 'info',
  view: 'muted',
}

/**
 * The audit trail. Administrator-only by design: the person whose actions are
 * recorded should not also control the record.
 */
export default function AuditLogs() {
  const [filters, setFilters] = React.useState({ action_type: '', module_key: '', date_from: '' })
  const [page, setPage] = React.useState(1)

  const { data, meta, loading, error, refetch } = useApi('/audit-logs', {
    ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)),
    page,
    per_page: 50,
  })

  function update(key, value) {
    setFilters((current) => ({ ...current, [key]: value }))
    setPage(1)
  }

  return (
    <>
      <PageHeader
        title="Audit trail"
        description="Every consequential action, with who performed it and what changed."
      />

      <Card className="mb-4">
        <CardContent className="flex flex-col gap-3 pt-5 sm:flex-row">
          <Select
            value={filters.action_type}
            onChange={(e) => update('action_type', e.target.value)}
            placeholder="All actions"
            aria-label="Filter by action"
            className="sm:w-48"
          >
            {Object.keys(ACTION_TONES).map((action) => (
              <option key={action} value={action}>
                {humanise(action)}
              </option>
            ))}
          </Select>

          <Select
            value={filters.module_key}
            onChange={(e) => update('module_key', e.target.value)}
            placeholder="All modules"
            aria-label="Filter by module"
            className="sm:w-48"
          >
            {['auth', 'applicants', 'requirements', 'clients', 'job_requests', 'matching', 'training', 'deployment', 'employees', 'violations', 'separation', 'archives'].map((module) => (
              <option key={module} value={module}>
                {humanise(module)}
              </option>
            ))}
          </Select>

          <Input
            type="date"
            value={filters.date_from}
            onChange={(e) => update('date_from', e.target.value)}
            aria-label="From date"
            className="sm:w-44"
          />
        </CardContent>
      </Card>

      <Card>
        {loading ? (
          <SkeletonRows rows={10} columns={5} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState icon={ScrollText} title="No audit entries match these filters" />
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>When</TableHead>
                  <TableHead>Who</TableHead>
                  <TableHead>Action</TableHead>
                  <TableHead>Module</TableHead>
                  <TableHead>Record</TableHead>
                  <TableHead>Changes</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.map((log) => (
                  <TableRow key={log.id}>
                    <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                      {formatDateTime(log.created_at)}
                    </TableCell>
                    <TableCell className="text-sm">
                      {log.actor?.first_name ? `${log.actor.first_name} ${log.actor.last_name}` : 'System'}
                      {log.ip_address && <p className="text-xs text-muted-foreground">{log.ip_address}</p>}
                    </TableCell>
                    <TableCell>
                      <Badge tone={ACTION_TONES[log.action_type] ?? 'muted'}>{humanise(log.action_type)}</Badge>
                    </TableCell>
                    <TableCell className="text-sm">{humanise(log.module_key)}</TableCell>
                    <TableCell className="text-sm">
                      {log.record_type ? `${log.record_type} #${log.record_id}` : '—'}
                    </TableCell>
                    <TableCell className="max-w-sm">
                      {log.new_values_json ? (
                        <code className="block truncate text-xs text-muted-foreground">
                          {JSON.stringify(log.new_values_json)}
                        </code>
                      ) : (
                        <span className="text-xs text-muted-foreground">—</span>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            <Pagination meta={meta} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  )
}
