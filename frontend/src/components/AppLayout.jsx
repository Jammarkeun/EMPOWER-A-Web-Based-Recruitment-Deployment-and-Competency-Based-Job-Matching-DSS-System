import * as React from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import {
  LayoutDashboard,
  Users,
  FolderOpen,
  Building2,
  ClipboardList,
  GraduationCap,
  Truck,
  IdCard,
  FileWarning,
  LogOut,
  Archive,
  ScrollText,
  Menu,
  X,
  BarChart3,
  UserCog,
  SlidersHorizontal,
  ChevronDown,
} from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import { LogoMark } from '@/components/Logo'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import NotificationBell from '@/components/NotificationBell'
import CommandPalette from '@/components/CommandPalette'
import ThemeToggle from '@/components/ThemeToggle'
import { useNotifications } from '@/contexts/NotificationsContext'

/**
 * Navigation mirrors the agency's workflow order rather than grouping by data
 * type, so the sidebar reads the way the work actually happens: requests come
 * in, applicants are screened, then deployed, then managed.
 *
 * Each entry declares the permission it needs. Items the user cannot use are
 * hidden rather than shown-and-refused, which keeps the HR view uncluttered.
 */
const NAVIGATION = [
  {
    section: 'Overview',
    items: [{ to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, permission: 'dashboard.view' }],
  },
  {
    section: 'Recruitment',
    items: [
      { to: '/job-requests', label: 'Manpower Requests', icon: ClipboardList, permission: 'job_requests.view' },
      // categories: the notification kinds this section is responsible for.
      // Named here beside the navigation because the mapping is a judgement
      // about where somebody would go to deal with the thing, not a property
      // of the notification itself.
      { to: '/applicants', label: 'Applicants', icon: Users, permission: 'applicants.view', categories: ['application'] },
      { to: '/documents', label: 'Documents', icon: FolderOpen, permission: 'applicants.view', categories: ['requirements'] },
      { to: '/trainings', label: 'Training', icon: GraduationCap, permission: 'training.view' },
    ],
  },
  {
    section: 'Deployment',
    items: [
      { to: '/deployments', label: 'Deployments', icon: Truck, permission: 'deployment.view', categories: ['deployment'] },
      { to: '/employees', label: 'Employees', icon: IdCard, permission: 'employees.view' },
      { to: '/separations', label: 'Separations', icon: FileWarning, permission: 'separation.view' },
    ],
  },
  {
    section: 'Administration',
    items: [
      { to: '/clients', label: 'Client Companies', icon: Building2, permission: 'clients.view' },
      { to: '/reports', label: 'Reports', icon: BarChart3, permission: 'reports.view' },
      { to: '/archives', label: 'Archives', icon: Archive, permission: 'archives.view' },
      { to: '/users', label: 'Users & Access', icon: UserCog, permission: 'users.view' },
      { to: '/audit-logs', label: 'Audit Trail', icon: ScrollText, permission: 'audit.view' },
      // No permission: every user has a My Account tab here.
      { to: '/settings', label: 'Settings', icon: SlidersHorizontal },
    ],
  },
]

/**
 * Whether the desktop sidebar is collapsed to an icon rail.
 *
 * Remembered across reloads, because this is a working preference rather than a
 * transient view state: someone on a small laptop who collapses it to see a wide
 * applicant table does not want it expanded again on every navigation.
 *
 * Wrapped in try/catch because localStorage throws outright in a few real
 * situations - a private window with site data blocked, most commonly - and a
 * sidebar preference is never worth breaking the whole layout over.
 */
/**
 * The height of both top bars.
 *
 * The sidebar's logo block and the main top bar sit side by side, so their
 * bottom borders read as one continuous line across the screen — but only if
 * they are exactly the same height. They previously set their own padding
 * independently and came out 67px and 53px, leaving a visible 14px step where
 * the two borders met. Declaring it once is what stops them drifting apart
 * again the next time either is touched.
 */
const HEADER_HEIGHT = 'h-14'

const SIDEBAR_KEY = 'empower.sidebar.collapsed'
const SIDEBAR_SECTIONS_KEY = 'empower.sidebar.sections'

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
        // Preference simply will not persist; the toggle still works.
      }
      return next
    })
  }, [])

  return [collapsed, toggle]
}

