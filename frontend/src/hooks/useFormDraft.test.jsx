import { describe, expect, it } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { useFormDraft } from './useFormDraft'

const toForm = (record) => ({ title: record?.title ?? '', location: record?.location ?? '' })

function draftOf(initialProps) {
  return renderHook(({ record, open, key }) => useFormDraft(record, toForm, { open, key }), { initialProps })
}

describe('useFormDraft', () => {
  it('starts from the record and is not dirty', () => {
    const { result } = draftOf({ record: { title: 'Orientation', location: 'Office' }, open: true, key: 1 })

    expect(result.current.form).toEqual({ title: 'Orientation', location: 'Office' })
    expect(result.current.dirty).toBe(false)
  })

  it('follows a background refresh while nothing has been typed', () => {
    const { result, rerender } = draftOf({ record: { title: 'Orientation', location: 'Office' }, open: true, key: 1 })

    rerender({ record: { title: 'Orientation', location: 'Plant 2' }, open: true, key: 1 })

    expect(result.current.form.location).toBe('Plant 2')
    expect(result.current.dirty).toBe(false)
  })

  it('keeps unsaved edits through a background refresh, and says the record moved', () => {
    const { result, rerender } = draftOf({ record: { title: 'Orientation', location: 'Office' }, open: true, key: 1 })

    act(() => result.current.setForm((f) => ({ ...f, title: 'Orientation (revised)' })))
    // The same record again, as a new object, the way every refetch delivers it.
    rerender({ record: { title: 'Orientation', location: 'Office' }, open: true, key: 1 })
    expect(result.current.form.title).toBe('Orientation (revised)')
    expect(result.current.outdated).toBe(false)

    // Now somebody else really changes it.
    rerender({ record: { title: 'Orientation', location: 'Plant 2' }, open: true, key: 1 })
    expect(result.current.form).toEqual({ title: 'Orientation (revised)', location: 'Office' })
    expect(result.current.dirty).toBe(true)
    expect(result.current.outdated).toBe(true)
    // What the edits started from is still known, for sending only real changes.
    expect(result.current.baseline).toEqual({ title: 'Orientation', location: 'Office' })
  })

  it('starts again on reopening and on switching record', () => {
    const { result, rerender } = draftOf({ record: { title: 'A', location: '' }, open: true, key: 1 })
    act(() => result.current.setForm((f) => ({ ...f, title: 'typed' })))

    rerender({ record: { title: 'A', location: '' }, open: false, key: 1 })
    expect(result.current.dirty).toBe(false)

    rerender({ record: { title: 'A', location: '' }, open: true, key: 1 })
    expect(result.current.form.title).toBe('A')

    act(() => result.current.setForm((f) => ({ ...f, title: 'typed again' })))
    rerender({ record: { title: 'B', location: '' }, open: true, key: 2 })
    expect(result.current.form.title).toBe('B')
    expect(result.current.dirty).toBe(false)
  })

  it('follows the record again once a save has gone through', () => {
    const { result, rerender } = draftOf({ record: { title: 'A', location: '' }, open: true, key: 1 })
    act(() => result.current.setForm((f) => ({ ...f, title: 'A2' })))

    act(() => result.current.markSaved())
    expect(result.current.dirty).toBe(false)

    // The server tidied the value; with no edits pending, the form takes it.
    rerender({ record: { title: 'A2 (final)', location: '' }, open: true, key: 1 })
    expect(result.current.form.title).toBe('A2 (final)')
  })

  it('treats the server echoing the edits back as caught up', () => {
    const { result, rerender } = draftOf({ record: { title: 'A', location: '' }, open: true, key: 1 })
    act(() => result.current.setForm((f) => ({ ...f, title: 'A2' })))

    rerender({ record: { title: 'A2', location: '' }, open: true, key: 1 })
    expect(result.current.dirty).toBe(false)
  })
})
