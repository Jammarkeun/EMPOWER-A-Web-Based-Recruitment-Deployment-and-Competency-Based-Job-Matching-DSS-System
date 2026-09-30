import * as React from 'react'
import { FileText, Download, Loader2, FileSpreadsheet, Printer, BarChart3 } from 'lucide-react'
import { api, get, getCached, post } from '@/lib/api'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import { PageHeader } from '@/components/PageHeader'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Field } from '@/components/ui/form'
import { EmptyState, LoadingState } from '@/components/ui/states'
import { cn } from '@/lib/utils'

/** Options for the filters a report can declare. */
const FILTER_OPTIONS = {
  current_status: [
    'applied', 'initial_screening', 'incomplete_requirements', 'primary_requirements_complete',
    'pending_final_requirements', 'ready_for_deployment', 'training_scheduled', 'training_completed',
    'client_evaluation', 'approved', 'deployed', 'active', 'resigned', 'terminated', 'archived',
  ],
  employment_status: ['active', 'resigned', 'terminated', 'archived'],
  folder_category: ['folder_1', 'folder_2', 'folder_3'],
  request_status: ['open', 'in_progress', 'partially_fulfilled', 'fulfilled', 'closed', 'cancelled'],
  deployment_status: ['active', 'completed', 'transferred', 'ended'],
  violation_type: ['awol', 'absences', 'suspension', 'late', 'misconduct', 'policy_violation'],
  source_channel: ['walk_in', 'messenger', 'email'],
  status: ['active', 'inactive', 'open', 'under_review', 'resolved', 'escalated', 'scheduled', 'completed', 'cancelled', 'filed', 'finalized', 'for_review'],
}

const FILTER_LABELS = {
  date_from: 'From date',
  date_to: 'To date',
  year: 'Year',
  client_company_id: 'Client company',
  client_department_id: 'Department',
  position_title: 'Position',
  current_status: 'Application status',
  employment_status: 'Employment status',
  folder_category: 'Document folder',
  request_status: 'Request status',
  deployment_status: 'Deployment status',
  violation_type: 'Violation type',
  source_channel: 'How they applied',
  status: 'Status',
}

