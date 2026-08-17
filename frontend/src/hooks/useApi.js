import * as React from 'react'
import { get } from '@/lib/api'

/**
 * Fetches from the API with loading, error, and refetch handling.
 *
 * Deliberately small rather than pulling in a data-fetching library: the screens
 * here are straightforward reads and mutations, and one hook keeps the data flow
 * legible for anyone reading the project for the first time.
 */
export function useApi(url, params, options = {}) {
  const { enabled = true } = options

  const [data, setData] = React.useState(null)
  const [meta, setMeta] = React.useState(null)
  const [loading, setLoading] = React.useState(enabled)
  const [error, setError] = React.useState(null)

  // Serialised so a new object literal on each render does not retrigger the
  // effect endlessly.
  const paramsKey = JSON.stringify(params ?? {})

  const fetchData = React.useCallback(async () => {
    if (!enabled) return

    setLoading(true)
    setError(null)

    try {
      const response = await get(url, JSON.parse(paramsKey))
      setData(response.data)
      setMeta(response.meta ?? null)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [url, paramsKey, enabled])

  React.useEffect(() => {
    let cancelled = false

    async function run() {
      if (!enabled) {
        setLoading(false)
        return
      }

      setLoading(true)
      setError(null)

      try {
        const response = await get(url, JSON.parse(paramsKey))
        // Guards against a slow response from a previous filter overwriting the
        // results of a newer one after the user has typed again.
        if (!cancelled) {
          setData(response.data)
          setMeta(response.meta ?? null)
        }
      } catch (err) {
        if (!cancelled) setError(err)
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    run()
    return () => {
      cancelled = true
    }
  }, [url, paramsKey, enabled])

  return { data, meta, loading, error, refetch: fetchData, setData }
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
