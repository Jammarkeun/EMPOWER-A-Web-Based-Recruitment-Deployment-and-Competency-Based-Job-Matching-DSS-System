import * as React from 'react'
import { createPortal } from 'react-dom'
import { CheckCircle2, AlertCircle, Info, AlertTriangle, X } from 'lucide-react'
import { cn } from '@/lib/utils'

const ToastContext = React.createContext(null)

/**
 * How long a toast stays before it dismisses itself.
 *
 * Three seconds is short. It is enough to confirm that something happened,
 * which is all most of these messages need to do, and it keeps the corner of
 * the screen clear while HR works through a queue of applicants at a counter.
 *
 * Two things stop that brevity losing information. Hovering or focusing a toast
 * pauses its countdown, so anything worth reading can be read in full. And the
 * message is never the only record of what happened: every action also updates
 * the screen behind it, and failures leave the form filled in with the error
 * shown inline.
 */
const DEFAULT_DURATION = 3000

/** Long enough for the exit animation to finish before the node is removed. */
const EXIT_MS = 180

/**
 * Beyond four the stack becomes a wall rather than a notification. Older
 * toasts are dropped first, since the newest is the one describing what the
 * user just did.
 */
const MAX_VISIBLE = 4

/**
 * Application-wide toast notifications.
 *
 * Every action in EMPOWER reports its outcome through here, successes as well
 * as failures. A save that produces no visible change is indistinguishable from
 * a save that silently failed, and in a records system that is the difference
 * between an applicant's document being on file and being lost.
 */
export function ToastProvider({ children }) {
  const [toasts, setToasts] = React.useState([])

  /** Removes immediately, without the exit animation. */
  const remove = React.useCallback((id) => {
    setToasts((current) => current.filter((t) => t.id !== id))
  }, [])

  /** Plays the toast out, then removes it. */
  const dismiss = React.useCallback(
    (id) => {
      setToasts((current) => current.map((t) => (t.id === id ? { ...t, leaving: true } : t)))
      setTimeout(() => remove(id), EXIT_MS)
    },
    [remove]
  )

  const push = React.useCallback((toast) => {
    const id = Math.random().toString(36).slice(2)

    setToasts((current) => {
      const next = [...current, { id, variant: 'info', duration: DEFAULT_DURATION, ...toast }]
      return next.length > MAX_VISIBLE ? next.slice(next.length - MAX_VISIBLE) : next
    })

    return id
  }, [])

  /*
   * There is deliberately no loading/promise variant.
   *
   * Every slow action in EMPOWER — the competency evaluation, document
   * recognition, report exports, uploads — already disables its own button and
   * shows a spinner in place. A toast saying the same thing would be a second
   * progress indicator for one action, in a different corner of the screen.
   * Toasts here report outcomes; the control the user pressed reports progress.
   */
  const value = React.useMemo(() => {
    const variant = (name) => (title, description, options) =>
      push({ variant: name, title, description, ...options })

    return {
      toast: push,
      success: variant('success'),
      error: variant('error'),
      warning: variant('warning'),
      info: variant('info'),
      dismiss,
    }
  }, [push, dismiss])

  return (
    <ToastContext.Provider value={value}>
      {children}
      {createPortal(
        <ToastViewport toasts={toasts} onDismiss={dismiss} />,
        document.body
      )}
    </ToastContext.Provider>
  )
}

/**
 * The stack itself.
 *
 * Bottom-right rather than top-centre: the top of every screen carries the
 * page heading and the primary action, and a toast that covers the button the
 * user is about to press is worse than no toast.
 *
 * Two live regions rather than one. Failures interrupt the screen reader
 * because the user needs to know before they carry on; confirmations wait
 * their turn.
 */
function ToastViewport({ toasts, onDismiss }) {
  const urgent = toasts.filter((t) => t.variant === 'error')
  const routine = toasts.filter((t) => t.variant !== 'error')

  return (
    <div className="pointer-events-none fixed inset-x-0 bottom-0 z-[100] flex flex-col items-end gap-2 p-4 sm:inset-x-auto sm:right-0 sm:max-w-sm">
      <div className="sr-only" role="alert" aria-live="assertive" aria-atomic="false">
        {urgent.map((t) => (
          <p key={t.id}>
            {t.title}. {t.description}
          </p>
        ))}
      </div>
      <div className="sr-only" role="status" aria-live="polite" aria-atomic="false">
        {routine.map((t) => (
          <p key={t.id}>
            {t.title}. {t.description}
          </p>
        ))}
      </div>

      {/* The visible toasts are hidden from assistive technology, since the
          live regions above already announce them. Without this every message
          is read twice. */}
      <div className="flex w-full flex-col gap-2" aria-hidden="true">
        {toasts.map((toast) => (
          <ToastItem
            key={toast.id}
            toast={toast}
            onDismiss={() => onDismiss(toast.id)}
          />
        ))}
      </div>
    </div>
  )
}

