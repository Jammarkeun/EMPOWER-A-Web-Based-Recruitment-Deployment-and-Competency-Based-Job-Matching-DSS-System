/**
 * The browser's side of the sign-in limits (security audit V-02).
 *
 * The server decides when a sign-in ends - an idle limit since the account last
 * made a request of its own, and a lifetime from signing in. This file only
 * keeps the screen in step with that, so a person is warned before it happens
 * and told afterwards why they are back at the sign-in page.
 *
 * The time of the last request is kept in localStorage rather than in memory,
 * so two open tabs agree: working in one keeps the other from announcing an
 * idle sign-out. Every storage call is wrapped, because a private window or
 * blocked site data makes localStorage throw.
 */

/** Sent by requests the screen makes on its own; the server does not count them as activity. */
export const BACKGROUND_HEADER = 'X-Empower-Background'

/** How long before an idle sign-out the warning appears. */
export const IDLE_WARNING_MS = 2 * 60_000

/** How long before the end of a sign-in's lifetime the person is told to save their work. */
export const LIFETIME_WARNING_MS = 5 * 60_000

const ACTIVITY_KEY = 'empower.lastActivity'
const NOTICE_KEY = 'empower.signedOutNotice'

export const sessionActivity = {
  /** Records that the server has just seen a request of the person's own. */
  touch(now = Date.now()) {
    try {
      localStorage.setItem(ACTIVITY_KEY, String(now))
    } catch {
      // Without storage each tab keeps its own clock; the server still decides.
    }
  },
  last() {
    try {
      const value = Number(localStorage.getItem(ACTIVITY_KEY))
      return Number.isFinite(value) && value > 0 ? value : null
    } catch {
      return null
    }
  },
}

/**
 * The sentence shown on the sign-in page after a sign-out the person did not
 * ask for. Kept in sessionStorage so it survives the redirect but not the tab.
 */
export const signOutNotice = {
  set(message) {
    try {
      sessionStorage.setItem(NOTICE_KEY, message)
    } catch {
      // The sign-in page then shows no explanation, which is the old behaviour.
    }
  },
  peek() {
    try {
      return sessionStorage.getItem(NOTICE_KEY)
    } catch {
      return null
    }
  },
  clear() {
    try {
      sessionStorage.removeItem(NOTICE_KEY)
    } catch {
      // Nothing to clear.
    }
  },
}

/** The 401 codes that mean a sign-in ran out, rather than never being valid. */
export const SESSION_END_CODES = new Set(['session_idle', 'session_expired'])

export function idleMessage(minutes) {
  return `You were signed out after ${minutes} minutes without activity. Please sign in again.`
}

export const LIFETIME_MESSAGE = 'Your session has ended. Please sign in again.'

/**
 * Where a sign-in stands, from the limits the server reported and the time of
 * the last request it counted.
 *
 *   active        nothing to say
 *   idle-warning  an idle sign-out is less than IDLE_WARNING_MS away
 *   ending        the lifetime ends within LIFETIME_WARNING_MS
 *   ended         one of the limits has passed; `reason` says which
 *
 * Whichever limit comes first wins, so a lifetime ending in one minute is not
 * hidden behind an idle warning for three.
 */
export function sessionState({ idleMinutes, expiresAt, lastActivity, now }) {
  const idleAt = idleMinutes && lastActivity ? lastActivity + idleMinutes * 60_000 : null
  const parsedEnd = expiresAt ? Date.parse(expiresAt) : NaN
  const endAt = Number.isFinite(parsedEnd) ? parsedEnd : null

  if (endAt !== null && now >= endAt) return { phase: 'ended', reason: 'session_expired' }
  if (idleAt !== null && now >= idleAt) return { phase: 'ended', reason: 'session_idle' }

  const idleFirst = idleAt !== null && (endAt === null || idleAt <= endAt)

  if (idleFirst && idleAt - now <= IDLE_WARNING_MS) return { phase: 'idle-warning', at: idleAt }
  if (endAt !== null && endAt - now <= LIFETIME_WARNING_MS) return { phase: 'ending', at: endAt }

  return { phase: 'active' }
}
