import * as React from 'react'
import { Check, Circle, Dot, ArrowRight, Loader2, Archive } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn, humanise } from '@/lib/utils'

/**
 * The recruitment process, as a visible path.
 *
 * The fifteen statuses the system stores are the real workflow, but they are too
 * fine-grained to read at a glance — and offering them in a dropdown asked an HR
 * officer to know the whole map by heart just to move someone one step. Here
 * they are collapsed into the six stages people actually talk about, so the
 * question "where is this applicant?" is answered by looking rather than by
 * reading a list of options.
 *
 * The grouping is presentational only. Nothing here changes what the system
 * stores or which moves it permits: the server still owns the transition rules,
 * and this component only ever offers what the server said was allowed.
 */
const STAGES = [
  {
    key: 'application',
    label: 'Application',
    statuses: ['applied'],
    hint: 'Received. Identity not yet checked.',
  },
  {
    key: 'screening',
    label: 'Screening',
    statuses: ['initial_screening'],
    hint: 'Details and documents being reviewed.',
  },
  {
    key: 'requirements',
    label: 'Requirements',
    statuses: ['incomplete_requirements', 'primary_requirements_complete', 'pending_final_requirements'],
    hint: 'Collecting and verifying documents.',
  },
  {
    key: 'processing',
    label: 'Processing',
    statuses: ['training_scheduled', 'training_completed', 'client_evaluation'],
    hint: 'Training and client evaluation.',
  },
  {
    key: 'ready',
    label: 'Ready',
    statuses: ['ready_for_deployment', 'approved'],
    hint: 'All requirements verified. Awaiting placement.',
  },
  {
    key: 'deployed',
    label: 'Deployed',
    statuses: ['deployed', 'active'],
    hint: 'Placed with a client company.',
  },
]

/** Statuses that sit outside the forward path rather than on it. */
const TERMINAL = ['resigned', 'terminated', 'archived']

function stageIndexOf(status) {
  return STAGES.findIndex((stage) => stage.statuses.includes(status))
}

/**
 * Which stage a permitted next status belongs to.
 *
 * Several statuses can share a stage, so the button is labelled by stage and the
 * exact status is what actually gets sent.
 */
function describeTransition(status) {
  const stage = STAGES.find((s) => s.statuses.includes(status))
  return stage ? stage.label : humanise(status)
}

export default function ApplicantProgress({ applicant, transitions = [], onMove, busy, canChange }) {
  const current = applicant.current_status
  const currentIndex = stageIndexOf(current)
  const isTerminal = TERMINAL.includes(current)

  /*
   * The forward moves, separated from the sideways ones.
   *
   * Archiving and sending a record back a stage are both legitimate, but they
   * are not what someone is looking for when they open this panel. Putting them
   * beside "move forward" as equal options is what made the old dropdown
   * confusing.
   */
  const forward = transitions.filter((s) => {
    const index = stageIndexOf(s)
    return index > -1 && index >= currentIndex && !TERMINAL.includes(s)
  })
  const other = transitions.filter((s) => !forward.includes(s))

  const nextStatus = forward[0]

  return (
    <div className="rounded-xl border bg-card p-4 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Current stage
          </p>
          <p className="mt-0.5 text-lg font-semibold tracking-tight">
            {isTerminal ? humanise(current) : (STAGES[currentIndex]?.label ?? humanise(current))}
          </p>
          <p className="text-xs text-muted-foreground">
            {isTerminal
              ? 'This record is closed.'
              : (STAGES[currentIndex]?.hint ?? humanise(current))}
          </p>
        </div>

        {canChange && nextStatus && (
          <Button onClick={() => onMove(nextStatus)} disabled={busy}>
            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <ArrowRight className="h-4 w-4" />}
            Move to {describeTransition(nextStatus)}
          </Button>
        )}
      </div>

      {/* The path itself. Horizontal on a wide screen, stacked on a narrow one,
          so the sequence survives on a phone rather than being squeezed. */}
      <ol className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6 lg:gap-0">
        {STAGES.map((stage, index) => {
          const done = currentIndex > index && !isTerminal
          const here = currentIndex === index && !isTerminal

          return (
            <li key={stage.key} className="relative flex items-start gap-3 lg:flex-col lg:gap-0">
              {/* The connector belongs to the step it leaves, so it cannot drift
                  out of alignment as the column count changes at each
                  breakpoint. The last stage has none. */}
              {index < STAGES.length - 1 && (
                <span
                  aria-hidden="true"
                  className={cn(
                    'absolute left-[11px] top-7 h-[calc(100%-0.5rem)] w-px lg:left-6 lg:top-[11px] lg:h-px lg:w-[calc(100%-1.5rem)]',
                    done ? 'bg-primary/40' : 'bg-border'
                  )}
                />
              )}

              <span
                className={cn(
                  'relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 transition-colors',
                  done && 'border-primary bg-primary text-primary-foreground',
                  here && 'border-primary bg-card text-primary',
                  !done && !here && 'border-border bg-card text-muted-foreground/40'
                )}
              >
                {done ? (
                  <Check className="h-3 w-3" strokeWidth={3} />
                ) : here ? (
                  <Dot className="h-5 w-5" strokeWidth={6} />
                ) : (
                  <Circle className="h-2 w-2" strokeWidth={4} />
                )}
              </span>

              <div className="min-w-0 lg:mt-2 lg:pr-3">
                <p
                  className={cn(
                    'text-sm leading-tight',
                    here ? 'font-semibold text-foreground' : done ? 'font-medium' : 'text-muted-foreground'
                  )}
                >
                  {stage.label}
                </p>
                {here && (
                  <p className="mt-0.5 text-xs text-muted-foreground">{humanise(current)}</p>
                )}
              </div>
            </li>
          )
        })}
      </ol>

      {/* Moves that are allowed but are not the forward path: sending a record
          back for missing documents, or closing it. Kept visually quieter so
          they are available without competing with the main action. */}
      {canChange && other.length > 0 && (
        <div className="mt-4 flex flex-wrap items-center gap-2 border-t pt-3">
          <span className="text-xs text-muted-foreground">Other moves:</span>
          {other.map((status) => (
            <Button
              key={status}
              variant="outline"
              size="sm"
              disabled={busy}
              onClick={() => onMove(status)}
            >
              {status === 'archived' && <Archive className="h-3.5 w-3.5" />}
              {humanise(status)}
            </Button>
          ))}
        </div>
      )}

      {canChange && transitions.length === 0 && (
        <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">
          There is no available next stage from here.
        </p>
      )}
    </div>
  )
}

export { STAGES, stageIndexOf }
