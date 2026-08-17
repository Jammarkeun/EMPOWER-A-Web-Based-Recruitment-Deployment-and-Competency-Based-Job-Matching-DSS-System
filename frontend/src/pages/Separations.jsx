import * as React from 'react'
import { Link } from 'react-router-dom'
import { FileWarning } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Select } from '@/components/ui/select'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { formatDate, humanise } from '@/lib/utils'

export default function Separations() {
  const [type, setType] = React.useState('')
  const { data, loading, error, refetch } = useApi('/separations', { type: type || undefined })

  return (
    <>
      <PageHeader
        title="Separations"
        description="Resignations and terminations across the deployed workforce."
      />

      <Card className="mb-4">
        <CardContent className="pt-5">
          <Select
            value={type}
            onChange={(e) => setType(e.target.value)}
            placeholder="All separations"
            aria-label="Filter by separation type"
            className="sm:w-56"
          >
            <option value="resignation">Resignations</option>
            <option value="termination">Terminations</option>
          </Select>
        </CardContent>
      </Card>

      <Card>
        {loading ? (
          <SkeletonRows rows={5} columns={6} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : !data?.length ? (
          <EmptyState
            icon={FileWarning}
            title="No separations recorded"
            description="Resignations and terminations are filed from an employee's record."
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
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((record) => (
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
                    <Badge tone={record.type === 'termination' ? 'destructive' : 'muted'}>
                      {humanise(record.type)}
                    </Badge>
                  </TableCell>
                  <TableCell className="max-w-xs text-sm">{record.reason}</TableCell>
                  <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                    {formatDate(record.date)}
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                    {formatDate(record.exit_date)}
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-col gap-1">
                      <StatusBadge status={record.status} />
                      {record.clearance_status && record.clearance_status !== 'cleared' && (
                        <span className="text-xs text-warning">
                          Clearance {humanise(record.clearance_status).toLowerCase()}
                        </span>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Card>
    </>
  )
}
