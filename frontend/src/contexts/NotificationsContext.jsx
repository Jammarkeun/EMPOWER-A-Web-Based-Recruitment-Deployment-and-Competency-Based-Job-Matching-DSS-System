import * as React from 'react'
import { get } from '@/lib/api'

/**
 * Unread notification counts, shared by everything that displays one.
 *
 * The bell used to poll for its own badge, which was fine while it was the only
 * thing showing a number. Now the sidebar shows a count per section as well,
 * and giving each of those its own timer would mean four requests a minute
 * asking the same question — and four answers that could disagree with each
 * other for up to a minute at a time.
 *
 * So the count is fetched once here and read from everywhere. The categories
 * come back as a breakdown of the same total, so the sidebar and the bell
 * cannot contradict one another by construction.
 *
 * Polling rather than a socket, which is the choice the bell already made and
 * this keeps: a document being verified is meaningful within minutes, not
 * seconds, and a WebSocket server earns nothing against that. `refresh()` is
 * what makes it feel immediate anyway — anything that reads or resolves a
 * notification calls it, so the number moves on the action rather than waiting
 * for the next tick.
 */

const NotificationsContext = React.createContext({
  unread: 0,
  byCategory: {},
  refresh: () => {},
  countFor: () => 0,
})

const POLL_MS = 60_000

export function NotificationsProvider({ children, enabled = true }) {
  const [unread, setUnread] = React.useState(0)
  const [byCategory, setByCategory] = React.useState({})

  const refresh = React.useCallback(async () => {
    if (!enabled) return

    try {
      const response = await get('/notifications/unread-count')
      setUnread(response.data.unread_count ?? 0)
      setByCategory(response.data.by_category ?? {})
    } catch {
      // A failing badge must never interrupt whatever the user is doing, and a
      // stale number is better than an error where a number should be.
    }
  }, [enabled])

  React.useEffect(() => {
    if (!enabled) {
      setUnread(0)
      setByCategory({})
      return
    }

    refresh()
    const timer = setInterval(refresh, POLL_MS)

    return () => clearInterval(timer)
  }, [enabled, refresh])

  /*
   * Refreshes when the tab is brought back to the foreground.
   *
   * Someone who leaves the page open on a second monitor and returns twenty
   * minutes later should not be looking at a number from twenty minutes ago,
   * and this costs one request at the moment they actually start reading.
   */
  React.useEffect(() => {
    if (!enabled) return

    function onVisible() {
      if (document.visibilityState === 'visible') refresh()
    }

    document.addEventListener('visibilitychange', onVisible)
    return () => document.removeEventListener('visibilitychange', onVisible)
  }, [enabled, refresh])

  /**
   * The count for one section, summing the categories it covers.
   *
   * A section is not a category: "My application" covers both status changes
   * and deployment news, because to the person reading it those are the same
   * subject. Passing the categories at the call site keeps that mapping beside
   * the navigation it describes rather than buried here.
   */
  const countFor = React.useCallback(
    (categories) =>
      (categories ?? []).reduce((total, category) => total + (byCategory[category] ?? 0), 0),
    [byCategory]
  )

  const value = React.useMemo(
    () => ({ unread, byCategory, refresh, countFor }),
    [unread, byCategory, refresh, countFor]
  )

  return <NotificationsContext.Provider value={value}>{children}</NotificationsContext.Provider>
}

export function useNotifications() {
  return React.useContext(NotificationsContext)
}
