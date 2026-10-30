import * as React from 'react'
import { Link } from 'react-router-dom'
import { Archive, Hourglass, Info, Loader2, ShieldCheck } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { useAuth } from '@/contexts/AuthContext'
import { post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Badge, StatusBadge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Field } from '@/components/ui/form'
import { EmptyState, ErrorState, SkeletonRows } from '@/components/ui/states'
import { Pagination } from '@/components/Pagination'
import ArchiveApplicantDialog from '@/components/applicant/ArchiveApplicantDialog'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn, formatDate, formatDateTime, todayISO } from '@/lib/utils'

const CATEGORIES = [
  { value: '', label: 'Everything due' },
  { value: 'unhired_applicant', label: 'Unhired applicants' },
  { value: 'closed_record_documents', label: 'Legal documents' },
]

/** A date some months or years from today, as YYYY-MM-DD on this computer's calendar. */
export function dateFromToday({ months = 0, years = 0 }) {
  const date = new Date()
  date.setMonth(date.getMonth() + months)
  date.setFullYear(date.getFullYear() + years)
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/**
 * Records past CDE's retention estimates, for a person to review (audit FN-12).
 *
 * The estimates are the agency's own, confirmed in the follow-up interview and
 * set in Settings: unhired applicants about three months, legal documents five
 * to ten years. They prompt a review; they are not deadlines. So this screen
 * only ever offers two things - keep the record on file until a date, with a
 * reason, or archive an unhired applicant through the ordinary status change.
 * Nothing is deleted, here or anywhere else, and nothing is archived by the
 * system on its own.
 */
export default function Retention() {
  const { can } = useAuth()
  const [category, setCategory] = React.useState('')
  const [page, setPage] = React.useState(1)
  const [keeping, setKeeping] = React.useState(null)
  const [archiving, setArchiving] = React.useState(null)

  const { data, meta, loading, error, refetch } = useApi('/retention-reviews', {
    category: category || undefined,
    page,
    per_page: 50,
  })

  const canDecide = can('archives.create')
  const canArchive = canDecide && can('applicants.change_status')
  const policy = data?.policy
  const rows = data?.rows ?? []

  return (
    <>
      <PageHeader
        title="Retention review"
        description="Records past the agency’s retention estimates, for a person to review. Nothing here is deleted."
      />

      {policy && (
        <div className="mb-4 flex items-start gap-3 rounded-panel bg-card p-4 text-sm">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-brand" aria-hidden="true" />
          <div className="space-y-1">
            <p>
              Unhired applicants are listed after <strong>{policy.unhired_applicant_months} months</strong> with no
              recorded activity. Legal documents of a resigned, terminated or archived record are listed{' '}
              <strong>{policy.legal_document_years} years</strong> after the record closed.
            </p>
            <p className="text-xs text-muted-foreground">
              Both are CDE’s own estimates and can be changed in Settings → Retention. Being listed means a review is due,
              not that anything will happen to the record.
            </p>
            {data.undated_closed_records > 0 && (
              <p className="text-xs text-status-warning-text">
                {data.undated_closed_records} closed record{data.undated_closed_records === 1 ? ' has' : 's have'} no
                recorded closing date and {data.undated_closed_records === 1 ? 'is' : 'are'} not listed. Check{' '}
                {data.undated_closed_records === 1 ? 'it' : 'them'} in Archives.
              </p>
            )}
          </div>
        </div>
      )}

      <div className="mb-3 flex flex-wrap gap-2" role="tablist" aria-label="What is due">
        {CATEGORIES.map((option) => {
          const count = option.value ? data?.counts?.[option.value] : undefined
          const active = category === option.value
          return (
            <button
              key={option.value || 'all'}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => {
                setCategory(option.value)
                setPage(1)
              }}
              className={cn(
                'rounded-full px-3 py-1.5 text-xs font-medium transition-colors',
                active ? 'bg-brand text-white' : 'bg-inset text-muted-foreground hover:text-foreground'
              )}
            >
              {option.label}
              {count !== undefined && ` (${count})`}
            </button>
          )
        })}
      </div>

      <Card>
        {loading ? (
          <SkeletonRows rows={6} columns={5} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={refetch} />
        ) : rows.length === 0 ? (
          <EmptyState
            icon={ShieldCheck}
            title="Nothing is due for review"
            description="Records appear here once they pass the retention estimates in Settings."
          />
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Record</TableHead>
                  <TableHead>Why it is listed</TableHead>
                  <TableHead>Review due</TableHead>
                  <TableHead className="text-right">
                    <span className="sr-only">Actions</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.key}>
                    <TableCell className="align-top">
                      <Link to={`/applicants/${row.applicant.id}`} className="font-medium hover:text-brand">
                        {row.applicant.full_name}
                      </Link>
                      <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <span className="font-mono text-xs text-muted-foreground">
                          {row.applicant.applicant_code || 'No code'}
                        </span>
                        <StatusBadge status={row.applicant.current_status} label={row.applicant.status_label} />
                      </div>
                      <Badge tone="muted" className="mt-1.5">
                        {row.category_label}
                      </Badge>
                    </TableCell>
                    <TableCell className="max-w-md align-top text-sm">
                      <p>{row.reason}</p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        {row.relevant_date_label}: {formatDate(row.relevant_date)}
                      </p>
                      {row.previous_decision && (
                        <p className="mt-1 text-xs text-muted-foreground">
                          Kept on file until {formatDate(row.previous_decision.retain_until)} by{' '}
                          {row.previous_decision.reviewed_by ?? 'an officer'} (
                          {formatDateTime(row.previous_decision.reviewed_at)}): “{row.previous_decision.note}”
                        </p>
                      )}
                    </TableCell>
                    <TableCell className="whitespace-nowrap align-top text-sm">
                      {formatDate(row.due_on)}
                      {row.days_overdue > 0 && (
                        <p className="text-xs text-muted-foreground">
                          {row.days_overdue} day{row.days_overdue === 1 ? '' : 's'} ago
                        </p>
                      )}
                    </TableCell>
                    <TableCell className="align-top">
                      <div className="flex flex-wrap justify-end gap-2">
                        {canDecide && row.actions.includes('retain') && (
                          <Button size="sm" variant="outline" onClick={() => setKeeping(row)}>
                            <Hourglass className="h-3.5 w-3.5" />
                            Keep on file…
                          </Button>
                        )}
                        {canArchive && row.actions.includes('archive') && (
                          <Button size="sm" variant="outline" onClick={() => setArchiving(row)}>
                            <Archive className="h-3.5 w-3.5" />
                            Archive…
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <div className="px-4 pb-3">
              <Pagination meta={meta} onPage={setPage} />
            </div>
          </>
        )}
      </Card>

      <KeepDialog row={keeping} policy={policy} onOpenChange={(open) => !open && setKeeping(null)} onKept={refetch} />

      {archiving && (
        <ArchiveApplicantDialog
          open={Boolean(archiving)}
          onOpenChange={(open) => !open && setArchiving(null)}
          applicant={archiving.applicant}
          reason={`Retention review: no recorded activity since ${formatDate(archiving.relevant_date)}.`}
          onArchived={refetch}
        />
      )}
    </>
  )
}

/** Keeping a listed record on file until a date, with the reason. */
function KeepDialog({ row, policy, onOpenChange, onKept }) {
  const toast = useToast()
  const [note, setNote] = React.useState('')
  const [until, setUntil] = React.useState('')
  const [errors, setErrors] = React.useState({})
  const [submitting, setSubmitting] = React.useState(false)

  React.useEffect(() => {
    if (row) {
      setNote('')
      setErrors({})
      // A suggestion only: one more retention period for an applicant, a year for documents.
      setUntil(
        row.category === 'unhired_applicant'
          ? dateFromToday({ months: policy?.unhired_applicant_months ?? 3 })
          : dateFromToday({ years: 1 })
      )
    }
  }, [row, policy])

  async function submit(event) {
    event.preventDefault()
    setSubmitting(true)
    setErrors({})
    try {
      const response = await post('/retention-reviews', {
        category: row.category,
        applicant_id: row.applicant.id,
        note: note.trim(),
        retain_until: until,
      })
      toast.success(response.message ?? 'Kept on file')
      onOpenChange(false)
      onKept()
    } catch (error) {
      if (error.isValidation) setErrors(error.errors)
      else toast.error('Not recorded', error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={Boolean(row)} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Keep {row?.applicant.full_name} on file</DialogTitle>
          <DialogDescription>
            The record leaves this list until the date you choose and is reviewed again after it. Nothing about the record
            itself changes.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-4" noValidate>
          <Field label="Keep until" htmlFor="retain_until" required error={errors.retain_until?.[0]}>
            <Input type="date" value={until} min={todayISO()} onChange={(e) => setUntil(e.target.value)} />
          </Field>
          <Field label="Reason" htmlFor="note" required error={errors.note?.[0]} hint="Recorded with your name in the audit trail.">
            <Textarea rows={3} value={note} onChange={(e) => setNote(e.target.value)} />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              Keep on file
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
