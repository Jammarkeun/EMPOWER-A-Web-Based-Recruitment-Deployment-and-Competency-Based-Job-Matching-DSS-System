import * as React from 'react'
import { Clock } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/contexts/AuthContext'
import { get } from '@/lib/api'
import {
  LIFETIME_MESSAGE,
  idleMessage,
  sessionActivity,
  sessionState,
  signOutNotice,
} from '@/lib/session'

/** How often the screen re-reads where the sign-in stands; once a second keeps the countdown honest. */
const TICK_MS = 1000

/**
 * Typing or clicking without any request reaching the server - a long form,
 * say - is still the person at work. Past this gap the screen tells the server
 * so with a light request, at most once per gap.
 */
const KEEPALIVE_GAP_MS = 60_000

const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel', 'touchstart']

function formatRemaining(ms) {
  const seconds = Math.max(0, Math.ceil(ms / 1000))
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

/**
 * Warns before a sign-in ends, and signs out cleanly when it has (audit V-02).
 *
 * The server is what ends a sign-in; this never extends one on its own
 * authority. It only does three things the server cannot:
 *
 *  - tells the server the person is still working when they are typing or
 *    clicking without any request going out;
 *  - warns two minutes before an idle sign-out, with a way to stay signed in,
 *    and five minutes before the sign-in's lifetime ends, so work can be saved;
 *  - once a limit has passed, signs out at once and leaves the reason for the
 *    sign-in page, instead of letting the next click fail.
 */
export default function SessionTimeout() {
  const { user, logout } = useAuth()
  const [limits, setLimits] = React.useState(user?.session ?? null)
  const [now, setNow] = React.useState(() => Date.now())
  const [acknowledgedEnd, setAcknowledgedEnd] = React.useState(null)

  const lastInteraction = React.useRef(0)
  const lastKeepalive = React.useRef(0)
  const signingOut = React.useRef(false)

  // The limits come with the profile. An account created by self-registration
  // is signed in without one, so it asks once.
  React.useEffect(() => {
    if (user?.session) {
      setLimits(user.session)
      return undefined
    }

    let cancelled = false
    get('/auth/session')
      .then((response) => !cancelled && setLimits(response.data))
      .catch(() => {})

    return () => {
      cancelled = true
    }
  }, [user?.session])

  React.useEffect(() => {
    const mark = () => {
      lastInteraction.current = Date.now()
    }
    ACTIVITY_EVENTS.forEach((name) => window.addEventListener(name, mark, { passive: true }))
    return () => ACTIVITY_EVENTS.forEach((name) => window.removeEventListener(name, mark))
  }, [])

  React.useEffect(() => {
    const tick = () => setNow(Date.now())
    const timer = setInterval(tick, TICK_MS)
    // A laptop woken from sleep should catch up at once, not on the next tick.
    const onVisible = () => document.visibilityState === 'visible' && tick()

    document.addEventListener('visibilitychange', onVisible)
    return () => {
      clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [])

  const lastActivity = sessionActivity.last()
  const state = limits
    ? sessionState({
        idleMinutes: limits.idle_timeout_minutes,
        expiresAt: limits.expires_at,
        lastActivity,
        now,
      })
    : { phase: 'active' }

  const keepAlive = React.useCallback(async () => {
    lastKeepalive.current = Date.now()
    try {
      const response = await get('/auth/session')
      sessionActivity.touch()
      setLimits(response.data)
      setNow(Date.now())
    } catch {
      // A refusal here is the sign-in having ended already; the API client
      // takes the person to the sign-in page with the reason.
    }
  }, [])

  // Working without requests: let the server know before its idle clock runs out.
  React.useEffect(() => {
    if (state.phase === 'ended') return

    const interactedSinceServerSaw = lastInteraction.current - (lastActivity ?? 0) > KEEPALIVE_GAP_MS
    const quietLongEnough = now - lastKeepalive.current > KEEPALIVE_GAP_MS

    if (interactedSinceServerSaw && quietLongEnough) keepAlive()
  }, [now, lastActivity, state.phase, keepAlive])

  React.useEffect(() => {
    if (state.phase !== 'ended' || signingOut.current) return

    signingOut.current = true
    signOutNotice.set(
      state.reason === 'session_idle' ? idleMessage(limits.idle_timeout_minutes) : LIFETIME_MESSAGE
    )
    logout()
  }, [state.phase, state.reason, limits, logout])

  const idleWarning = state.phase === 'idle-warning'
  const endingWarning = state.phase === 'ending' && acknowledgedEnd !== limits?.expires_at

  if (!idleWarning && !endingWarning) return null

  const remaining = formatRemaining(state.at - now)
  const endTime = new Date(state.at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (open) return
        if (idleWarning) keepAlive()
        else setAcknowledgedEnd(limits.expires_at)
      }}
    >
      <DialogContent className="max-w-md">
        <DialogHeader>
          <div className="flex items-start gap-3">
            <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-status-warning-bg text-status-warning-text">
              <Clock className="h-4 w-4" />
            </span>
            <div className="space-y-1">
              <DialogTitle>{idleWarning ? 'Are you still there?' : 'Your session ends soon'}</DialogTitle>
              <DialogDescription>
                {idleWarning ? (
                  <>
                    For security, you will be signed out in{' '}
                    <span className="font-semibold tabular-nums text-foreground" aria-live="polite">
                      {remaining}
                    </span>{' '}
                    because there has been no activity.
                  </>
                ) : (
                  <>
                    Your session ends at <span className="font-semibold text-foreground">{endTime}</span>. Save
                    your work now; after that you will need to sign in again.
                  </>
                )}
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <DialogFooter>
          <Button variant="outline" onClick={() => logout()}>
            {idleWarning ? 'Sign out' : 'Sign in again now'}
          </Button>
          {idleWarning ? (
            <Button onClick={keepAlive}>Stay signed in</Button>
          ) : (
            <Button onClick={() => setAcknowledgedEnd(limits.expires_at)}>OK</Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
