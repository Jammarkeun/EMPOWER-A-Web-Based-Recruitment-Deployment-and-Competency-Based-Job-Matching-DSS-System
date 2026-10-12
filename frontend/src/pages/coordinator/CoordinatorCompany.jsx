import * as React from 'react'
import { Building2, Users } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states'
import { formatDate } from '@/lib/utils'

/**
 * The assigned client, as CDE's HR and administrator keep it.
 *
 * Read-only on purpose: the client company record is master data the agency
 * maintains, and the coordinator's job is to know it, not to change it.
 */
export default function CoordinatorCompany() {
  const { data, loading, error, refetch } = useApi('/coordinator/company')

  if (loading) return <LoadingState label="Loading your client…" />
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data) return null

  const details = [
    ['Business type', data.business_type],
    ['Office address', data.office_address],
    ['Contact person', data.contact_person],
    ['Contact number', data.contact_number],
    ['Email', data.email],
  ].filter(([, value]) => value)

  return (
    <div className="space-y-5">
      <PageHeader
        title={data.company_name}
        description="Your assigned client company. CDE's HR maintains these details; contact them if anything is out of date."
      />

      <div className="flex flex-wrap items-center gap-2">
        <StatusBadge status={data.status} />
        <span className="font-mono text-xs text-muted-foreground">{data.company_code}</span>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Company details</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
              {details.map(([label, value]) => (
                <div key={label}>
                  <dt className="text-xs text-muted-foreground">{label}</dt>
                  <dd className="text-sm">{value}</dd>
                </div>
              ))}
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">CDE coordinators</CardTitle>
            <CardDescription>Assigned to this client now.</CardDescription>
          </CardHeader>
          <CardContent>
            <ul className="divide-y">
              {data.coordinators.map((c) => (
                <li key={c.email} className="py-2">
                  <p className="text-sm font-medium">{c.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {c.email} · since {formatDate(c.since)}
                  </p>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Departments</CardTitle>
          </CardHeader>
          <CardContent>
            {data.departments.length === 0 ? (
              <EmptyState icon={Building2} title="No departments recorded" />
            ) : (
              <ul className="divide-y">
                {data.departments.map((d) => (
                  <li key={d.id} className="flex items-center justify-between gap-3 py-2.5">
                    <div>
                      <p className="text-sm font-medium">{d.department_name}</p>
                      <p className="font-mono text-xs text-muted-foreground">{d.department_code}</p>
                    </div>
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                      <Users className="h-3.5 w-3.5" />
                      {d.active_employees} active
                      <StatusBadge status={d.status} />
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Positions</CardTitle>
            <CardDescription>Roles CDE places workers in at this client.</CardDescription>
          </CardHeader>
          <CardContent>
            {data.positions.length === 0 ? (
              <p className="text-sm text-muted-foreground">No positions recorded.</p>
            ) : (
              <ul className="flex flex-wrap gap-1.5">
                {data.positions.map((p) => (
                  <li key={p.id}>
                    <Badge tone="outline" title={p.description ?? undefined}>
                      {p.position_title}
                    </Badge>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
