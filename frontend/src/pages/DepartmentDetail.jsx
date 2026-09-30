import * as React from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, IdCard, Users, ClipboardList, UserMinus } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader, StatTile } from '@/components/PageHeader'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { StatusBadge } from '@/components/ui/badge'
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states'
import { formatDate } from '@/lib/utils'

/**
 * Who is working in one of a client's departments.
 *
 * The client page listed departments as names and nothing else, so the question
 * a client asks first — "who have you given us in Packaging?" — could only be
 * answered by opening the employee list and filtering it by hand.
 *
 * A route of its own rather than a panel inside the company page, for three
 * reasons: the URL can be sent to somebody, the browser's back button does the
 * obvious thing, and the department is named in the address rather than being
 * state the page has to remember. The API is addressed the same way —
 * `/clients/{client}/departments/{department}/employees` — so a department
 * belonging to another client does not resolve at all.
 */
export default function DepartmentDetail() {
  const { id, departmentId } = useParams()

  const { data, loading, error, refetch } = useApi(
    `/clients/${id}/departments/${departmentId}/employees`
  )

  const backToDepartments = `/clients/${id}?tab=departments`

  if (loading) {
    return (
      <>
        <PageHeader
          breadcrumbs={[{ label: 'Client companies', to: '/clients' }, { label: 'Department' }]}
          title="Department"
        />
        <LoadingState label="Loading employees…" />
      </>
    )
  }

  if (error) {
    return (
      <>
        <PageHeader
          breadcrumbs={[{ label: 'Client companies', to: '/clients' }, { label: 'Department' }]}
          title="Department"
          actions={<BackButton to={backToDepartments} />}
        />
        <Card>
          {/*
            A 404 here means the department is not this company's, which is a
            different problem from the server being unreachable and deserves a
            different sentence.
          */}
          <ErrorState
            message={
              error.status === 404
                ? 'This department does not belong to this client company.'
                : 'Unable to load employees. Please try again.'
            }
            onRetry={error.status === 404 ? undefined : refetch}
          />
        </Card>
      </>
    )
  }

  if (!data) return null

  const { department, company, employees } = data
  const current = department.employees_count ?? 0
  const former = department.former_employees_count ?? 0

  return (
    <>
      <PageHeader
        breadcrumbs={[
          { label: 'Client companies', to: '/clients' },
          { label: company.company_name, to: `/clients/${id}` },
          { label: department.department_name },
        ]}
        title={department.department_name}
        description={`${company.company_name} · ${department.department_code}`}
        actions={<BackButton to={backToDepartments} />}
      />

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <StatTile
          label="Employees"
          value={current}
          hint="Currently placed here"
          icon={Users}
        />
        {/* Only shown when there are any. A permanent "0 former employees" is
            noise on a department that has never lost anybody. */}
        {former > 0 && (
          <StatTile
            label="Former employees"
            value={former}
            hint="Resigned or terminated, kept on record"
            icon={UserMinus}
          />
        )}
        <StatTile
          label="Manpower requests"
          value={department.job_requests_count ?? 0}
          hint="Raised against this department"
          icon={ClipboardList}
          to="/job-requests"
        />
      </div>

      <Card>
        {employees.length === 0 ? (
          <EmptyState
            icon={IdCard}
            title="No employees are currently assigned to this department"
            description="Workers appear here once an applicant is deployed into it."
            action={
              <Button variant="outline" size="sm" asChild>
                <Link to={backToDepartments}>Back to departments</Link>
              </Button>
            }
          />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Employee</TableHead>
                <TableHead>Number</TableHead>
                <TableHead>Position</TableHead>
                <TableHead>Supervisor</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Hired</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {employees.map((employee) => (
                <TableRow
                  key={employee.id}
                  // Former staff are listed but recede, so a glance down the
                  // column finds the people who are actually there.
                  className={employee.employment_status !== 'active' ? 'opacity-60' : undefined}
                >
                  <TableCell>
                    <Link
                      to={`/employees/${employee.id}`}
                      className="font-medium text-primary hover:underline"
                    >
                      {employee.full_name}
                    </Link>
                  </TableCell>
                  <TableCell className="whitespace-nowrap font-mono text-xs text-muted-foreground">
                    {employee.employee_number}
                  </TableCell>
                  <TableCell className="text-sm">{employee.current_position_title || '—'}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {employee.current_supervisor_name || '—'}
                  </TableCell>
                  <TableCell>
                    <StatusBadge status={employee.employment_status} />
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                    {formatDate(employee.hire_date)}
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

/**
 * An explicit way back.
 *
 * The breadcrumb above already leads to the company, but it lands on the
 * company's default tab rather than the department list the reader came from.
 * This returns them to exactly where they were.
 */
function BackButton({ to }) {
  return (
    <Button variant="outline" size="sm" asChild>
      <Link to={to}>
        <ArrowLeft className="h-3.5 w-3.5" />
        Back to departments
      </Link>
    </Button>
  )
}
