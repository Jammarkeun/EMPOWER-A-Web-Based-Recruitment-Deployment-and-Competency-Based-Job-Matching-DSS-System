import * as React from 'react'
import { useSearchParams } from 'react-router-dom'
import { MonitorSmartphone, Smartphone, Monitor, LogOut, ShieldCheck } from 'lucide-react'
import { useApi, useDebounced } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { api } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { FilterToolbar } from '@/components/FilterToolbar'
import { Pagination } from '@/components/Pagination'
import ConfirmActionDialog from '@/components/ConfirmActionDialog'
import { formatDateTime } from '@/lib/utils'

const EVENTS = {
  signed_in: { label: 'Signed in', tone: 'success' },
  failed: { label: 'Failed attempt', tone: 'destructive' },
  signed_out: { label: 'Signed out', tone: 'muted' },
  ended: { label: 'Session ended', tone: 'warning' },
}

const REASONS = {
  inactive_account: 'Account is deactivated',
  idle_timeout: 'Idle too long',
  session_lifetime: 'Session lifetime reached',
  signed_out_by_administrator: 'Signed out by an administrator',
}

const RESULT_OPTIONS = [
  { value: '', label: 'All events' },
  { value: 'success', label: 'Signed in' },
  { value: 'failed', label: 'Failed attempts' },
  { value: 'logout', label: 'Signed out' },
  { value: 'ended', label: 'Sessions ended' },
]

/**
 * Every device signed in to EMPOWER, for the administrator (CDE, 26 October
 * 2026: "kita niya lang yung lahat ng device na pumapasok sa system").
 *
 * Who is signed in now, from what, and since when; and every attempt to sign
 * in, failed ones included. A device can be signed out from here. No token or
 * password is ever shown - the server does not send one.
 */
export default function SignInActivity() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = searchParams.get('tab') ?? 'now'

  return (
    <div className="space-y-4">
      <PageHeader
        title="Sign-in Activity"
        description="Every device signed in to EMPOWER, and every attempt to sign in. Use it to spot an account being used from somewhere it should not be."
      />

      <Tabs value={tab} onValueChange={(value) => setSearchParams(value === 'now' ? {} : { tab: value })}>
        <TabsList>
          <TabsTrigger value="now">Signed in now</TabsTrigger>
          <TabsTrigger value="history">Sign-in history</TabsTrigger>
        </TabsList>
        <TabsContent value="now">
          <SignedInNow />
        </TabsContent>
        <TabsContent value="history">
          <History />
        </TabsContent>
      </Tabs>
    </div>
  )
}

function DeviceCell({ device, isMobile, userAgent }) {
  const Icon = isMobile ? Smartphone : Monitor
  return (
    <span className="inline-flex items-center gap-1.5 text-sm" title={userAgent ?? undefined}>
      <Icon className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
      {device}
    </span>
  )
}

