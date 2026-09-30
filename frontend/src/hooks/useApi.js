import * as React from 'react'
import { get } from '@/lib/api'

const responseCache = new Map()
const DEFAULT_STALE_TIME = 60_000

/**
 * Fetches from the API with loading, error, and refetch handling.
 *
 * Deliberately small rather than pulling in a data-fetching library: the screens
 * here are straightforward reads and mutations, and one hook keeps the data flow
 * legible for anyone reading the project for the first time.
 *
 * ## Why loading and refreshing are two different things
 *
 * This hook used to report one `loading` flag for both the first fetch and every
 * refetch afterwards. Screens quite reasonably wrote:
 *
 *     if (loading) return <LoadingState />
 *
 * which meant that refreshing after an action replaced the entire page with a
 * spinner. React unmounted the real content, the document lost its height, the
 * browser pinned the scroll position to the top, and when the data came back the
 * applicant was reading the top of the page instead of the document section they
 * had been working in. On a checklist of eighteen requirements that is a long
 * scroll back, every single time.
 *
 * The fix is at the source rather than in each screen: `loading` now means "we
 * have nothing to show yet", and stays false once there is data. A refetch
 * reports `refreshing` instead, which a screen can show as a quiet indicator
 * beside the content it is refreshing while the content itself stays on screen -
 * and therefore stays where the reader left it.
 */
export function useApi(url, params, options = {}) {
  const { enabled = true, cacheKey, staleTime = DEFAULT_STALE_TIME, retry = 2 } = options

  const [data, setData] = React.useState(null)
  const [meta, setMeta] = React.useState(null)
  const [loading, setLoading] = React.useState(enabled)
  const [refreshing, setRefreshing] = React.useState(false)
  const [error, setError] = React.useState(null)
  const [updatedAt, setUpdatedAt] = React.useState(null)

  // Serialised so a new object literal on each render does not retrigger the
  // effect endlessly.
  const paramsKey = JSON.stringify(params ?? {})
  const cacheId = cacheKey ? `${cacheKey}:${paramsKey}` : null

  // Whether anything has ever arrived. Held in a ref rather than derived from
  // `data` so the fetch function does not change identity when data does, which
  // would restart any effect depending on refetch.
  const hasData = React.useRef(false)

  const load = React.useCallback(
    async ({ background, signal, isCancelled = () => false } = {}) => {
      if (!enabled) {
        setLoading(false)
        return
      }

      if (background) {
        setRefreshing(true)
      } else {
        setLoading(true)
      }
      setError(null)

      try {
        const response = await get(url, JSON.parse(paramsKey), { signal, retries: retry })

        // Guards against a slow response from a previous filter overwriting the
        // results of a newer one after the user has typed again.
        if (isCancelled()) return

        setData(response.data)
        setMeta(response.meta ?? null)
        setUpdatedAt(Date.now())
        hasData.current = true
        if (cacheId) responseCache.set(cacheId, { data: response.data, meta: response.meta ?? null, updatedAt: Date.now() })
      } catch (err) {
        if (!isCancelled()) setError(err)
      } finally {
        if (!isCancelled()) {
          setLoading(false)
          setRefreshing(false)
        }
      }
    },
    [url, paramsKey, enabled, cacheId, retry]
  )

  /**
   * Fetch again without taking the page away.
   *
   * Treated as a background refresh once there is something on screen, so the
   * reader keeps both their content and their scroll position. The very first
   * call still counts as a real load, since there is nothing to preserve.
   */
  const refetch = React.useCallback(
    () => load({ background: hasData.current }),
    [load]
  )

  React.useEffect(() => {
    const controller = new AbortController()
    let cancelled = false

    if (cacheId) {
      const cached = responseCache.get(cacheId)
      if (cached && Date.now() - cached.updatedAt < staleTime) {
        setData(cached.data)
        setMeta(cached.meta)
        setUpdatedAt(cached.updatedAt)
        hasData.current = true
        setLoading(false)
        return () => controller.abort()
      }
    }

    load({ signal: controller.signal, background: false, isCancelled: () => cancelled })

    return () => {
      cancelled = true
      controller.abort()
    }
  }, [load, cacheId, staleTime])

  return { data, meta, loading, refreshing, error, updatedAt, refetch, setData }
}

/** Debounces a rapidly changing value, used for search-as-you-type filters. */
export function useDebounced(value, delay = 350) {
  const [debounced, setDebounced] = React.useState(value)

  React.useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])

  return debounced
}
