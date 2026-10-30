import * as React from 'react'
import { Loader2, AlertTriangle, Info } from 'lucide-react'
import { get, post } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { NumberInput } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Field } from '@/components/ui/form'
import { ErrorState, LoadingState } from '@/components/ui/states'
import CriteriaValueEditor from '@/components/CriteriaValueEditor'
import RangeSummary from '@/components/RangeSummary'
import {
  directionBadge,
  importanceProblem,
  isMustHave,
  rangeLabels,
  rangeProblem,
  unitFor,
} from '@/lib/requirementRange'

/** The most importance one requirement can carry, as the server allows. */
const IMPORTANCE_LIMIT = 1000

/**
 * Sets the matching requirements for a request and how much each is worth.
 *
 * The importance points are the whole basis of the ranking, so the dialog
 * shows each requirement's share of the total as it is edited. A number in
 * isolation means nothing; "20 points out of 100" is what an HR officer can
 * actually reason about, and what they will be asked to justify.
 */
export default function CriteriaDialog({ open, onOpenChange, jobRequestId, onSaved }) {
  const [catalog, setCatalog] = React.useState([])
  const [rows, setRows] = React.useState({})
  const [prefill, setPrefill] = React.useState(null)
  const [loading, setLoading] = React.useState(true)
  const [saving, setSaving] = React.useState(false)
  const [loadError, setLoadError] = React.useState(null)
  const [attempt, setAttempt] = React.useState(0)
  const toast = useToast()

  React.useEffect(() => {
    if (!open) return

    setLoading(true)
    setLoadError(null)
    get(`/job-requests/${jobRequestId}/criteria`)
      .then((response) => {
        setCatalog(response.data.catalog)

        const toRow = (criterion) => ({
          enabled: true,
          mandatory: Boolean(criterion.mandatory_flag),
          weight: String(criterion.weight_score ?? 0),
          min: criterion.min_value ?? '',
          max: criterion.max_value ?? '',
          expected: criterion.expected_value ?? '',
        })

        /*
         * A request with nothing set yet starts from what is already known:
         * the requirements typed on the request itself (its age bracket,
         * minimum height, education, and so on) and the client's standing
         * criteria. Those used to sit unused while the ranking ignored them.
         * They are only proposed - nothing is saved until somebody saves.
         */
        const source = response.data.configured.length ? response.data.configured : response.data.suggested ?? []

        setRows(Object.fromEntries(source.map((c) => [c.criteria_code, toRow(c)])))
        setPrefill(
          response.data.configured.length
            ? null
            : {
                fromRequest: (response.data.suggested_from_request ?? []).map((c) => c.criteria_name),
                fromCompany: (response.data.suggested_from_company ?? []).map((c) => c.criteria_name),
                company: response.data.company_name,
                unmapped: response.data.unmapped_requirements ?? [],
              }
        )
      })
      // Shown in place of the form: an empty form after a failed load would
      // look like a request with nothing set, and saving it would clear it.
      .catch((error) => setLoadError(error.message || 'Could not load the matching requirements.'))
      .finally(() => setLoading(false))
  }, [open, jobRequestId, attempt])

  function update(code, patch) {
    setRows((current) => ({
      ...current,
      [code]: { enabled: true, mandatory: false, weight: '0', min: '', max: '', expected: '', ...current[code], ...patch },
    }))
  }

  const typeOf = Object.fromEntries(catalog.map((c) => [c.criteria_code, c.criteria_type]))
  const enabled = Object.entries(rows).filter(([, row]) => row.enabled)

  // Counted the way the server counts it: a hard filter is Must Have even
  // when its box is not ticked, so any points left on it score nothing.
  const totalWeight = enabled
    .filter(([code, row]) => !isMustHave(row.mandatory, typeOf[code]))
    .reduce((sum, [, row]) => sum + (Number(row.weight) || 0), 0)

  const problems = {}
  for (const criterion of catalog) {
    const row = rows[criterion.criteria_code]
    if (!row?.enabled) continue

    const mustHave = isMustHave(row.mandatory, criterion.criteria_type)
    const labels = rangeLabels({ mustHave, lowerIsBetter: criterion.score_direction === 'lower_better' })
    const weight = mustHave ? null : importanceProblem(row.weight, IMPORTANCE_LIMIT)
    const range =
      criterion.accepts === 'none' ? rangeProblem(row.min, row.max, labels, unitFor(criterion.criteria_code)) : null

    if (weight || range) problems[criterion.criteria_code] = { weight, range }
  }
  const hasProblems = Object.keys(problems).length > 0

  async function handleSave() {
    setSaving(true)

    try {
      const criteria = enabled.map(([code, row]) => ({
        criteria_code: code,
        mandatory_flag: row.mandatory,
        weight_score: row.mandatory ? 0 : Number(row.weight) || 0,
        min_value: row.min === '' ? null : Number(row.min),
        max_value: row.max === '' ? null : Number(row.max),
        expected_value: row.expected || null,
      }))

      await post(`/job-requests/${jobRequestId}/criteria`, { criteria })
      toast.success('Matching requirements saved', 'Run an evaluation to rank applicants against them.')
      onOpenChange(false)
      onSaved?.()
    } catch (error) {
      toast.error(
        error.isValidation ? 'Some values need correcting' : 'Could not save the matching requirements',
        error.message
      )
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>Edit matching requirements</DialogTitle>
          <DialogDescription>
            Tick each requirement that matters for this position. <strong>Must Have</strong> requirements
            decide who is eligible. All others add points to the match score, based on their importance.
          </DialogDescription>
        </DialogHeader>

        {loading ? (
          <LoadingState />
        ) : loadError ? (
          <ErrorState message={loadError} onRetry={() => setAttempt((n) => n + 1)} />
        ) : (
          <>
            {prefill && (prefill.fromRequest.length > 0 || prefill.fromCompany.length > 0 || prefill.unmapped.length > 0) && (
              <div className="flex gap-2.5 rounded-md border border-primary/30 bg-primary/5 p-3 text-xs">
                <Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" />
                <div className="space-y-1 text-muted-foreground">
                  {prefill.fromRequest.length > 0 && (
                    <p>
                      <span className="font-medium text-foreground">From this request:</span>{' '}
                      {prefill.fromRequest.join(', ')}.
                    </p>
                  )}
                  {prefill.fromCompany.length > 0 && (
                    <p>
                      <span className="font-medium text-foreground">
                        From {prefill.company ?? 'the client'}'s standard requirements:
                      </span>{' '}
                      {prefill.fromCompany.join(', ')}.
                    </p>
                  )}
                  {prefill.unmapped.map((note) => (
                    <p key={note} className="text-warning">
                      {note}
                    </p>
                  ))}
                  <p>Check the values, set the importance of each scored requirement, then save.</p>
                </div>
              </div>
            )}

            <div className="max-h-[45vh] space-y-2 overflow-y-auto pr-1">
              {catalog.map((criterion) => {
                const code = criterion.criteria_code
                const row = rows[code] ?? {}
                const isEnabled = !!row.enabled
                const alwaysMustHave = criterion.criteria_type === 'hard_filter'
                const mustHave = isMustHave(row.mandatory, criterion.criteria_type)
                const lowerIsBetter = criterion.score_direction === 'lower_better'
                const labels = rangeLabels({ mustHave, lowerIsBetter })
                const unit = unitFor(code)
                const share = totalWeight > 0 && !mustHave ? ((Number(row.weight) || 0) / totalWeight) * 100 : 0
                const problem = problems[code] ?? {}

                return (
                  <div
                    key={criterion.id}
                    className={`rounded-md border p-3 transition-colors ${isEnabled ? 'border-primary/30 bg-primary/[0.03]' : ''}`}
                  >
                    <div className="flex items-start gap-3">
                      <input
                        type="checkbox"
                        className="mt-1 h-4 w-4 shrink-0 rounded border-input"
                        checked={isEnabled}
                        onChange={(e) => update(code, { enabled: e.target.checked })}
                        aria-label={`Use ${criterion.criteria_name}`}
                      />

                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="text-sm font-medium">{criterion.criteria_name}</p>
                          {alwaysMustHave && <Badge tone="warning">Always Must Have</Badge>}
                          {/* Direction only matters where points are at stake. */}
                          {lowerIsBetter && !mustHave && <Badge tone="muted">{directionBadge(code)}</Badge>}
                          {criterion.is_active === false && <Badge tone="muted">Turned off</Badge>}
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">{criterion.description}</p>
                        {/* Listed only because this request already uses it. */}
                        {criterion.is_active === false && (
                          <p className="mt-1 text-xs text-warning">
                            Turned off in Settings. It still counts on this request until you untick it
                            and save; once removed, it cannot be added back.
                          </p>
                        )}

                        {isEnabled && (
                          <div className="mt-3 space-y-3 border-t pt-3">
                            {/* A hard filter is checked as Must Have either way,
                                so its box shows that rather than offering a
                                choice the scoring would ignore. */}
                            <label className="flex items-start gap-2 text-xs">
                              <input
                                type="checkbox"
                                className="mt-0.5 h-3.5 w-3.5 shrink-0 rounded border-input"
                                checked={mustHave}
                                disabled={alwaysMustHave}
                                onChange={(e) => update(code, { mandatory: e.target.checked })}
                              />
                              <span>
                                <span className="font-medium text-foreground">Required for eligibility</span>
                                <span className="text-muted-foreground"> (Must Have)</span>
                                <span className="mt-0.5 block text-muted-foreground">
                                  Pass or fail. Applicants who do not meet it are marked not eligible, whatever
                                  their match score, and listed below everyone eligible. It adds no points.
                                </span>
                              </span>
                            </label>

                            <div className="grid gap-3 sm:grid-cols-3">
                              {!mustHave && (
                                <Field
                                  label="Importance (points)"
                                  htmlFor={`importance-${code}`}
                                  error={problem.weight}
                                  hint={
                                    Number(row.weight) > 0
                                      ? `The most points this can add: ${Math.round(share)}% of the match score.`
                                      : 'The most points this can add to the match score.'
                                  }
                                >
                                  <NumberInput
                                    min="0"
                                    max={IMPORTANCE_LIMIT}
                                    step="0.01"
                                    value={row.weight ?? '0'}
                                    onChange={(e) => update(code, { weight: e.target.value })}
                                    className="h-8"
                                  />
                                </Field>
                              )}

                              {/* Numbers only apply to a requirement measured as
                                  a number, so they are offered only there. */}
                              {criterion.accepts === 'none' && (
                                <>
                                  <Field
                                    label={unit ? `${labels.min.label} (${unit})` : labels.min.label}
                                    htmlFor={`min-${code}`}
                                    error={problem.range?.min}
                                    hint={labels.min.hint}
                                  >
                                    <NumberInput
                                      min={unit ? '0' : undefined}
                                      step="0.01"
                                      value={row.min ?? ''}
                                      placeholder={labels.min.placeholder}
                                      onChange={(e) => update(code, { min: e.target.value })}
                                      className="h-8"
                                    />
                                  </Field>

                                  <Field
                                    label={unit ? `${labels.max.label} (${unit})` : labels.max.label}
                                    htmlFor={`max-${code}`}
                                    error={problem.range?.max}
                                    hint={labels.max.hint}
                                  >
                                    <NumberInput
                                      min={unit ? '0' : undefined}
                                      step="0.01"
                                      value={row.max ?? ''}
                                      placeholder={labels.max.placeholder}
                                      onChange={(e) => update(code, { max: e.target.value })}
                                      className="h-8"
                                    />
                                  </Field>
                                </>
                              )}

                              {/*
                                What this client actually requires. Without it the
                                skills and certifications criteria carried weight
                                but had nothing to look for, so they matched
                                nobody and said nothing about why.
                              */}
                              {criterion.accepts !== 'none' && (
                                <div className={mustHave ? 'sm:col-span-3' : 'sm:col-span-2'}>
                                  <CriteriaValueEditor
                                    accepts={criterion.accepts}
                                    options={criterion.options}
                                    listMatch={criterion.list_match}
                                    value={row.expected ?? ''}
                                    onChange={(next) => update(code, { expected: next ?? '' })}
                                    label={
                                      criterion.accepts === 'list'
                                        ? 'What this client requires'
                                        : code === 'education'
                                          ? 'Minimum Required'
                                          : 'Required value'
                                    }
                                    hint={
                                      code === 'education'
                                        ? 'Applicants at this level or higher meet it.'
                                        : undefined
                                    }
                                    placeholder={code === 'certifications' ? 'e.g. TESDA NC II' : undefined}
                                  />
                                </div>
                              )}
                            </div>

                            {criterion.accepts === 'none' && !problem.range && (
                              <RangeSummary
                                rule={{ min: row.min, max: row.max, mustHave, lowerIsBetter, unit, code }}
                                weight={row.weight}
                              />
                            )}

                            {/*
                              A requirement with nothing to measure against is
                              the failure this whole field exists to prevent,
                              so it is called out rather than left to be
                              discovered in an empty ranking.
                            */}
                            {criterion.accepts !== 'none' && !row.expected && (
                              <p className="flex items-start gap-1.5 text-xs text-warning">
                                <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
                                Nothing entered yet, so any applicant with this recorded on their profile
                                will meet it.
                              </p>
                            )}
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>

            {totalWeight === 0 && enabled.length > 0 && (
              <div className="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/5 p-3">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
                <p className="text-xs text-muted-foreground">
                  Give at least one requirement that is not Must Have an importance above 0. Otherwise
                  every applicant gets a match score of 0 and the ranking cannot tell them apart.
                </p>
              </div>
            )}

            <DialogFooter className="items-center sm:justify-between">
              <p className="text-sm text-muted-foreground">
                Total importance:{' '}
                <span className="font-medium tabular-nums text-foreground">{totalWeight}</span> points
              </p>
              <div className="flex gap-2">
                <Button variant="outline" onClick={() => onOpenChange(false)}>
                  Cancel
                </Button>
                <Button onClick={handleSave} disabled={saving || totalWeight <= 0 || hasProblems}>
                  {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                  Save requirements
                </Button>
              </div>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
