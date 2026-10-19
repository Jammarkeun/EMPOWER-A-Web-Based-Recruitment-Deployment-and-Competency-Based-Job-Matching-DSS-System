import * as React from 'react'
import { Link } from 'react-router-dom'
import { Check, Circle, Dot, ArrowRight, Loader2, Archive, IdCard } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn, humanise } from '@/lib/utils'

/**
 * The recruitment process, as a visible path.
 *
 * The fifteen statuses the system stores are the real workflow, but they are too
 * fine-grained to read at a glance, so they are shown in the six stages people
 * actually talk about. The stages come from the server (ApplicantStatuses), in
 * lifecycle order, so this path and the portal's can never disagree.
 *
 * They used to be defined here, with "ready for deployment" placed after
 * training and client evaluation. Someone who had only finished their
 * documents was shown with training and client evaluation ticked as done,
 * and the real next step - endorsing them - appeared as a sideways move.
 *
 * Nothing here changes what the system stores or which moves it permits: the
 * server owns the transition rules, and this component only ever offers what
 * the server said was allowed.
 */

/** What each status means for the officer looking at it. */
export const STATUS_HINTS = {
  applied: 'Received. Waiting to be screened.',
  initial_screening: 'Details and documents being reviewed.',
  incomplete_requirements: 'Documents are still outstanding.',
  primary_requirements_complete: 'Primary documents verified. The medical results come next.',
  pending_final_requirements: 'Waiting for the medical results.',
  ready_for_deployment: 'Every document is verified. Training comes next.',
  training_scheduled: 'Enrolled in a training session.',
  training_completed: 'Training completed. Ready to endorse to a client.',
  client_evaluation: 'Endorsed to a client company for its evaluation.',
  approved: 'Approved by the client company. Ready to deploy.',
  deployed: 'Placed with a client company.',
  active: 'Placed with a client company.',
}

/** The forward move named as the act it records, where a stage name would be vague. */
const ACTION_LABELS = {
  client_evaluation: 'Endorse to a client',
  approved: 'Record client approval',
  deployed: 'Record deployment',
}

/** Statuses that sit outside the forward path rather than on it. */
const TERMINAL = ['resigned', 'terminated', 'archived']

/**
 * Statuses that belong to employment rather than recruitment.
 *
 * Once somebody is deployed, the rest of their lifecycle - schedule, DTR,
 * discipline, resignation, termination - is run from the employee record, and
 * none of it is a button here. Offering "Terminated" as a one-click move on
 * this panel is exactly what used to end employment with no record behind it.
 */
const EMPLOYMENT = ['deployed', 'active', 'resigned', 'terminated']

export function stageIndexOf(stages, status) {
  return stages.findIndex((stage) => stage.statuses.includes(status))
}

/**
 * Splits the permitted moves into the way forward and everything else.
 *
 * Archiving and sending a record back a stage are both legitimate, but they
 * are not what someone is looking for when they open this panel.
 */
export function splitTransitions(stages, current, transitions) {
  const currentIndex = stageIndexOf(stages, current)
  const forward = transitions.filter((status) => {
    const index = stageIndexOf(stages, status)
    return index > -1 && index >= currentIndex && !TERMINAL.includes(status)
  })
  return { forward, other: transitions.filter((status) => !forward.includes(status)) }
}

export default function ApplicantProgress({
  applicant,
  stages = [],
  transitions = [],
  onMove,
  busy,
  canChange,
  employeeId,
  blockedMoves = {},
}) {
  const current = applicant.current_status
  const currentIndex = stageIndexOf(stages, current)
  const isTerminal = TERMINAL.includes(current)
  const { forward, other } = splitTransitions(stages, current, transitions)
  const nextStatus = forward[0]
  const blocked = nextStatus ? blockedMoves[nextStatus] : null

  const describe = (status) =>
    ACTION_LABELS[status] ?? `Move to ${stages.find((s) => s.statuses.includes(status))?.label ?? humanise(status)}`

  return (
    <div className="rounded-panel bg-card p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Current stage</p>
          <p className="mt-0.5 text-lg font-semibold tracking-tight">
            {isTerminal ? humanise(current) : (stages[currentIndex]?.label ?? humanise(current))}
          </p>
          <p className="text-xs text-muted-foreground">
            {isTerminal ? 'This record is closed.' : (STATUS_HINTS[current] ?? humanise(current))}
          </p>
        </div>

        {canChange && nextStatus && (
          <div className="flex flex-col items-end gap-1">
            <Button onClick={() => onMove(nextStatus)} disabled={busy || Boolean(blocked)}>
              {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <ArrowRight className="h-4 w-4" />}
              {describe(nextStatus)}
            </Button>
            {blocked && <p className="max-w-xs text-right text-xs text-muted-foreground">{blocked}</p>}
          </div>
        )}
      </div>

      {/* The path itself. Horizontal on a wide screen, stacked on a narrow one,
          so the sequence survives on a phone rather than being squeezed. */}
      {stages.length > 0 && (
        <ol className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-6 lg:gap-0" aria-label="Recruitment stages">
          {stages.map((stage, index) => {
            const done = currentIndex > index && !isTerminal
            const here = currentIndex === index && !isTerminal

            return (
              <li
                key={stage.key}
                className="relative flex items-start gap-3 lg:flex-col lg:gap-0"
                aria-current={here ? 'step' : undefined}
              >
                {/* The connector belongs to the step it leaves, so it cannot drift
                    out of alignment as the column count changes at each
                    breakpoint. The last stage has none. */}
                {index < stages.length - 1 && (
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
                    <span className="sr-only">{done ? ' (done)' : here ? ' (current)' : ' (not reached)'}</span>
                  </p>
                  {here && <p className="mt-0.5 text-xs text-muted-foreground">{humanise(current)}</p>}
                </div>
              </li>
            )
          })}
        </ol>
      )}

      {/* Moves that are allowed but are not the forward path: sending a record
          back for missing documents, or closing it. Kept visually quieter so
          they are available without competing with the main action. */}
      {canChange && other.length > 0 && (
        <div className="mt-4 flex flex-wrap items-center gap-2 border-t pt-3">
          <span className="text-xs text-muted-foreground">Other moves:</span>
          {other.map((status) => (
            <Button key={status} variant="outline" size="sm" disabled={busy} onClick={() => onMove(status)}>
              {status === 'archived' && <Archive className="h-3.5 w-3.5" />}
              {humanise(status)}
            </Button>
          ))}
        </div>
      )}

      {EMPLOYMENT.includes(current) && employeeId ? (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t pt-3">
          <p className="text-xs text-muted-foreground">
            {['resigned', 'terminated'].includes(current)
              ? 'Employment ended through the separation process. The full record is on the employee file.'
              : 'Employment is managed on the employee record: schedule and DTR, discipline, and resignation or termination.'}
          </p>
          <Button variant="outline" size="sm" asChild>
            <Link to={`/employees/${employeeId}`}>
              <IdCard className="h-3.5 w-3.5" />
              Open employee record
            </Link>
          </Button>
        </div>
      ) : (
        canChange &&
        transitions.length === 0 && (
          <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">There is no available next stage from here.</p>
        )
      )}
    </div>
  )
}
