import * as React from 'react'
import { useLocation } from 'react-router-dom'
import { get, post } from '@/lib/api'

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
  countsFor: () => new Map(),
  markSeen: () => {},
  ready: false,
})

/** Whether a sidebar link covers a notification's page: itself, or a page beneath it. */
function covers(to, path) {
  return path === to || path.startsWith(`${to}/`)
}

/**
 * The sidebar item a group of notifications belongs to.
 *
 * The page the notification opens decides it - the most specific link that
 * covers that page - so every page with news shows a number, including ones
 * added later, with nothing to keep in step. A notification whose page is not
 * in this person's sidebar falls back to the item that lists its category.
 */
function itemFor(items, entry) {
  if (entry.path) {
    const covering = items.filter((item) => covers(item.to, entry.path))
    if (covering.length) return covering.reduce((best, item) => (item.to.length > best.to.length ? item : best))
  }

  return items.find((item) => item.categories?.includes(entry.category)) ?? null
}

/**
 * The sidebar item for the page being viewed: the most specific link that
 * covers it. A link marked `end` (a home page such as the dashboard or "My
 * application") counts only for itself, so opening Settings beneath the
 * portal does not count as having looked at "My application".
 */
function currentItem(items, pathname) {
  const matching = items.filter((item) => (item.end ? pathname === item.to : covers(item.to, pathname)))
  return matching.length ? matching.reduce((best, item) => (item.to.length > best.to.length ? item : best)) : null
}

const POLL_MS = 60_000

export function NotificationsProvider({ children, enabled = true }) {
  const [unread, setUnread] = React.useState(0)
  const [byCategory, setByCategory] = React.useState({})
  // null until a server that reports destinations answers.
  const [byDestination, setByDestination] = React.useState(null)

  const refresh = React.useCallback(async () => {
    if (!enabled) return

    try {
      // Marked as background: the count is fetched every minute whether or
      // not anybody is at the desk, so it must not keep a sign-in alive.
      const response = await get('/notifications/unread-count', null, { background: true })
      setUnread(response.data.unread_count ?? 0)
      setByCategory(response.data.by_category ?? {})
      setByDestination(response.data.by_destination ?? null)
    } catch {
      // A failing badge must never interrupt whatever the user is doing, and a
      // stale number is better than an error where a number should be.
    }
  }, [enabled])

  React.useEffect(() => {
    if (!enabled) {
      setUnread(0)
      setByCategory({})
      setByDestination(null)
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

  /**
   * The unread count for every item of a sidebar at once, keyed by its link.
   *
   * Each notification is counted on exactly one item - the one for the page
   * it opens (see itemFor) - so the numbers in the sidebar add up to no more
   * than the bell. Pass only the items this person can see. Falls back to the
   * categories when the server does not report destinations.
   */
  const countsFor = React.useCallback(
    (items) => {
      const counts = new Map(items.map((item) => [item.to, 0]))

      if (!byDestination) {
        items.forEach((item) => counts.set(item.to, countFor(item.categories)))
        return counts
      }

      for (const entry of byDestination) {
        const item = itemFor(items, entry)
        if (item) counts.set(item.to, counts.get(item.to) + entry.count)
      }

      return counts
    },
    [byDestination, countFor]
  )

  /**
   * Marks read what was counted on one sidebar item, because its page has
   * been opened and the person has now seen it.
   *
   * Exactly the destinations countsFor gave that item are sent, so the number
   * that disappears is the number that was shown - nothing counted elsewhere is
   * touched. The badge drops at once; the next refresh confirms it.
   */
  const markSeen = React.useCallback(
    async (items, item) => {
      if (!byDestination) return

      const seen = byDestination.filter((entry) => itemFor(items, entry)?.to === item.to)
      if (!seen.length) return

      const total = seen.reduce((sum, entry) => sum + entry.count, 0)
      setByDestination((current) => current?.filter((entry) => !seen.includes(entry)) ?? current)
      setUnread((count) => Math.max(0, count - total))
      setByCategory((current) => {
        const next = { ...current }
        seen.forEach((entry) => {
          next[entry.category] = Math.max(0, (next[entry.category] ?? 0) - entry.count)
        })
        return next
      })

      try {
        await post('/notifications/read-destinations', {
          destinations: seen.map(({ path, category }) => ({ path, category })),
        })
      } catch {
        // The badge comes back on the next refresh; nothing else depends on it.
      }

      refresh()
    },
    [byDestination, refresh]
  )

  const value = React.useMemo(
    () => ({ unread, byCategory, refresh, countFor, countsFor, markSeen, ready: byDestination !== null }),
    [unread, byCategory, refresh, countFor, countsFor, markSeen, byDestination]
  )

  return <NotificationsContext.Provider value={value}>{children}</NotificationsContext.Provider>
}

export function useNotifications() {
  return React.useContext(NotificationsContext)
}

/**
 * Clears a sidebar item's number when its page is opened.
 *
 * Runs once per page visited - on arriving at it, or as soon as the counts
 * first load if the person landed there directly. Something new that arrives
 * while they stay on the page keeps its number until they next open it: the
 * page they are looking at may not show it yet, so it has not been seen.
 *
 * `items` is the sidebar as this person sees it.
 */
export function useMarkSeenOnVisit(items) {
  const { pathname } = useLocation()
  const { ready, markSeen } = useNotifications()
  const handled = React.useRef(null)

  React.useEffect(() => {
    if (!ready || handled.current === pathname) return
    handled.current = pathname

    const item = currentItem(items, pathname)
    if (item) markSeen(items, item)
  }, [ready, pathname, items, markSeen])
}
