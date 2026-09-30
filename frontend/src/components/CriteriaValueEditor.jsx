import * as React from 'react'
import { Plus, X, Pencil, Check } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { cn, humanise } from '@/lib/utils'

/**
 * The values a competency criterion is measured against.
 *
 * This is what a client company actually asks for, and it differs between them:
 * a food manufacturer wants machine operation and safety procedures, a hospital
 * wants customer service and teamwork. Until now there was no way to enter any
 * of it — the criteria form offered a weight and a min/max and nothing else, so
 * the skills and certifications criteria could be switched on and weighted but
 * never told what to look for. They matched nobody and explained nothing.
 *
 * Three shapes, decided by the API rather than here:
 *
 * - `list`   — free text, as many as the client requires. Holding any one of
 *              them satisfies the criterion, which is why they are entered as
 *              separate items rather than as one sentence: the engine matches
 *              each in turn, and a comma typed inside an item would silently
 *              split it.
 * - `choice` — a fixed vocabulary the scoring engine recognises. Offered as a
 *              menu because an unrecognised value is not a validation error; it
 *              simply never matches, and nothing on screen would say why.
 * - `none`   — the criterion is measured numerically and has no expected value.
 *
 * Stored as the comma-separated string the engine already reads, so nothing
 * downstream changes.
 */

/** Splits the stored string into items, dropping blanks left by stray commas. */
export function parseValues(stored) {
  return String(stored ?? '')
    .split(',')
    .map((value) => value.trim())
    .filter(Boolean)
}

/** Joins items back into the single column the scoring engine reads. */
export function joinValues(values) {
  return values.length ? values.join(', ') : null
}

/** Case- and spacing-insensitive, matching how the engine compares them. */
function isDuplicate(values, candidate, exceptIndex = -1) {
  const key = candidate.trim().toLowerCase().replace(/\s+/g, ' ')

  return values.some(
    (value, index) =>
      index !== exceptIndex && value.trim().toLowerCase().replace(/\s+/g, ' ') === key
  )
}

export default function CriteriaValueEditor({ accepts, options, value, onChange, label, hint }) {
  if (accepts === 'choice') {
    return (
      <div className="space-y-1">
        <label className="text-xs text-muted-foreground">{label ?? 'Required'}</label>
        <Select
          value={value ?? ''}
          onChange={(event) => onChange(event.target.value || null)}
          className="h-8"
        >
          <option value="">No requirement</option>
          {(options ?? []).map((option) => (
            <option key={option} value={option}>
              {humanise(option)}
            </option>
          ))}
        </Select>
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </div>
    )
  }

  if (accepts !== 'list') return null

  return <ValueList value={value} onChange={onChange} label={label} hint={hint} />
}

function ValueList({ value, onChange, label, hint }) {
  const values = parseValues(value)

  const [draft, setDraft] = React.useState('')
  const [error, setError] = React.useState('')
  const [editingIndex, setEditingIndex] = React.useState(-1)
  const [editingDraft, setEditingDraft] = React.useState('')

  function commit(next) {
    onChange(joinValues(next))
  }

  function add() {
    const candidate = draft.trim()

    // Silently ignoring an empty submit would leave somebody pressing Add and
    // watching nothing happen, so it says what it wants instead.
    if (!candidate) {
      setError('Type the requirement first.')
      return
    }

    if (candidate.includes(',')) {
      setError('Add one at a time — a comma separates them in storage.')
      return
    }

    if (isDuplicate(values, candidate)) {
      setError(`"${candidate}" is already on the list.`)
      return
    }

    commit([...values, candidate])
    setDraft('')
    setError('')
  }

  function saveEdit(index) {
    const candidate = editingDraft.trim()

    if (!candidate) {
      setError('A requirement cannot be blank. Remove it instead.')
      return
    }

    if (isDuplicate(values, candidate, index)) {
      setError(`"${candidate}" is already on the list.`)
      return
    }

    commit(values.map((existing, i) => (i === index ? candidate : existing)))
    setEditingIndex(-1)
    setError('')
  }

  return (
    <div className="space-y-2">
      <label className="text-xs text-muted-foreground">{label ?? 'Accepted values'}</label>

      {values.length > 0 && (
        <ul className="flex flex-wrap gap-1.5">
          {values.map((item, index) =>
            index === editingIndex ? (
              <li key={`${item}-${index}`} className="flex items-center gap-1">
                <Input
                  value={editingDraft}
                  autoFocus
                  onChange={(event) => setEditingDraft(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                      event.preventDefault()
                      saveEdit(index)
                    }
                    if (event.key === 'Escape') {
                      setEditingIndex(-1)
                      setError('')
                    }
                  }}
                  className="h-7 w-44 text-xs"
                  aria-label={`Edit ${item}`}
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-7 w-7"
                  onClick={() => saveEdit(index)}
                  aria-label="Save"
                >
                  <Check className="h-3.5 w-3.5" />
                </Button>
              </li>
            ) : (
              <li
                key={`${item}-${index}`}
                className="inline-flex items-center gap-1 rounded-full border bg-secondary py-0.5 pl-2.5 pr-1 text-xs"
              >
                <span>{item}</span>
                <button
                  type="button"
                  onClick={() => {
                    setEditingIndex(index)
                    setEditingDraft(item)
                    setError('')
                  }}
                  className="rounded p-0.5 text-muted-foreground transition-colors hover:text-foreground"
                  aria-label={`Edit ${item}`}
                >
                  <Pencil className="h-3 w-3" />
                </button>
                <button
                  type="button"
                  onClick={() => {
                    commit(values.filter((_, i) => i !== index))
                    setError('')
                  }}
                  className="rounded p-0.5 text-muted-foreground transition-colors hover:text-destructive"
                  aria-label={`Remove ${item}`}
                >
                  <X className="h-3 w-3" />
                </button>
              </li>
            )
          )}
        </ul>
      )}

      <div className="flex gap-1.5">
        <Input
          value={draft}
          onChange={(event) => {
            setDraft(event.target.value)
            if (error) setError('')
          }}
          // Enter adds rather than submitting the surrounding form, which would
          // otherwise save the whole dialog on the way to adding one item.
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault()
              add()
            }
          }}
          placeholder="e.g. Machine Operation"
          className={cn('h-8 text-xs', error && 'border-destructive')}
          aria-invalid={!!error}
          aria-label={label ?? 'Add a requirement'}
        />
        <Button type="button" variant="outline" size="sm" className="h-8 shrink-0" onClick={add}>
          <Plus className="h-3.5 w-3.5" />
          Add
        </Button>
      </div>

      {error ? (
        <p className="text-xs text-destructive" role="alert">
          {error}
        </p>
      ) : (
        <p className="text-xs text-muted-foreground">
          {hint ?? 'An applicant satisfies this by having any one of them.'}
        </p>
      )}
    </div>
  )
}
