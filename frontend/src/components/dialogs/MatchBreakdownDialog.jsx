import * as React from 'react'
import { Sparkles, CheckCircle2, XCircle, Scale, ShieldCheck, Info } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { StatusBadge } from '@/components/ui/badge'
import { cn, formatDateTime } from '@/lib/utils'
import CriterionDetails from '@/components/CriterionDetails'

/**
 * How one applicant's score was reached, criterion by criterion.
 *
 * Read straight from the ranking the engine saved: the percentage, the
 * recommendation band (set by the administrator, not by this screen), every
 * mandatory requirement and whether it was met, and every scored criterion with
 * the points awarded and the reason. Nothing is recomputed here, so what HR
 * reads is exactly what the engine decided and why.
 */
export default function MatchBreakdownDialog({ open, onOpenChange, candidate, jobPosition }) {
  if (!candidate) return null

  const {
    applicant_name,
    percentage_score = 0,
    raw_score,
    max_score,
    hard_filter_pass = true,
    recommendation_level,
    recommendation_label,
    breakdown,
    evaluated_at,
  } = candidate

  const criteria = breakdown?.criteria ?? []
  const gates = criteria.filter((c) => c.is_gate)
  const scored = criteria.filter((c) => !c.is_gate)
  const failedGates = breakdown?.failed_gates ?? []

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl sm:max-w-3xl">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <Sparkles className="h-5 w-5 text-primary" />
            <DialogTitle className="text-xl">How this score was reached</DialogTitle>
          </div>
          <DialogDescription>
            <strong className="text-foreground">{applicant_name}</strong> against{' '}
            <strong className="text-foreground">{jobPosition ?? 'this request'}</strong>
            {evaluated_at && <> · evaluated {formatDateTime(evaluated_at)}</>}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-5 py-2">
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-1 rounded-row bg-inset p-4">
              <span className="text-xs font-medium uppercase tracking-wider text-muted-foreground">Score</span>
              <div className="flex items-baseline gap-1.5">
                <span className="text-3xl font-bold tabular-nums text-primary">{percentage_score}%</span>
                {max_score > 0 && (
                  <span className="text-xs text-muted-foreground">
                    {raw_score} of {max_score} pts
                  </span>
                )}
              </div>
            </div>

            <div className="space-y-1 rounded-row bg-inset p-4">
              <span className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                Mandatory requirements
              </span>
              <div className="flex items-center gap-1.5 pt-1">
                {hard_filter_pass ? (
                  <>
                    <CheckCircle2 className="h-5 w-5 text-success" />
                    <span className="text-sm font-semibold text-success">
                      {gates.length ? `All ${gates.length} met` : 'None set'}
                    </span>
                  </>
                ) : (
                  <>
                    <XCircle className="h-5 w-5 text-destructive" />
                    <span className="text-sm font-semibold text-destructive">
                      {failedGates.length} not met
                    </span>
                  </>
                )}
              </div>
            </div>

            <div className="space-y-1 rounded-row bg-inset p-4">
              <span className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                Recommendation
              </span>
              <div className="pt-1">
                <StatusBadge status={recommendation_level} label={recommendation_label} />
              </div>
            </div>
          </div>

          {!hard_filter_pass && failedGates.length > 0 && (
            <div className="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/10 p-3.5">
              <XCircle className="mt-0.5 h-5 w-5 shrink-0 text-destructive" />
              <p className="text-xs text-destructive/90">
                Not eligible regardless of score: the client requires{' '}
                <span className="font-medium">{failedGates.join(', ')}</span>, and this applicant does not
                meet {failedGates.length === 1 ? 'it' : 'them'}.
              </p>
            </div>
          )}

          {gates.length > 0 && (
            <div className="space-y-2">
              <h4 className="flex items-center gap-1.5 text-sm font-semibold">
                <ShieldCheck className="h-4 w-4 text-primary" />
                Mandatory requirements
              </h4>
              <ul className="space-y-2">
                {gates.map((item) => (
                  <li key={item.criteria} className="rounded-row bg-inset p-3 text-xs">
                    <div className="flex items-center gap-2">
                      {item.passed ? (
                        <CheckCircle2 className="h-4 w-4 shrink-0 text-success" />
                      ) : (
                        <XCircle className="h-4 w-4 shrink-0 text-destructive" />
                      )}
                      <span className="text-sm font-semibold">{item.label}</span>
                    </div>
                    <p className="mt-1 pl-6 text-muted-foreground">{item.explanation}</p>
                    <CriterionDetails details={item.details} className="pl-6" />
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="space-y-2">
            <h4 className="flex items-center gap-1.5 text-sm font-semibold">
              <Scale className="h-4 w-4 text-primary" />
              Scored criteria
            </h4>

            {scored.length > 0 ? (
              <div className="max-h-[22rem] space-y-2 overflow-y-auto pr-1">
                {scored.map((item) => {
                  const pct = item.weight > 0 ? Math.min(100, Math.round((item.score / item.weight) * 100)) : 0

                  return (
                    <div key={item.criteria} className="space-y-2 rounded-row bg-inset p-3 text-xs">
                      <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                          {item.score > 0 ? (
                            <CheckCircle2 className="h-4 w-4 shrink-0 text-success" />
                          ) : (
                            <XCircle className="h-4 w-4 shrink-0 text-destructive" />
                          )}
                          <span className="text-sm font-semibold text-foreground">{item.label}</span>
                        </div>
                        <div className="flex items-center gap-2 font-mono text-muted-foreground">
                          <span>
                            {item.score} / {item.weight} pts
                          </span>
                          <span className="font-semibold text-foreground">({pct}%)</span>
                        </div>
                      </div>

                      <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                        <div
                          className={cn(
                            'h-full transition-all duration-300',
                            pct >= 100 ? 'bg-success' : pct > 0 ? 'bg-warning' : 'bg-destructive'
                          )}
                          style={{ width: `${pct}%` }}
                        />
                      </div>

                      <p className="pl-6 text-[11px] text-muted-foreground">{item.explanation}</p>
                      <CriterionDetails details={item.details} className="pl-6" />
                    </div>
                  )
                })}
              </div>
            ) : (
              <div className="rounded-lg border border-dashed p-6 text-center text-xs text-muted-foreground">
                No scored criteria on this evaluation.
              </div>
            )}
          </div>

          <p className="flex items-start gap-2 text-xs text-muted-foreground">
            <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            Mandatory requirements decide eligibility and carry no points. The score is the points
            awarded over the points available on the scored criteria. It is a recommendation; nobody is
            deployed until HR records it.
          </p>
        </div>
      </DialogContent>
    </Dialog>
  )
}