/*
 * Tone per variant.
 *
 * Opacity modifiers here must be multiples of five: that is Tailwind's scale,
 * and a value off it (/12, say) compiles to no rule at all, which shows up as
 * a silently transparent chip rather than a build error.
 *
 * Colour is never the only signal. Each variant also has its own icon and its
 * own wording, so the meaning survives for a colour-blind user and in the
 * black-and-white printouts HR takes to client meetings.
 */
const VARIANTS = {
  success: {
    icon: CheckCircle2,
    ring: 'border-l-success',
    chip: 'bg-success/15 text-success',
    bar: 'bg-success',
  },
  error: {
    icon: AlertCircle,
    ring: 'border-l-destructive',
    chip: 'bg-destructive/15 text-destructive',
    bar: 'bg-destructive',
  },
  warning: {
    icon: AlertTriangle,
    ring: 'border-l-warning',
    chip: 'bg-warning/15 text-warning',
    bar: 'bg-warning',
  },
  info: {
    icon: Info,
    ring: 'border-l-primary',
    chip: 'bg-primary/15 text-primary',
    bar: 'bg-primary',
  },
}

function ToastItem({ toast, onDismiss }) {
  const variant = VARIANTS[toast.variant] ?? VARIANTS.info
  const Icon = variant.icon

  const [paused, setPaused] = React.useState(false)

  /*
   * The countdown is kept in JavaScript rather than driven off the progress
   * bar's animationend event, because a user with "reduce motion" set has all
   * animations collapsed to 0.01ms — which would dismiss every toast the
   * instant it appeared.
   */
  const remaining = React.useRef(toast.duration)
  const startedAt = React.useRef(Date.now())
  const dismissRef = React.useRef(onDismiss)
  dismissRef.current = onDismiss

  React.useEffect(() => {
    if (toast.duration == null || paused || toast.leaving) return

    startedAt.current = Date.now()
    const timer = setTimeout(() => dismissRef.current(), remaining.current)

    return () => {
      clearTimeout(timer)
      // Bank the time already served, so pausing does not hand back the whole
      // duration each time the pointer passes over.
      remaining.current = Math.max(0, remaining.current - (Date.now() - startedAt.current))
    }
  }, [paused, toast.duration, toast.leaving])

  const hold = () => setPaused(true)
  const release = () => setPaused(false)

  return (
    <div
      className={cn(
        'pointer-events-auto relative w-full overflow-hidden rounded-lg border border-l-4 bg-card shadow-lg ring-1 ring-black/[0.03] dark:ring-white/[0.06]',
        variant.ring,
        toast.leaving ? 'animate-toast-out' : 'animate-toast-in'
      )}
      onMouseEnter={hold}
      onMouseLeave={release}
      onFocusCapture={hold}
      onBlurCapture={release}
    >
      <div className="flex items-start gap-3 p-3.5 pr-2">
        <span
          className={cn(
            'mt-px flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
            variant.chip
          )}
        >
          <Icon className="h-4 w-4" />
        </span>

        <div className="min-w-0 flex-1 space-y-0.5 pt-0.5">
          <p className="text-sm font-medium leading-snug text-foreground">{toast.title}</p>
          {toast.description && (
            <p className="text-xs leading-relaxed text-muted-foreground">{toast.description}</p>
          )}
        </div>

        <button
          type="button"
          onClick={onDismiss}
          className="-mr-0.5 rounded-md p-1.5 text-muted-foreground/70 transition-colors hover:bg-muted hover:text-foreground"
          aria-label="Dismiss notification"
        >
          <X className="h-3.5 w-3.5" />
        </button>
      </div>

      {toast.duration != null && !toast.leaving && (
        <div className="toast-progress-bar absolute inset-x-0 bottom-0 h-0.5 bg-border/40">
          <div
            className={cn('h-full origin-left', variant.bar)}
            style={{
              animation: `toast-progress ${toast.duration}ms linear forwards`,
              animationPlayState: paused ? 'paused' : 'running',
            }}
          />
        </div>
      )}
    </div>
  )
}

export function useToast() {
  const context = React.useContext(ToastContext)
  if (!context) throw new Error('useToast must be used within a ToastProvider')
  return context
}
