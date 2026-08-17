import * as React from 'react'
import { useNavigate } from 'react-router-dom'
import { Bell, Check, FileText, UserCheck, ClipboardCheck, Info } from 'lucide-react'
import { get, post } from '@/lib/api'
import { cn, formatDateTime } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { useToast } from '@/components/ui/toast'

const CATEGORY_ICONS = {
  requirements: FileText,
  application: ClipboardCheck,
  deployment: UserCheck,
  general: Info,
}

const SEVERITY_STYLES = {
  success: 'text-success',
  warning: 'text-warning',
  info: 'text-primary',
}

/**
 * Notification bell with an unread badge.
 *
 * Polls rather than holding a socket open. The events here are things like a
 * document being verified — meaningful within minutes, not seconds — so a
 * lightweight poll avoids running a WebSocket server for no practical gain.
 */
export default function NotificationBell() {
  const [open, setOpen] = React.useState(false)
  const [items, setItems] = React.useState([])
  const [unread, setUnread] = React.useState(0)
  const [loading, setLoading] = React.useState(false)
  const containerRef = React.useRef(null)
  const navigate = useNavigate()
  const toast = useToast()

  const loadCount = React.useCallback(async () => {
    try {
      const response = await get('/notifications/unread-count')
      setUnread(response.data.unread_count)
    } catch {
      // A failing badge must not interrupt whatever the user is doing.
    }
  }, [])

  React.useEffect(() => {
    loadCount()
    const timer = setInterval(loadCount, 60_000)
    return () => clearInterval(timer)
  }, [loadCount])

  // Close on outside click and on Escape, so the panel never traps the user.
  React.useEffect(() => {
    if (!open) return

    function onPointerDown(event) {
      if (containerRef.current && !containerRef.current.contains(event.target)) {
        setOpen(false)
      }
    }

    function onKeyDown(event) {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  async function togglePanel() {
    const next = !open
    setOpen(next)

    if (next) {
      setLoading(true)
      try {
        const response = await get('/notifications', { per_page: 12 })
        setItems(response.data.notifications)
        setUnread(response.data.unread_count)
      } catch {
        setItems([])
      } finally {
        setLoading(false)
      }
    }
  }

  async function handleOpenItem(item) {
    if (!item.read_at) {
      // Updated locally first so the panel responds immediately; the badge is
      // reconciled against the server on the next poll regardless.
      setItems((current) => current.map((n) => (n.id === item.id ? { ...n, read_at: new Date().toISOString() } : n)))
      setUnread((count) => Math.max(0, count - 1))
      post(`/notifications/${item.id}/read`).catch(() => loadCount())
    }

    if (item.link) {
      setOpen(false)
      navigate(item.link)
    }
  }

  async function markAllRead() {
    // Nothing to do, and a toast saying "0 marked as read" is noise.
    if (unread === 0) return

    const cleared = unread

    // Applied optimistically: the badge should clear the moment it is clicked.
    setItems((current) => current.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })))
    setUnread(0)

    try {
      await post('/notifications/read-all')
      toast.success(
        'Notifications cleared',
        `${cleared} notification${cleared === 1 ? '' : 's'} marked as read.`
      )
    } catch (error) {
      // The optimistic update has to be undone visibly, or the user is left
      // believing a badge cleared when the server never agreed.
      loadCount()
      toast.error('Could not clear notifications', error.message)
    }
  }

  return (
    <div className="relative" ref={containerRef}>
      <Button
        variant="ghost"
        size="icon"
        onClick={togglePanel}
        aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
        aria-expanded={open}
        className="relative"
      >
        <Bell className="h-4 w-4" />
        {unread > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold tabular-nums text-destructive-foreground">
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </Button>

      {open && (
        <div
          className="absolute right-0 z-50 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border bg-card shadow-lg animate-fade-in"
          role="dialog"
          aria-label="Notifications"
        >
          <div className="flex items-center justify-between border-b px-4 py-2.5">
            <p className="text-sm font-semibold">Notifications</p>
            {unread > 0 && (
              <button onClick={markAllRead} className="text-xs text-primary hover:underline">
                Mark all read
              </button>
            )}
          </div>

          <div className="max-h-96 overflow-y-auto">
            {loading ? (
              <p className="px-4 py-8 text-center text-sm text-muted-foreground">Loading…</p>
            ) : items.length === 0 ? (
              <div className="px-4 py-10 text-center">
                <Check className="mx-auto mb-2 h-5 w-5 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Nothing to catch up on</p>
              </div>
            ) : (
              <ul className="divide-y">
                {items.map((item) => {
                  const Icon = CATEGORY_ICONS[item.category] ?? Info
                  return (
                    <li key={item.id}>
                      <button
                        onClick={() => handleOpenItem(item)}
                        className={cn(
                          'flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-accent/50',
                          !item.read_at && 'bg-primary/[0.04]'
                        )}
                      >
                        <Icon className={cn('mt-0.5 h-4 w-4 shrink-0', SEVERITY_STYLES[item.severity])} />
                        <div className="min-w-0 flex-1">
                          <p className={cn('text-sm leading-snug', !item.read_at && 'font-medium')}>{item.title}</p>
                          <p className="mt-0.5 text-xs text-muted-foreground">{item.body}</p>
                          <p className="mt-1 text-[11px] text-muted-foreground">{formatDateTime(item.created_at)}</p>
                        </div>
                        {!item.read_at && <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" />}
                      </button>
                    </li>
                  )
                })}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
