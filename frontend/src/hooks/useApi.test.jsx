import { describe, expect, it, vi } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'

const api = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/lib/api', () => api)

const { useApi } = await import('./useApi')

function deferred() {
  let resolve
  const promise = new Promise((r) => {
    resolve = r
  })
  return { promise, resolve }
}

describe('useApi', () => {
  it('never lets an older refresh overwrite the rows of a newer filter', async () => {
    const slowRefresh = deferred()
    api.get
      .mockImplementationOnce(() => Promise.resolve({ data: ['first load, status A'] }))
      .mockImplementationOnce(() => slowRefresh.promise)
      .mockImplementationOnce(() => Promise.resolve({ data: ['status B'] }))

    const { result, rerender } = renderHook(({ status }) => useApi('/applicants', { status }), {
      initialProps: { status: 'A' },
    })
    await waitFor(() => expect(result.current.data).toEqual(['first load, status A']))

    // A refresh for A starts (after saving something, say) and is slow...
    act(() => {
      result.current.refetch()
    })
    // ...the filter moves to B, whose answer arrives first...
    rerender({ status: 'B' })
    await waitFor(() => expect(result.current.data).toEqual(['status B']))

    // ...and A's late answer is dropped.
    await act(async () => {
      slowRefresh.resolve({ data: ['late answer for A'] })
      await slowRefresh.promise
    })
    expect(result.current.data).toEqual(['status B'])
    expect(result.current.loading).toBe(false)
  })
})
