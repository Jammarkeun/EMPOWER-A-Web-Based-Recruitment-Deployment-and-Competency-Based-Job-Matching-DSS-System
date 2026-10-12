import * as React from 'react'
import { Check, X } from 'lucide-react'
import { cn } from '@/lib/utils'

/**
 * A list criterion, item by item: what the client required, and which of it
 * the applicant holds.
 *
 * "3 of 4 skills" is a number; Communication ✓, Customer Service ✓,
 * Teamwork ✓, Computer Literacy ✗ is a reason somebody can repeat to the
 * client or to the applicant. Renders nothing for criteria measured as a
 * single value.
 */
export default function CriterionDetails({ details, className }) {
  if (!details?.required?.length) return null

  const matched = new Set(details.matched ?? [])

  return (
    <ul className={cn('mt-1.5 flex flex-wrap gap-1.5', className)}>
      {details.required.map((item) => {
        const held = matched.has(item)

        return (
          <li
            key={item}
            className={cn(
              'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px]',
              held
                ? 'border-status-success-border bg-status-success-bg text-status-success-text'
                : 'border-status-danger-border bg-status-danger-bg text-status-danger-text'
            )}
          >
            {held ? <Check className="h-3 w-3" /> : <X className="h-3 w-3" />}
            {item}
            <span className="sr-only">{held ? 'held' : 'missing'}</span>
          </li>
        )
      })}
      {details.mode === 'any' && (
        <li className="self-center text-[11px] text-muted-foreground">Any one is enough</li>
      )}
    </ul>
  )
}
