import * as React from 'react'

const serialise = (value) => JSON.stringify(value ?? null)

/**
 * Form state seeded from a record, which a background refresh of that record
 * never overwrites while the form holds unsaved edits.
 *
 * ## Why this exists
 *
 * Open screens refetch in the background whenever the server reports a change
 * (LiveUpdatesContext), and every refetch hands a component a new copy of the
 * record. Forms used to re-seed in an effect keyed on that record object:
 *
 *     React.useEffect(() => { if (open) setForm(fromRecord(record)) }, [open, record])
 *
 * so somebody signing in elsewhere was enough to wipe a half-typed resignation
 * update or session correction. This hook separates the three moments that
 * effect ran together:
 *
 * - **Opening** (`open` turns true) or **switching record** (`key` changes):
 *   always start again from the record. This is the explicit reset.
 * - **A refresh while the form is untouched**: follow the record silently, so
 *   the form shows what is current.
 * - **A refresh while the form has edits**: keep the edits. `outdated` says the
 *   record moved underneath them, so a screen can tell the user.
 *
 * Seeding happens during render rather than in an effect, so a dialog never
 * paints one frame of the previous record's values when it opens.
 *
 * `toForm` must be a pure function of `source`: the hook compares its output
 * to detect a change, so a value that differs on every call (a random key, the
 * current time) would look like a refresh on every render.
 *
 * `baseline` is what the form was seeded with. Compare against it, not against
 * the latest record, to find what the user changed: a field somebody else
 * updated in the meantime is then not mistaken for an edit and sent back.
 */
export function useFormDraft(source, toForm, { open = true, key = null } = {}) {
  const [draft, setDraft] = React.useState(() => {
    const form = toForm(source)
    return { form, baseline: form, seen: serialise(form), open, key }
  })

  if (open) {
    const next = toForm(source)
    const seen = serialise(next)

    if (!draft.open || draft.key !== key) {
      setDraft({ form: next, baseline: next, seen, open, key })
    } else if (seen !== draft.seen) {
      const formNow = serialise(draft.form)
      const untouched = formNow === serialise(draft.baseline)
      // A save the server has just echoed back: the edits are now the record.
      const caughtUp = formNow === seen
      setDraft(untouched || caughtUp ? { form: next, baseline: next, seen, open, key } : { ...draft, seen })
    }
  } else if (draft.open) {
    setDraft({ ...draft, open: false })
  }

  const setForm = React.useCallback((update) => {
    setDraft((current) => ({
      ...current,
      form: typeof update === 'function' ? update(current.form) : update,
    }))
  }, [])

  /** After a successful save: the edits are what the record now holds. */
  const markSaved = React.useCallback(() => {
    setDraft((current) => ({ ...current, baseline: current.form }))
  }, [])

  const dirty = open && serialise(draft.form) !== serialise(draft.baseline)

  return {
    form: draft.form,
    setForm,
    baseline: draft.baseline,
    dirty,
    outdated: dirty && draft.seen !== serialise(draft.baseline),
    markSaved,
  }
}