function SignedInNow() {
  const { can } = useAuth()
  const toast = useToast()
  const { data, loading, error, refetch } = useApi('/security/sessions')
  const [ending, setEnding] = React.useState(null)

  async function signOut() {
    try {
      const response = await api.delete(`/security/sessions/${ending.id}`)
      toast.success('Device signed out', response.data?.message)
      setEnding(null)
      refetch()
    } catch (err) {
      setEnding(null)
      toast.error('Could not sign the device out', err.message)
    }
  }

  if (loading) {
    return (
      <div className="rounded-panel bg-card">
        <SkeletonRows rows={4} columns={6} />
      </div>
    )
  }
  if (error) return <ErrorState message={error.message} onRetry={refetch} />
  if (!data?.length) return <EmptyState icon={MonitorSmartphone} title="Nobody is signed in" />

  return (
    <>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Account</TableHead>
            <TableHead>Device</TableHead>
            <TableHead>Address</TableHead>
            <TableHead>Signed in</TableHead>
            <TableHead>Last active</TableHead>
            <TableHead className="w-28" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {data.map((session) => (
            <TableRow key={session.id}>
              <TableCell>
                <p className="text-sm font-medium">
                  {session.user.full_name}
                  {session.is_current && (
                    <Badge tone="info" className="ml-2">
                      This device
                    </Badge>
                  )}
                </p>
                <p className="text-xs text-muted-foreground">
                  {session.user.email} · {(session.user.roles ?? []).join(', ') || session.user.user_type}
                </p>
              </TableCell>
              <TableCell>
                <DeviceCell device={session.device} isMobile={session.is_mobile} userAgent={session.user_agent} />
              </TableCell>
              <TableCell className="font-mono text-xs">{session.ip_address ?? '—'}</TableCell>
              <TableCell className="whitespace-nowrap text-xs text-muted-foreground">{formatDateTime(session.signed_in_at)}</TableCell>
              <TableCell className="whitespace-nowrap text-xs text-muted-foreground">{formatDateTime(session.last_active_at)}</TableCell>
              <TableCell className="text-right">
                {can('sessions.revoke') && !session.is_current && (
                  <Button variant="outline" size="sm" onClick={() => setEnding(session)}>
                    <LogOut className="h-3.5 w-3.5" />
                    Sign out
                  </Button>
                )}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      <p className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
        <ShieldCheck className="h-3.5 w-3.5" />
        Each account has one session at a time; signing in elsewhere ends the previous one.
      </p>

      <ConfirmActionDialog
        open={Boolean(ending)}
        onOpenChange={(open) => !open && setEnding(null)}
        tone="warning"
        title="Sign this device out?"
        description="The person is signed out of EMPOWER on that device straight away and can sign in again with their password. Nothing else about the account changes."
        details={ending ? [['Account', ending.user.full_name], ['Device', ending.device], ['Address', ending.ip_address]] : []}
        confirmLabel="Sign out device"
        onConfirm={signOut}
      />
    </>
  )
}

function History() {
  const [result, setResult] = React.useState('')
  const [search, setSearch] = React.useState('')
  const [page, setPage] = React.useState(1)
  const debounced = useDebounced(search)

  React.useEffect(() => setPage(1), [result, debounced])

  const { data, meta, loading, error, refetch } = useApi('/security/sign-ins', {
    result: result || undefined,
    search: debounced || undefined,
    page,
    per_page: 50,
  })

  return (
    <div className="space-y-3">
      <FilterToolbar
        search={search}
        onSearchChange={setSearch}
        searchPlaceholder="Search by name, email or address…"
        filters={[{ id: 'result', label: 'All events', value: result, onChange: setResult, options: RESULT_OPTIONS }]}
        resultCount={meta?.total}
        resultLabel="events"
      />

      {loading ? (
        <div className="rounded-panel bg-card">
          <SkeletonRows rows={6} columns={5} />
        </div>
      ) : error ? (
        <ErrorState message={error.message} onRetry={refetch} />
      ) : !data?.length ? (
        <EmptyState icon={MonitorSmartphone} title="No sign-in activity" />
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>When</TableHead>
                <TableHead>Event</TableHead>
                <TableHead>Account</TableHead>
                <TableHead>Device</TableHead>
                <TableHead>Address</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.map((event) => {
                const meta$ = EVENTS[event.event] ?? EVENTS.signed_out
                return (
                  <TableRow key={event.id}>
                    <TableCell className="whitespace-nowrap text-xs text-muted-foreground">{formatDateTime(event.at)}</TableCell>
                    <TableCell>
                      <Badge tone={meta$.tone}>{meta$.label}</Badge>
                      {(event.reason || event.by) && (
                        <p className="mt-1 text-xs text-muted-foreground">
                          {REASONS[event.reason] ?? event.reason}
                          {event.by && ` (${event.by})`}
                        </p>
                      )}
                    </TableCell>
                    <TableCell>
                      {event.account ? (
                        <>
                          <p className="text-sm font-medium">{event.account.full_name}</p>
                          <p className="text-xs text-muted-foreground">{event.account.email}</p>
                        </>
                      ) : (
                        <p className="text-sm text-muted-foreground">
                          No account: <span className="font-mono">{event.attempted_email ?? '—'}</span>
                        </p>
                      )}
                    </TableCell>
                    <TableCell>
                      <DeviceCell device={event.device} isMobile={/iPhone|iPad|Android|Mobile/i.test(event.user_agent ?? event.device)} userAgent={event.user_agent} />
                    </TableCell>
                    <TableCell className="font-mono text-xs">{event.ip_address ?? '—'}</TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
          <Pagination meta={meta} onPage={setPage} />
        </>
      )}
    </div>
  )
}
