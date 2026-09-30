import * as React from 'react'
import { Sparkles, CheckCircle2, XCircle, Info, Scale, ShieldCheck } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

/**
 * MatchBreakdownDialog displays an interactive, granular breakdown of how
 * an applicant's score was computed by the Competency Matching DSS Engine.
 */
export default function MatchBreakdownDialog({ open, onOpenChange, candidate, jobPosition }) {
  if (!candidate) return null

  const {
    applicant_name,
    overall_score = 0,
    hard_filter_pass = true,
    breakdown = [],
    failed_filters = [],
    created_at,
  } = candidate

  const scorePercentage = Math.round(overall_score)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl sm:max-w-3xl">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <Sparkles className="h-5 w-5 text-primary" />
            <DialogTitle className="text-xl">Competency DSS Match Breakdown</DialogTitle>
          </div>
          <DialogDescription>
            Scoring algorithm trace for <strong className="text-foreground">{applicant_name}</strong> against{' '}
            <strong className="text-foreground">{jobPosition ?? 'Position'}</strong>.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-5 py-2">
          {/* Top Score Summary Banner */}
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="rounded-xl border bg-card p-4 space-y-1 text-center sm:text-left">
              <span className="text-xs uppercase tracking-wider text-muted-foreground font-medium">Overall Match</span>
              <div className="flex items-baseline gap-1 justify-center sm:justify-start">
                <span className="text-3xl font-bold tabular-nums text-primary">{scorePercentage}%</span>
                <span className="text-xs text-muted-foreground">fit score</span>
              </div>
            </div>

            <div className="rounded-xl border bg-card p-4 space-y-1 text-center sm:text-left">
              <span className="text-xs uppercase tracking-wider text-muted-foreground font-medium">Mandatory Criteria</span>
              <div className="flex items-center gap-1.5 justify-center sm:justify-start pt-1">
                {hard_filter_pass ? (
                  <>
                    <CheckCircle2 className="h-5 w-5 text-success" />
                    <span className="text-sm font-semibold text-success">All Mandatory Passed</span>
                  </>
                ) : (
                  <>
                    <XCircle className="h-5 w-5 text-destructive" />
                    <span className="text-sm font-semibold text-destructive">Disqualified by Filter</span>
                  </>
                )}
              </div>
            </div>

            <div className="rounded-xl border bg-card p-4 space-y-1 text-center sm:text-left">
              <span className="text-xs uppercase tracking-wider text-muted-foreground font-medium">Suitability Status</span>
              <div className="pt-1">
                <Badge
                  variant={
                    scorePercentage >= 80 && hard_filter_pass
                      ? 'success'
                      : scorePercentage >= 60 && hard_filter_pass
                      ? 'warning'
                      : 'destructive'
                  }
                  className="px-2.5 py-1 text-xs"
                >
                  {scorePercentage >= 80 && hard_filter_pass
                    ? 'Highly Qualified'
                    : scorePercentage >= 60 && hard_filter_pass
                    ? 'Potentially Suitable'
                    : 'Unsuited'}
                </Badge>
              </div>
            </div>
          </div>

          {/* Disqualification warning banner if mandatory criteria failed */}
          {!hard_filter_pass && failed_filters.length > 0 && (
            <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3.5 flex items-start gap-3">
              <XCircle className="h-5 w-5 text-destructive shrink-0 mt-0.5" />
              <div className="space-y-1 text-xs">
                <p className="font-semibold text-destructive">Mandatory Filter Failure</p>
                <p className="text-destructive/90">
                  This candidate was flagged as unsuited because they failed the following mandatory requirement(s):{' '}
                  <span className="font-medium underline">{failed_filters.join(', ')}</span>.
                </p>
              </div>
            </div>
          )}

          {/* Breakdown Items List */}
          <div className="space-y-3">
            <h4 className="text-sm font-semibold flex items-center gap-1.5">
              <Scale className="h-4 w-4 text-primary" />
              Criteria Evaluation Matrix
            </h4>

            {Array.isArray(breakdown) && breakdown.length > 0 ? (
              <div className="space-y-2 max-h-[22rem] overflow-y-auto pr-1">
                {breakdown.map((item, idx) => {
                  const weight = Number(item.weight ?? item.criteria_weight ?? 0)
                  const score = Number(item.score ?? item.earned_score ?? 0)
                  const pct = weight > 0 ? Math.min(100, Math.round((score / weight) * 100)) : 0
                  const isHard = Boolean(item.is_mandatory || item.type === 'hard_filter')

                  return (
                    <div key={item.code ?? idx} className="rounded-lg border p-3 bg-card space-y-2 text-xs">
                      <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                          {item.passed !== false ? (
                            <CheckCircle2 className="h-4 w-4 text-success shrink-0" />
                          ) : (
                            <XCircle className="h-4 w-4 text-destructive shrink-0" />
                          )}
                          <span className="font-semibold text-foreground text-sm">
                            {item.title ?? item.criterion_name ?? item.code}
                          </span>
                          {isHard && (
                            <Badge variant="outline" className="text-[10px] uppercase border-destructive/40 text-destructive">
                              Mandatory
                            </Badge>
                          )}
                        </div>

                        <div className="flex items-center gap-2 font-mono text-muted-foreground">
                          <span>
                            {score} / {weight} pts
                          </span>
                          <span className="font-semibold text-foreground">({pct}%)</span>
                        </div>
                      </div>

                      {/* Progress bar */}
                      <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                        <div
                          className={cn(
                            'h-full transition-all duration-300',
                            item.passed === false
                              ? 'bg-destructive'
                              : pct >= 80
                              ? 'bg-success'
                              : pct >= 50
                              ? 'bg-warning'
                              : 'bg-destructive'
                          )}
                          style={{ width: `${pct}%` }}
                        />
                      </div>

                      {item.explanation && (
                        <p className="text-muted-foreground italic pl-6 text-[11px]">
                          Reason: {item.explanation}
                        </p>
                      )}
                    </div>
                  )
                })}
              </div>
            ) : (
              <div className="rounded-lg border border-dashed p-6 text-center text-xs text-muted-foreground">
                No detailed criterion breakdown available for this match trace.
              </div>
            )}
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}

