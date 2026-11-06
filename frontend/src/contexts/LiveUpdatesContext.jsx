import * as React from 'react'
import { get } from '@/lib/api'

/**
 * Keeps open screens up to date without a browser refresh.
 *
 * Asks the server every few seconds whether anything has changed - one tiny
 * request answered from two indexed lookups - and, only when it has, bumps a
 * counter that every useApi hook listens to. Each screen then refetches what it
 * is showing in the background, so the content stays where the reader left it
 * and simply changes underneath them.
 *
 * Polling rather than a socket for the reason the notification badge gives:
 * nothing here needs to arrive within the second, and a socket server is one
 * more process to keep running on XAMPP and on the host. Paused while the tab
 * is hidden, and checked at once when it comes back.
 *
 * Two counters. `data` moves when a record changed; `activity` also moves on
 * sign-ins, sign-outs and failed sign-ins, which change no record. Only the
 * screens that show sign-ins listen to `activity` (useApi's `live:
 * 'activity'`): when every screen refreshed on every sign-in anywhere, lists
 * shifted under the reader and open forms were reset for nothing.
 */

const LiveUpdatesContext = React.createContext({ data: 0, activity: 0 })

const POLL_MS = 10_000

export function LiveUpdatesProvider({ children, enabled = true }) {
  const [ticks, setTicks] = React.useState({ data: 0, activity: 0 })
  const markers = React.useRef(null)

  const check = React.useCallback(async () => {
    if (document.visibilityState === 'hidden') return

    try {
      // Background: a screen left open must not keep a sign-in alive.
      const response = await get('/live', null, { background: true, retries: 0 })
      const version = response.data?.version
      if (!version) return
      // An older server sends only `version`, which then covers both.
      const activity = response.data?.activity ?? version

      // The first answer is only a baseline; the screen has just loaded.
      const previous = markers.current
      if (previous !== null && (version !== previous.version || activity !== previous.activity)) {
        setTicks((t) => ({
          data: version !== previous.version ? t.data + 1 : t.data,
          activity: activity !== previous.activity ? t.activity + 1 : t.activity,
        }))
      }
      markers.current = { version, activity }
    } catch {
      // A missed check is caught up by the next one.
    }
  }, [])

  React.useEffect(() => {
    markers.current = null
    if (!enabled) return

    check()
    const timer = setInterval(check, POLL_MS)

    function onVisible() {
      if (document.visibilityState === 'visible') check()
    }
    document.addEventListener('visibilitychange', onVisible)

    return () => {
      clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [enabled, check])

  return <LiveUpdatesContext.Provider value={ticks}>{children}</LiveUpdatesContext.Provider>
}

/**
 * A number that goes up each time the server reports a change: to a record
 * (`'data'`, the default), or to anything including sign-ins (`'activity'`).
 */
export function useLiveTick(kind = 'data') {
  return React.useContext(LiveUpdatesContext)[kind] ?? 0
}
