import * as React from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { LogOut, FileText, Home, Briefcase, UserRound, Menu, X, SlidersHorizontal } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import NotificationBell from '@/components/NotificationBell'
import ThemeToggle from '@/components/ThemeToggle'
import { LogoMark } from '@/components/Logo'
import { useNotifications } from '@/contexts/NotificationsContext'

/**
 * Shell for the applicant and employee portal.
 *
 * Deliberately a different layout from the staff system rather than the same one
 * with items hidden. An applicant checking their application should not be
 * looking at an interface built for managing hundreds of records, and the visual
 * difference makes it obvious at a glance which side of the system you are on.
 *
 * What is shared is the *chrome*, not the content: this sidebar is painted from
 * the same `--sidebar` tokens as the staff one, so the agency's navy, the
 * readable-on-navy text, and the selected-item treatment are defined once and
 * behave identically in both themes. That is the point of the tokens - a second
 * palette invented here would be a second thing to get wrong in dark mode, and
 * the sidebar is exactly where that has gone wrong before.
 *
 * The portal was previously a row of tabs, which worked while there were two
 * pages and stopped working at four. A sidebar also gives the applicant's own
 * identity somewhere to live, which a tab strip never did.
 */

const SIDEBAR_KEY = 'empower.portal.sidebar.collapsed'

/**
 * Whether the desktop sidebar is collapsed to an icon rail.
 *
 * Remembered across reloads because it is a working preference rather than a
 * transient view state. Wrapped in try/catch: localStorage throws outright in a
 * private window with site data blocked, and a sidebar preference is never worth
 * breaking the layout over.
 */
function useSidebarCollapsed() {
  const [collapsed, setCollapsed] = React.useState(() => {
    try {
      return localStorage.getItem(SIDEBAR_KEY) === '1'
    } catch {
      return false
    }
  })

  const toggle = React.useCallback(() => {
    setCollapsed((current) => {
      const next = !current
      try {
        localStorage.setItem(SIDEBAR_KEY, next ? '1' : '0')
      } catch {
        // The preference simply will not persist; the toggle still works.
      }
      return next
    })
  }, [])

  return [collapsed, toggle]
}

/** Both top bars share this so their bottom borders read as one line. */
const HEADER_HEIGHT = 'h-14'

/** Initials for the avatar, from whatever parts of the name we actually have. */
function initialsOf(name) {
  const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean)

  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 1).toUpperCase()

  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}

