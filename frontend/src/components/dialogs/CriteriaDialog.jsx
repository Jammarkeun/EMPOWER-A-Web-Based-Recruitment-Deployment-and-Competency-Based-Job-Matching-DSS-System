import * as React from 'react'
import { Loader2, AlertTriangle } from 'lucide-react'
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
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { LoadingState } from '@/components/ui/states'

/**
 * Configures which competency criteria apply to a request and what each is worth.
 *
 * The weights are the whole basis of the ranking, so the dialog shows each
 * criterion's share of the total as it is edited. A weight in isolation means
 * nothing; "20 points out of 100" is what an HR officer can actually reason
 * about, and what they will be asked to justify.
 */
export default function CriteriaDialog({ open, onOpenChange, jobRequestId, onSaved }) {
  const [catalog, setCatalog] = React.useState([])
  const [rows, setRows] = React.useState({})
  const [loading, setLoading] = React.useState(true)
  const [saving, setSaving] = React.useState(false)
  const toast = useToast()

  React.useEffect(() => {
    if (!open) return

    setLoading(true)
    get(`/job-requests/${jobRequestId}/criteria`)
      .then((response) => {
        setCatalog(response.data.catalog)

        const configured = {}
        for (const criterion of response.data.configured) {
          configured[criterion.criteria_code] = {
            enabled: true,
            mandatory: criterion.mandatory_flag,
            weight: String(criterion.weight_score ?? 0),
            min: criterion.min_value ?? '',
            max: criterion.max_value ?? '',
            expected: criterion.expected_value ?? '',
          }
        }
        setRows(configured)
      })
      .catch((error) => toast.error('Could not load criteria', error.message))
      .finally(() => setLoading(false))
  }, [open, jobRequestId])

  function update(code, patch) {
    setRows((current) => ({
      ...current,
      [code]: { enabled: true, mandatory: false, weight: '0', min: '', max: '', expected: '', ...current[code], ...patch },
    }))
  }

  const enabled = Object.entries(rows).filter(([, row]) => row.enabled)
  const totalWeight = enabled
    .filter(([, row]) => !row.mandatory)
    .reduce((sum, [, row]) => sum + (Number(row.weight) || 0), 0)

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
      toast.success('Criteria saved', 'Run an evaluation to rank candidates against them.')
      onOpenChange(false)
      onSaved?.()
    } catch (error) {
      toast.error('Could not save criteria', error.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>Competency criteria</DialogTitle>
          <DialogDescription>
            Choose what matters for this position and how much each factor is worth. Mandatory
            criteria act as eligibility gates rather than scored points.
          </DialogDescription>
        </DialogHeader>

        {loading ? (
          <LoadingState />
        ) : (
          <>
            <div className="max-h-[45vh] space-y-2 overflow-y-auto pr-1">
              {catalog.map((criterion) => {
                const row = rows[criterion.criteria_code] ?? {}
                const isEnabled = !!row.enabled
                const share = totalWeight > 0 && !row.mandatory ? ((Number(row.weight) || 0) / totalWeight) * 100 : 0

                return (
                  <div
                    key={criterion.id}
                    className={`rounded-md border p-3 transition-colors ${isEnabled ? 'border-primary/30 bg-primary/[0.03]' : ''}`}
                  >
                    <div className="flex flex-wrap items-start gap-3">
                      <input
                        type="checkbox"
                        className="mt-1 h-4 w-4 rounded border-input"
                        checked={isEnabled}
                        onChange={(e) => update(criterion.criteria_code, { enabled: e.target.checked })}
                        aria-label={`Use ${criterion.criteria_name}`}
                      />

                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="text-sm font-medium">{criterion.criteria_name}</p>
                          {criterion.criteria_type === 'hard_filter' && <Badge tone="warning">Gate by default</Badge>}
                          {criterion.score_direction === 'lower_better' && <Badge tone="muted">Lower is better</Badge>}
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">{criterion.description}</p>

                        {isEnabled && (
                          <div className="mt-3 grid gap-2 sm:grid-cols-4">
                            <label className="flex items-center gap-2 text-xs">
                              <input
                                type="checkbox"
                                className="h-3.5 w-3.5 rounded border-input"
                                checked={!!row.mandatory}
                                onChange={(e) => update(criterion.criteria_code, { mandatory: e.target.checked })}
                              />
                              Mandatory
                            </label>

                            {!row.mandatory && (
                              <div>
                                <label className="text-xs text-muted-foreground">Weight</label>
                                <Input
                                  type="number"
                                  min="0"
                                  value={row.weight ?? '0'}
                                  onChange={(e) => update(criterion.criteria_code, { weight: e.target.value })}
                                  className="h-8"
                                />
                              </div>
                            )}

                            <div>
                              <label className="text-xs text-muted-foreground">Min</label>
                              <Input
                                type="number"
                                value={row.min ?? ''}
                                onChange={(e) => update(criterion.criteria_code, { min: e.target.value })}
                                className="h-8"
                              />
                            </div>

                            <div>
                              <label className="text-xs text-muted-foreground">Max</label>
                              <Input
                                type="number"
                                value={row.max ?? ''}
                                onChange={(e) => update(criterion.criteria_code, { max: e.target.value })}
                                className="h-8"
                              />
                            </div>

                            {!row.mandatory && Number(row.weight) > 0 && (
                              <p className="text-xs text-muted-foreground sm:col-span-4">
                                Worth {Math.round(share)}% of the total score
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
                  At least one non-mandatory criterion needs a weight above zero, otherwise every
                  applicant scores zero and the ranking carries no information.
                </p>
              </div>
            )}

            <DialogFooter className="items-center sm:justify-between">
              <p className="text-sm text-muted-foreground">
                Total weight: <span className="font-medium tabular-nums text-foreground">{totalWeight}</span> points
              </p>
              <div className="flex gap-2">
                <Button variant="outline" onClick={() => onOpenChange(false)}>
                  Cancel
                </Button>
                <Button onClick={handleSave} disabled={saving || totalWeight <= 0}>
                  {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                  Save criteria
                </Button>
              </div>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