export default function Reports() {
  const { can } = useAuth()
  const toast = useToast()

  const [catalogue, setCatalogue] = React.useState([])
  const [selected, setSelected] = React.useState(null)
  const [filters, setFilters] = React.useState({})
  const [clients, setClients] = React.useState([])
  const [departments, setDepartments] = React.useState([])
  const [positions, setPositions] = React.useState([])
  const [preview, setPreview] = React.useState(null)
  const [loading, setLoading] = React.useState(false)
  const [exporting, setExporting] = React.useState(null)

  React.useEffect(() => {
    getCached('/reports')
      .then((response) => {
        setCatalogue(response.data.types)

        /*
         * Only picks a default when nothing is chosen yet.
         *
         * This effect runs twice under React's development double-invoke, so
         * two catalogue requests are in flight at once. Assigning the first
         * report unconditionally meant the slower response landed after the
         * user had already clicked one and silently put the selection back to
         * "Applicant Report" — so choosing any other report appeared to do
         * nothing at all. The slower the API, the wider that window: against
         * Supabase it was reliable enough to look like a broken button.
         */
        setSelected((current) => current ?? response.data.types[0] ?? null)
      })
      .catch((error) => toast.error('Could not load reports', error.message))

    get('/clients', { status: 'active', per_page: 100 })
      .then((response) => setClients(response.data))
      .catch(() => setClients([]))

    // Distinct titles, because two clients may both hire a Production Helper
    // and the filter is on the title the placement recorded.
    getCached('/positions')
      .then((response) =>
        setPositions([...new Set((response.data.positions ?? []).map((p) => p.position_title))].sort())
      )
      .catch(() => setPositions([]))
  }, [])

  // Departments belong to a company, so the list follows whichever is chosen.
  React.useEffect(() => {
    const companyId = filters.client_company_id

    if (!companyId) {
      setDepartments([])
      return
    }

    get(`/clients/${companyId}/departments`)
      .then((response) => setDepartments(response.data ?? []))
      .catch(() => setDepartments([]))
  }, [filters.client_company_id])

  // Filters from the previous report rarely apply to the next one, so they are
  // cleared when the selection changes rather than silently carried over.
  React.useEffect(() => {
    setFilters({})
    setPreview(null)
  }, [selected?.key])

  async function runPreview() {
    if (!selected) return

    setLoading(true)
    try {
      const response = await post('/reports/preview', {
        report_type: selected.key,
        filters: cleanFilters(filters),
      })
      setPreview(response.data)

      /*
       * The count is the point of this message, not the fact that it ran — the
       * table appearing already says that. Seeing "0 records" as a toast is
       * what tells the user their filters excluded everything, before they
       * export an empty spreadsheet and take it to a client meeting.
       */
      const rows = response.data.total_rows
      if (rows === 0) {
        toast.warning('No records match these filters', 'Try widening the date range or clearing a filter.')
      } else {
        toast.success('Report ready', `${rows} record${rows === 1 ? '' : 's'} found.`)
      }
    } catch (error) {
      toast.error('Could not generate report', error.message)
    } finally {
      setLoading(false)
    }
  }

  /**
   * Downloads the export.
   *
   * The endpoint returns a file rather than JSON, so the response is requested
   * as a blob and handed to the browser through an object URL. Going through
   * the API client keeps the bearer token attached, which a plain link could
   * not do.
   */
  async function runExport(format) {
    if (!selected) return

    setExporting(format)
    try {
      const response = await api.post(
        '/reports/export',
        { report_type: selected.key, format, filters: cleanFilters(filters) },
        { responseType: 'blob' }
      )

      if (response.status === 202) {
        const queued = JSON.parse(await response.data.text())
        const exportId = queued.data.export_id

        for (let attempt = 0; attempt < 120; attempt += 1) {
          await new Promise((resolve) => setTimeout(resolve, 1000))
          const status = await get(`/reports/exports/${exportId}`)

          if (status.data.status === 'failed') {
            throw new Error(status.data.message ?? 'The queued report failed.')
          }

          if (status.data.status === 'completed') {
            const file = await api.get(`/reports/exports/${exportId}/download`, { responseType: 'blob' })
            const url = URL.createObjectURL(file.data)
            const link = document.createElement('a')
            link.href = url
            link.download = `${selected.key}.${format}`
            document.body.appendChild(link)
            link.click()
            link.remove()
            URL.revokeObjectURL(url)
            toast.success('Report downloaded', link.download)
            return
          }
        }

        throw new Error('The report is taking longer than expected. Check Reports again shortly.')
      }

      const disposition = response.headers['content-disposition'] ?? ''
      const match = disposition.match(/filename="?([^"]+)"?/)
      const filename = match?.[1] ?? `${selected.key}.${format}`

      const url = URL.createObjectURL(new Blob([response.data]))
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)

      toast.success('Report downloaded', filename)
    } catch (error) {
      toast.error('Export failed', error.message ?? 'The report could not be generated.')
    } finally {
      setExporting(null)
    }
  }

  if (!catalogue.length) return <LoadingState label="Loading reports…" />

  return (
    <>
      <PageHeader
        title="Reports"
        description="Generate, print, and export records for management and client review."
      />

      <div className="grid gap-4 lg:grid-cols-[260px_1fr]">
        <Card className="h-fit no-print">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm">Report type</CardTitle>
          </CardHeader>
          <CardContent className="p-2">
            <ul className="space-y-0.5">
              {catalogue.map((report) => (
                <li key={report.key}>
                  <button
                    onClick={() => setSelected(report)}
                    className={cn(
                      'w-full rounded-md px-3 py-2 text-left text-sm transition-colors',
                      selected?.key === report.key
                        ? 'bg-primary/10 font-medium text-primary'
                        : 'hover:bg-accent'
                    )}
                  >
                    {report.label}
                  </button>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        <div className="space-y-4">
          {selected?.filters?.length > 0 && (
            <Card className="no-print">
              <CardHeader className="pb-3">
                <CardTitle className="text-sm">Filters</CardTitle>
                <CardDescription>Leave a filter blank to include everything.</CardDescription>
              </CardHeader>
              <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {selected.filters.map((key) => (
                  <FilterInput
                    key={key}
                    name={key}
                    value={filters[key] ?? ''}
                    clients={clients}
                    departments={departments}
                    positions={positions}
                    companySelected={!!filters.client_company_id}
                    onChange={(value) =>
                      setFilters((current) => {
                        const next = { ...current, [key]: value }

                        // A department chosen under one client is meaningless
                        // under another, and leaving it set would silently
                        // return nothing at all.
                        if (key === 'client_company_id') next.client_department_id = ''

                        return next
                      })
                    }
                  />
                ))}
              </CardContent>
            </Card>
          )}

          <div className="flex flex-wrap gap-2 no-print">
            <Button onClick={runPreview} disabled={loading}>
              {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : <BarChart3 className="h-4 w-4" />}
              {loading ? 'Generating…' : 'Generate report'}
            </Button>

            {can('reports.export') && (
              <>
                <Button variant="outline" onClick={() => runExport('pdf')} disabled={!!exporting}>
                  {exporting === 'pdf' ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileText className="h-4 w-4" />}
                  PDF
                </Button>
                <Button variant="outline" onClick={() => runExport('xlsx')} disabled={!!exporting}>
                  {exporting === 'xlsx' ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileSpreadsheet className="h-4 w-4" />}
                  Excel
                </Button>
                <Button variant="outline" onClick={() => runExport('csv')} disabled={!!exporting}>
                  <Download className="h-4 w-4" />
                  CSV
                </Button>
              </>
            )}

            {preview && (
              <Button variant="ghost" onClick={() => window.print()}>
                <Printer className="h-4 w-4" />
                Print
              </Button>
            )}
          </div>

          {preview ? <ReportPreview preview={preview} /> : (
            <Card>
              <EmptyState
                icon={BarChart3}
                title="No report generated yet"
                description="Choose a report type, set any filters, then generate it to see the results."
              />
            </Card>
          )}
        </div>
      </div>
    </>
  )
}

function ReportPreview({ preview }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{preview.title}</CardTitle>
        <CardDescription>
          {preview.subtitle} · {preview.period} · {preview.total_rows} record{preview.total_rows === 1 ? '' : 's'}
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        {preview.summary?.length > 0 && (
          <div className="grid gap-3 rounded-lg border bg-muted/40 p-4 sm:grid-cols-2 lg:grid-cols-4">
            {preview.summary.map((item) => (
              <div key={item.label}>
                <p className="text-xs text-muted-foreground">{item.label}</p>
                <p className="text-lg font-semibold tabular-nums">{item.value}</p>
              </div>
            ))}
          </div>
        )}

        {preview.rows.length === 0 ? (
          <EmptyState
            title="No records match these filters"
            description="Try widening the date range or clearing a filter."
          />
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  {preview.columns.map((column) => (
                    <TableHead key={column}>{column}</TableHead>
                  ))}
                </TableRow>
              </TableHeader>
              <TableBody>
                {preview.rows.map((row, index) => (
                  <TableRow key={index}>
                    {row.map((cell, cellIndex) => (
                      <TableCell key={cellIndex} className="whitespace-nowrap text-sm">
                        {cell}
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            {preview.truncated && (
              <p className="text-center text-xs text-muted-foreground">
                Showing the first 100 of {preview.total_rows} records. Export to see them all.
              </p>
            )}
          </>
        )}
      </CardContent>
    </Card>
  )
}

function FilterInput({ name, value, onChange, clients, departments, positions, companySelected }) {
  const label = FILTER_LABELS[name] ?? name

  if (name === 'date_from' || name === 'date_to') {
    return (
      <Field label={label} htmlFor={name}>
        <Input id={name} type="date" value={value} onChange={(e) => onChange(e.target.value)} />
      </Field>
    )
  }

  if (name === 'year') {
    const thisYear = new Date().getFullYear()
    return (
      <Field label={label} htmlFor={name}>
        <Select id={name} value={value} onChange={(e) => onChange(e.target.value)} placeholder={String(thisYear)}>
          {[0, 1, 2, 3, 4].map((offset) => (
            <option key={offset} value={thisYear - offset}>
              {thisYear - offset}
            </option>
          ))}
        </Select>
      </Field>
    )
  }

  if (name === 'client_company_id') {
    return (
      <Field label={label} htmlFor={name}>
        <Select id={name} value={value} onChange={(e) => onChange(e.target.value)} placeholder="All clients">
          {clients.map((client) => (
            <option key={client.id} value={client.id}>
              {client.company_name}
            </option>
          ))}
        </Select>
      </Field>
    )
  }

  /*
   * A department belongs to one company, so choosing one without saying whose
   * is not a question the data can answer. Rather than listing every
   * department across every client and letting the two filters contradict each
   * other, this waits for the company and says so.
   */
  if (name === 'client_department_id') {
    return (
      <Field
        label={label}
        htmlFor={name}
        hint={!companySelected ? 'Choose a client company first' : undefined}
      >
        <Select
          id={name}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={companySelected ? 'All departments' : 'Select a client first'}
          disabled={!companySelected}
        >
          {departments.map((department) => (
            <option key={department.id} value={department.id}>
              {department.department_name}
            </option>
          ))}
        </Select>
      </Field>
    )
  }

  // Filtered by title, because the title is what the placement records actually
  // store. The options are the positions the system knows about, so the value
  // is chosen rather than typed and cannot drift from the column.
  if (name === 'position_title') {
    return (
      <Field label={label} htmlFor={name}>
        <Select id={name} value={value} onChange={(e) => onChange(e.target.value)} placeholder="All positions">
          {positions.map((title) => (
            <option key={title} value={title}>
              {title}
            </option>
          ))}
        </Select>
      </Field>
    )
  }

  const options = FILTER_OPTIONS[name] ?? []

  return (
    <Field label={label} htmlFor={name}>
      <Select id={name} value={value} onChange={(e) => onChange(e.target.value)} placeholder="All">
        {options.map((option) => (
          <option key={option} value={option}>
            {option.split('_').map((w) => w[0].toUpperCase() + w.slice(1)).join(' ')}
          </option>
        ))}
      </Select>
    </Field>
  )
}

/** Strips blank filters so the server sees "no filter" rather than an empty string. */
function cleanFilters(filters) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value != null))
}