export default function PortalLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const [mobileOpen, setMobileOpen] = React.useState(false)
  const [collapsed, toggleCollapsed] = useSidebarCollapsed()

  React.useEffect(() => {
    if (!mobileOpen) return undefined
    function closeOnEscape(event) {
      if (event.key === 'Escape') setMobileOpen(false)
    }
    document.addEventListener('keydown', closeOnEscape)
    return () => document.removeEventListener('keydown', closeOnEscape)
  }, [mobileOpen])

  const isEmployee = user?.user_type === 'employee'

  /*
   * Each section carries its own unread count, not the global one.
   *
   * The categories are named here rather than in the notifications context
   * because this is where the mapping is a judgement about navigation: a
   * section is not a category. "My application" covers both status changes and
   * deployment news, because to somebody waiting to hear about their
   * application those are the same subject. Profile has none — nothing in the
   * system notifies about it, and a badge that could never appear is worse than
   * no badge at all.
   */
  const links = [
    { to: '/portal', label: 'My application', icon: Home, end: true, categories: ['application', 'deployment'] },
    { to: '/portal/documents', label: 'My documents', icon: FileText, categories: ['requirements'] },
    ...(isEmployee
      ? [{ to: '/portal/employment', label: 'My employment', icon: Briefcase, categories: [] }]
      : []),
    { to: '/portal/profile', label: 'Profile', icon: UserRound, categories: [] },
    { to: '/portal/settings', label: 'Settings', icon: SlidersHorizontal, categories: [] },
  ]

  async function handleLogout() {
    await logout()
    navigate('/login', { replace: true })
    toast.success('Signed out', 'Your session has ended.')
  }

  return (
    <div className="min-h-screen bg-background">
      {/* Mobile header. The sidebar becomes a drawer below this. */}
      <header className="sticky top-0 z-40 flex items-center justify-between border-b bg-card px-4 py-3 lg:hidden">
        <div className="flex items-center gap-2">
          <LogoMark size="xs" />
          <span className="font-semibold">EMPOWER</span>
        </div>
        <div className="flex items-center gap-1">
          <ThemeToggle />
          <NotificationBell />
          <Button
            variant="ghost"
            size="icon"
            onClick={() => setMobileOpen((open) => !open)}
            aria-label={mobileOpen ? 'Close navigation' : 'Open navigation'}
            aria-expanded={mobileOpen}
            aria-controls="portal-sidebar"
          >
            {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </Button>
        </div>
      </header>

      <div className="flex">
        {mobileOpen && (
          <button
            type="button"
            className="fixed inset-0 z-20 bg-foreground/30 lg:hidden"
            aria-label="Close navigation"
            onClick={() => setMobileOpen(false)}
          />
        )}
        <PortalSidebar
          links={links}
          user={user}
          collapsed={collapsed}
          onToggleCollapsed={toggleCollapsed}
          mobileOpen={mobileOpen}
          onNavigate={() => setMobileOpen(false)}
          onLogout={handleLogout}
        />

        <main className="min-w-0 flex-1">
          <div
            className={cn(
              'sticky top-0 z-30 hidden shrink-0 items-center gap-2 border-b bg-card/80 px-6 backdrop-blur lg:flex',
              HEADER_HEIGHT
            )}
          >
            <div className="ml-auto flex items-center gap-1">
              <ThemeToggle />
              <NotificationBell />
              {/*
                The applicant's own identity, beside the greeting rather than
                buried in a menu. It doubles as the way into the profile page,
                which is where somebody looks for it.
              */}
              <NavLink
                to="/portal/profile"
                className={({ isActive }) =>
                  cn(
                    'ml-1 flex items-center gap-2 rounded-full border py-1 pl-1 pr-3 text-sm transition-colors',
                    isActive
                      ? 'border-primary/40 bg-primary/10 text-foreground'
                      : 'border-transparent hover:bg-accent'
                  )
                }
              >
                <span
                  className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground"
                  aria-hidden="true"
                >
                  {initialsOf(user?.full_name)}
                </span>
                <span className="max-w-[12rem] truncate">{user?.full_name}</span>
              </NavLink>
            </div>
          </div>

          {/*
            Narrower than the staff system on purpose. An applicant is reading
            their own single record, and a line of text spanning a wide monitor
            is harder to read, not more informative.
          */}
          <div className="mx-auto max-w-3xl px-4 py-6 lg:px-6">
            <Outlet />
          </div>

          <footer className="mx-auto max-w-3xl px-4 pb-8 pt-2 lg:px-6">
            <p className="text-center text-xs text-muted-foreground">
              Questions about your application? Contact the CDE Manpower Services office in Sta.
              Cruz, Laguna.
            </p>
          </footer>
        </main>
      </div>
    </div>
  )
}

/**
 * The portal navigation.
 *
 * `collapsed` applies only from `lg` up, and that constraint is expressed
 * entirely through `lg:` classes rather than by branching in JavaScript. Hiding
 * labels with `{!collapsed && ...}` has no idea how wide the screen is, so
 * somebody who collapsed the sidebar on a laptop would then open the menu on
 * their phone and find a full-width drawer of unlabelled icons. Everything is
 * always rendered; only CSS decides what is seen.
 */