export default function AppLayout() {
  const { user, logout, can } = useAuth()
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

  async function handleLogout() {
    await logout()
    navigate('/login', { replace: true })
    // Confirmed on the way out. Signing out on a shared counter machine is
    // exactly the action a user needs to know actually completed.
    toast.success('Signed out', 'Your session on this computer has ended.')
  }

  const sections = NAVIGATION.map((section) => ({
    ...section,
    items: section.items.filter((item) => can(item.permission)),
  })).filter((section) => section.items.length > 0)

  return (
    <div className="min-h-screen bg-background">
      {/* Mobile header */}
      <header className="sticky top-0 z-40 flex items-center justify-between border-b bg-card px-4 py-3 lg:hidden no-print">
        <div className="flex items-center gap-2">
          <LogoMark size="xs" />
          <span className="font-semibold">EMPOWER</span>
        </div>
        <div className="flex items-center gap-1">
          <CommandPalette />
          <ThemeToggle />
          <NotificationBell />
          <Button
            variant="ghost"
            size="icon"
            onClick={() => setMobileOpen((open) => !open)}
            aria-label={mobileOpen ? 'Close navigation' : 'Open navigation'}
            aria-expanded={mobileOpen}
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
        <Sidebar
          sections={sections}
          user={user}
          onLogout={handleLogout}
          mobileOpen={mobileOpen}
          collapsed={collapsed}
          onToggleCollapsed={toggleCollapsed}
          onNavigate={() => setMobileOpen(false)}
        />

        <main className="min-w-0 flex-1">
          {/* Sits above the page content rather than in the sidebar, so search
              and the unread badge stay reachable while scrolling a long list. */}
          <div
            className={cn(
              'sticky top-0 z-30 hidden shrink-0 items-center gap-2 border-b bg-card/80 px-8 backdrop-blur lg:flex no-print',
              HEADER_HEIGHT
            )}
          >
            <CommandPalette />
            <div className="ml-auto flex items-center gap-1">
              <ThemeToggle />
              <NotificationBell />
            </div>
          </div>

          <div className="mx-auto max-w-7xl px-4 py-6 lg:px-8">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}

/**
 * The main navigation.
 *
 * Collapsing narrows it to an icon rail rather than hiding it outright. On a
 * system where people move between applicants, requests and deployments all day,
 * hiding navigation entirely trades one click for two; a rail keeps every
 * destination one click away while giving most of the width back to the content.
 *
 * `collapsed` applies only from `lg` up. On a phone the sidebar is a drawer that
 * is either open or shut, and a permanent 64px rail down the side of a small
 * screen would be worse than either state.
 *
 * That constraint is enforced entirely through `lg:` classes rather than by
 * branching in JavaScript, and the difference matters. An earlier version hid
 * the labels with `{!collapsed && ...}`, which has no idea what size the screen
 * is - so a user who collapsed the sidebar on their laptop then opened the menu
 * on their phone got a full-width drawer containing nothing but unlabelled
 * icons. Everything is always rendered here; only CSS decides what is seen.
 */
function Sidebar({ sections, user, onLogout, mobileOpen, collapsed, onToggleCollapsed, onNavigate }) {
  const { countFor } = useNotifications()
  const location = useLocation()
  const [openSections, setOpenSections] = React.useState(() => {
    try {
      const saved = JSON.parse(localStorage.getItem(SIDEBAR_SECTIONS_KEY) ?? '{}')
      return Object.fromEntries(sections.map((section) => [section.section, saved[section.section] ?? false]))
    } catch {
      return Object.fromEntries(sections.map((section) => [section.section, false]))
    }
  })

  React.useEffect(() => {
    try {
      localStorage.setItem(SIDEBAR_SECTIONS_KEY, JSON.stringify(openSections))
    } catch {
      // The menu remains usable when browser storage is unavailable.
    }
  }, [openSections])

  function toggleSection(sectionName) {
    setOpenSections((current) => ({ ...current, [sectionName]: !current[sectionName] }))
  }

  function sectionIsActive(section) {
    return section.items.some(
      (item) => location.pathname === item.to || location.pathname.startsWith(`${item.to}/`)
    )
  }

  React.useEffect(() => {
    const activeSections = sections
      .filter(sectionIsActive)
      .map((section) => section.section)

    if (activeSections.length === 0) return
    setOpenSections((current) => ({
      ...current,
      ...Object.fromEntries(activeSections.map((section) => [section, true])),
    }))
  }, [location.pathname])

  return (
    <aside
      id="main-sidebar"
      className={cn(
        'no-print scrollbar-none w-64 shrink-0 bg-sidebar text-sidebar-foreground transition-[width] duration-200 ease-out',
        'border-r border-sidebar-border',
        'lg:sticky lg:top-0 lg:block lg:h-screen',
        collapsed && 'lg:w-16',
        mobileOpen ? 'fixed inset-x-0 top-[57px] bottom-0 z-30 block overflow-y-auto' : 'hidden'
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
          {/* plain: the sidebar is painted the artwork's own navy, so the
              logo's baked-in background disappears and only the white monogram
              shows. A badge ring here would draw the square edge back in. */}
          <LogoMark size="sm" plain className={cn(collapsed && 'lg:hidden')} />
          {/* Hidden rather than truncated: "EMPO..." in a 64px rail reads as a
              rendering fault rather than as a shortened name. */}
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
            aria-controls="main-sidebar"
            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            <Menu className="h-5 w-5" />
          </Button>
        </div>

        <nav
          className={cn(
            'scrollbar-none flex-1 space-y-5 overflow-y-auto px-3 py-4',
            collapsed && 'lg:space-y-2 lg:px-2'
          )}
          aria-label="Main"
        >
          {sections.map((section) => (
            <div key={section.section}>
              {/* On the rail the heading becomes a rule: the groups still need
                  separating, and dropping the distinction entirely runs all
                  fifteen links together into one undifferentiated column. */}
              {collapsed && (
                <div className="mx-2 mb-2 hidden border-t border-sidebar-border first:border-t-0 lg:block" aria-hidden="true" />
              )}
              <button
                type="button"
                onClick={() => toggleSection(section.section)}
                aria-expanded={collapsed || openSections[section.section]}
                className={cn(
                  'flex w-full items-center justify-between px-2.5 py-2 text-left text-xs transition-colors',
                  sectionIsActive(section)
                    ? 'font-bold text-sidebar-active'
                    : 'font-medium text-sidebar-muted hover:text-sidebar-foreground',
                  collapsed && 'lg:hidden'
                )}
              >
                <span>{section.section}</span>
                {section.items.reduce((total, item) => total + countFor(item.categories), 0) > 0 && (
                  <span className="ml-auto mr-2 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-destructive-foreground">
                    {Math.min(
                      99,
                      section.items.reduce((total, item) => total + countFor(item.categories), 0)
                    )}
                  </span>
                )}
                <ChevronDown
                  className={cn(
                    'h-3.5 w-3.5 transition-transform',
                    !openSections[section.section] && '-rotate-90'
                  )}
                  aria-hidden="true"
                />
              </button>
              <ul
                className={cn(
                  'ml-2 space-y-0.5 border-l border-sidebar-border pl-2',
                  collapsed && 'lg:ml-0 lg:border-0 lg:pl-0',
                  !(collapsed || openSections[section.section]) && 'hidden'
                )}
              >
                {section.items.map((item) => {
                  const unread = countFor(item.categories)

                  return (
                    <li key={item.to}>
                      <NavLink
                        to={item.to}
                        end={item.end}
                        onClick={onNavigate}
                        // Only a tooltip; the label itself is still in the DOM, so
                        // a screen reader announces "Applicants" either way.
                        title={collapsed ? item.label : undefined}
                        className={({ isActive }) =>
                          cn(
                            'flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm transition-colors',
                            collapsed && 'lg:justify-center lg:gap-0 lg:px-0 lg:py-2.5',
                            // On navy, a pale wash plus a light accent reads
                            // as selected; the light-mode treatment of a tinted
                            // background with dark text would vanish here.
                            isActive
                              ? 'bg-white/15 font-medium text-sidebar-active'
                              : 'text-sidebar-foreground/80 hover:bg-sidebar-hover hover:text-sidebar-foreground'
                          )
                        }
                      >
                        <span className="relative shrink-0">
                          <item.icon className="h-4 w-4" />
                          {/* A dot on the rail, where there is no room for a
                              number but "something here needs you" still
                              needs saying. */}
                          {unread > 0 && collapsed && (
                            <span className="absolute -right-1 -top-1 hidden h-2 w-2 rounded-full bg-destructive lg:block" />
                          )}
                        </span>

                        <span className={cn('truncate', collapsed && 'lg:hidden')}>{item.label}</span>

                        {unread > 0 && (
                          <span
                            className={cn(
                              'ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1.5 text-[11px] font-semibold tabular-nums text-destructive-foreground',
                              collapsed && 'lg:hidden'
                            )}
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
            </div>
          ))}
        </nav>

        <div className={cn('border-t border-sidebar-border p-3', collapsed && 'lg:p-2')}>
          <div className={cn('mb-2 px-2', collapsed && 'lg:hidden')}>
            <p className="truncate text-sm font-medium">{user?.full_name}</p>
            <p className="truncate text-xs capitalize text-sidebar-muted">
              {user?.roles?.join(', ') || user?.user_type}
            </p>
          </div>
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
