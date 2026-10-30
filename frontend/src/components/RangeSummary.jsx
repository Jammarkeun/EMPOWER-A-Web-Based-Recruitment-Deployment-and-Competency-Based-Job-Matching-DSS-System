import { describeRange, pointsExample } from '@/lib/requirementRange'

/**
 * The rule a requirement's two numbers make, read back as they are typed, with
 * worked figures when the points change gradually. Used by both requirement
 * editors, the request's and the client's.
 */
export default function RangeSummary({ rule, weight }) {
  const example = pointsExample({ ...rule, weight })

  return (
    <div className="space-y-0.5 rounded-md bg-muted/40 px-2.5 py-2 text-xs text-muted-foreground" aria-live="polite">
      <p>{describeRange(rule)}</p>
      {example && <p>{example}</p>}
    </div>
  )
}