function PortalSidebar({ links, user, collapsed, onToggleCollapsed, mobileOpen, onNavigate, onLogout }) {
  const { countFor } = useNotifications()

  return (
    <aside
      id="portal-sidebar"
      className={cn(
        'scrollbar-none w-64 shrink-0 bg-sidebar text-sidebar-foreground transition-[width] duration-200 ease-out',
        'border-r border-sidebar-border',
        'lg:sticky lg:top-0 lg:block lg:h-screen',
        collapsed && 'lg:w-16',
        mobileOpen ? 'fixed inset-x-0 bottom-0 top-[57px] z-30 block overflow-y-auto' : 'hidden'
      )}
    >
      <div className="flex h-full flex-col">
        <div
          className={cn(
            'hidden shrink-0 items-center gap-2.5 border-b border-sidebar-border px-5 lg:flex',
            HEADER_HEIGHT,
            collapsed && 'lg:justify-center lg:px-0'
          )}
        >
          {/* plain: the sidebar is painted the artwork's own navy, so the logo's
              baked-in background disappears and only the monogram shows. */}
          <LogoMark size="sm" plain className={cn(collapsed && 'lg:hidden')} />
          <div className={cn('min-w-0', collapsed && 'lg:hidden')}>
            <p className="truncate text-sm font-semibold leading-tight">EMPOWER</p>
            <p className="truncate text-xs text-sidebar-muted">CDE Manpower Services</p>
          </div>
          <Button
            variant="ghost"
            size="icon"
            className="ml-auto shrink-0 text-sidebar-foreground hover:bg-sidebar-hover hover:text-sidebar-foreground"
            onClick={onToggleCollapsed}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            aria-expanded={!collapsed}
            aria-controls="portal-sidebar"
            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            <Menu className="h-5 w-5" />
          </Button>
        </div>

        {/* The applicant, named, at the top of their own navigation. On the rail
            only the avatar survives, which is still enough to say whose it is. */}
        <div
          className={cn(
            'flex items-center gap-2.5 border-b border-sidebar-border px-4 py-3',
            collapsed && 'lg:justify-center lg:px-0'
          )}
        >
          <span
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/15 text-sm font-semibold text-sidebar-foreground"
            aria-hidden="true"
          >
            {initialsOf(user?.full_name)}
          </span>
          <div className={cn('min-w-0', collapsed && 'lg:hidden')}>
            <p className="truncate text-sm font-medium leading-tight">{user?.full_name}</p>
            <p className="truncate text-xs text-sidebar-muted">
              {user?.user_type === 'employee' ? 'Employee' : 'Applicant'}
            </p>
          </div>
        </div>

        <nav
          className={cn('scrollbar-none flex-1 overflow-y-auto px-3 py-4', collapsed && 'lg:px-2')}
          aria-label="Portal"
        >
          <ul className="space-y-0.5">
            {links.map((link) => {
              const unread = countFor(link.categories)

              return (
                <li key={link.to}>
                  <NavLink
                    to={link.to}
                    end={link.end}
                    onClick={onNavigate}
                    title={collapsed ? link.label : undefined}
                    className={({ isActive }) =>
                      cn(
                        'flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm transition-colors',
                        collapsed && 'lg:justify-center lg:gap-0 lg:px-0 lg:py-2.5',
                        // On navy, a pale wash plus a light accent reads as
                        // selected. The light-mode treatment of a tinted
                        // background with dark text would vanish here.
                        isActive
                          ? 'bg-white/15 font-medium text-sidebar-active'
                          : 'text-sidebar-foreground/80 hover:bg-sidebar-hover hover:text-sidebar-foreground'
                      )
                    }
                  >
                    <span className="relative shrink-0">
                      <link.icon className="h-4 w-4" />
                      {/* On the rail there is no room for a number, so the
                          count becomes a dot - still saying "something here
                          needs you", which is the part that matters at a
                          glance. */}
                      {unread > 0 && collapsed && (
                        <span className="absolute -right-1 -top-1 hidden h-2 w-2 rounded-full bg-destructive lg:block" />
                      )}
                    </span>

                    <span className={cn('truncate', collapsed && 'lg:hidden')}>{link.label}</span>

                    {unread > 0 && (
                      <span
                        className={cn(
                          'ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1.5 text-[11px] font-semibold tabular-nums text-destructive-foreground',
                          collapsed && 'lg:hidden'
                        )}
                        // Read out as words, since a bare number beside a link
                        // announces nothing on its own.
                        aria-label={`${unread} unread`}
                      >
                        {unread > 9 ? '9+' : unread}
                      </span>
                    )}
                  </NavLink>
                </li>
              )
            })}
          </ul>
        </nav>

        <div className={cn('border-t border-sidebar-border p-3', collapsed && 'lg:p-2')}>
          <Button
            variant="ghost"
            size="sm"
            className={cn(
              'w-full justify-start text-sidebar-foreground/90 hover:bg-sidebar-hover hover:text-sidebar-foreground',
              collapsed && 'lg:justify-center lg:px-0'
            )}
            onClick={onLogout}
            title={collapsed ? 'Sign out' : undefined}
          >
            <LogOut className="h-4 w-4" />
            <span className={cn(collapsed && 'lg:hidden')}>Sign out</span>
          </Button>
        </div>
      </div>
    </aside>
  )
}
