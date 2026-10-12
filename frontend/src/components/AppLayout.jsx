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
  CalendarClock,
  Flag,
  Handshake,
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
  /*
   * The coordinator's workspace: one client company. Only a coordinator holds
   * `coordination.access`, and a coordinator holds nothing else, so for them
   * this is the whole sidebar and for staff it does not appear at all.
   */
  {
    section: 'My Client',
    items: [
      { to: '/coordinator', label: 'Dashboard', icon: LayoutDashboard, permission: 'coordination.access', end: true },
      { to: '/coordinator/company', label: 'My Client Company', icon: Handshake, permission: 'coordination.access' },
      { to: '/coordinator/employees', label: 'Employees', icon: IdCard, permission: 'coordination.access' },
      { to: '/coordinator/deployments', label: 'Deployments', icon: Truck, permission: 'coordination.access' },
      { to: '/coordinator/reports', label: 'Violation Reports', icon: Flag, permission: 'coordination.access', categories: ['coordination'] },
      { to: '/coordinator/requests', label: 'Manpower Requests', icon: ClipboardList, permission: 'coordination.access' },
    ],
  },
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
      { to: '/attendance', label: 'Attendance (DTR)', icon: CalendarClock, permission: 'attendance.view' },
      { to: '/violation-reports', label: 'Violation Reports', icon: Flag, permission: 'violation_reports.view', categories: ['coordination'] },
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

function getBreadcrumbContext(pathname) {
  if (pathname.startsWith('/coordinator/company')) return { section: 'My Client', page: 'My Client Company' }
  if (pathname.startsWith('/coordinator/employees')) return { section: 'My Client', page: 'Employees' }
  if (pathname.startsWith('/coordinator/deployments')) return { section: 'My Client', page: 'Deployments' }
  if (pathname.startsWith('/coordinator/reports')) return { section: 'My Client', page: 'Violation Reports' }
  if (pathname.startsWith('/coordinator/requests')) return { section: 'My Client', page: 'Manpower Requests' }
  if (pathname.startsWith('/coordinator')) return { section: 'My Client', page: 'Dashboard' }
  if (pathname.startsWith('/dashboard')) return { section: 'Overview', page: 'Dashboard' }
  if (pathname.startsWith('/attendance')) return { section: 'Deployment', page: 'Attendance (DTR)' }
  if (pathname.startsWith('/violation-reports')) return { section: 'Deployment', page: 'Violation Reports' }
  if (pathname.startsWith('/job-requests')) return { section: 'Recruitment', page: 'Manpower Requests' }
  if (pathname.startsWith('/applicants')) return { section: 'Recruitment', page: 'Applicants' }
  if (pathname.startsWith('/documents')) return { section: 'Recruitment', page: 'Documents' }
  if (pathname.startsWith('/trainings')) return { section: 'Recruitment', page: 'Training' }
  if (pathname.startsWith('/deployments')) return { section: 'Deployment', page: 'Deployments' }
  if (pathname.startsWith('/employees')) return { section: 'Deployment', page: 'Employees' }
  if (pathname.startsWith('/separations')) return { section: 'Deployment', page: 'Separations' }
  if (pathname.startsWith('/clients')) return { section: 'Administration', page: 'Client Companies' }
  if (pathname.startsWith('/reports')) return { section: 'Administration', page: 'Reports' }
  if (pathname.startsWith('/archives')) return { section: 'Administration', page: 'Archives' }
  if (pathname.startsWith('/users')) return { section: 'Administration', page: 'Users & Access' }
  if (pathname.startsWith('/audit-logs')) return { section: 'Administration', page: 'Audit Trail' }
  if (pathname.startsWith('/settings')) return { section: 'Administration', page: 'Settings' }
  return { section: 'EMPOWER', page: 'Overview' }
}

export default function AppLayout() {
  const { user, logout, can } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
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
    toast.success('Signed out', 'Your session on this computer has ended.')
  }

  const sections = NAVIGATION.map((section) => ({
    ...section,
    items: section.items.filter((item) => can(item.permission)),
  })).filter((section) => section.items.length > 0)

  const crumbContext = getBreadcrumbContext(location.pathname)

  return (
    <div className="min-h-screen bg-background text-foreground font-sans">
      {/* Mobile header */}
      <header className="sticky top-0 z-40 flex items-center justify-between border-b border-border bg-card px-4 py-3 lg:hidden no-print">
        <div className="flex items-center gap-2">
          <LogoMark size="xs" />
          <span className="font-heading font-bold text-sm tracking-tight">EMPOWER</span>
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
          {/* Top Bar */}
          <div
            className={cn(
              'sticky top-0 z-30 hidden shrink-0 items-center justify-between border-b border-border bg-card/95 px-6 backdrop-blur lg:flex no-print',
              HEADER_HEIGHT
            )}
          >
            <div className="flex items-center gap-4">
              <nav aria-label="Page Context" className="flex items-center gap-1.5 text-xs text-muted-foreground font-medium">
                <span>{crumbContext.section}</span>
                <span className="text-muted-foreground/50">/</span>
                <span className="font-semibold text-foreground">{crumbContext.page}</span>
              </nav>
              <div className="h-4 w-px bg-border" />
              <CommandPalette />
            </div>
            <div className="flex items-center gap-1.5">
              <ThemeToggle />
              <NotificationBell />
            </div>
          </div>

          <div className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}

function Sidebar({ sections, user, onLogout, mobileOpen, collapsed, onToggleCollapsed, onNavigate }) {
  const { countFor } = useNotifications()
  const location = useLocation()

  function userInitials(name) {
    if (!name) return 'HR'
    const parts = name.trim().split(/\s+/)
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  }

  return (
    <aside
      id="main-sidebar"
      className={cn(
        'no-print scrollbar-none w-64 shrink-0 bg-navy-900 text-sidebar-foreground transition-[width] duration-200 ease-out',
        'border-r border-sidebar-border',
        'lg:sticky lg:top-0 lg:block lg:h-screen',
        collapsed && 'lg:w-16',
        mobileOpen ? 'fixed inset-x-0 top-[57px] bottom-0 z-30 block overflow-y-auto' : 'hidden'
      )}
    >
      <div className="flex h-full flex-col">
        {/* Logo block */}
        <div
          className={cn(
            'hidden shrink-0 items-center justify-between border-b border-sidebar-border/60 px-4 lg:flex',
            HEADER_HEIGHT,
            collapsed && 'lg:justify-center lg:px-0'
          )}
        >
          <div className="flex items-center gap-2.5 min-w-0">
            <LogoMark size="sm" plain className="shrink-0" />
            <div className={cn('min-w-0 leading-tight', collapsed && 'lg:hidden')}>
              <p className="font-heading text-sm font-bold tracking-tight text-white">EMPOWER</p>
              <p className="text-[11px] text-sidebar-muted/90 whitespace-normal">CDE Manpower Services</p>
            </div>
          </div>
          <Button
            variant="ghost"
            size="icon"
            className="h-8 w-8 text-sidebar-muted hover:bg-navy-800 hover:text-white shrink-0"
            onClick={onToggleCollapsed}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            <Menu className="h-4 w-4" />
          </Button>
        </div>

        {/* Navigation Groups */}
        <nav
          className={cn(
            'scrollbar-none flex-1 space-y-4 overflow-y-auto px-3 py-4',
            collapsed && 'lg:space-y-3 lg:px-2'
          )}
          aria-label="Main"
        >
          {sections.map((section) => (
            <div key={section.section} className="space-y-1">
              {!collapsed ? (
                <div className="px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-sidebar-muted/80">
                  {section.section}
                </div>
              ) : (
                <div className="mx-2 my-2 hidden border-t border-sidebar-border/50 lg:block" />
              )}

              <ul className="space-y-1">
                {section.items.map((item) => {
                  const unread = countFor(item.categories)

                  return (
                    <li key={item.to}>
                      <NavLink
                        to={item.to}
                        end={item.end}
                        onClick={onNavigate}
                        title={collapsed ? item.label : undefined}
                        className={({ isActive }) =>
                          cn(
                            'relative flex items-center gap-3 rounded-control px-2.5 h-9 text-xs font-medium transition-colors',
                            collapsed && 'lg:justify-center lg:gap-0 lg:px-0',
                            isActive
                              ? 'bg-navy-700 text-white font-semibold before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-4.5 before:w-[3px] before:bg-brand before:rounded-r'
                              : 'text-sidebar-foreground/80 hover:bg-navy-800 hover:text-white'
                          )
                        }
                      >
                        <span className="relative shrink-0">
                          <item.icon className="h-4 w-4 stroke-[1.75]" />
                          {unread > 0 && collapsed && (
                            <span className="absolute -right-1 -top-1 hidden h-2 w-2 rounded-full bg-brand lg:block" />
                          )}
                        </span>

                        <span className={cn('truncate', collapsed && 'lg:hidden')}>{item.label}</span>

                        {unread > 0 && (
                          <span
                            className={cn(
                              'ml-auto flex h-4.5 min-w-4.5 items-center justify-center rounded-tag bg-brand/30 px-1 text-[10px] font-mono font-semibold tabular-nums text-blue-200',
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

        {/* Footer User Block */}
        <div className={cn('border-t border-sidebar-border/60 p-3 space-y-2', collapsed && 'lg:p-2')}>
          <div className={cn('flex items-center gap-2.5 px-1', collapsed && 'lg:justify-center lg:px-0')}>
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-control bg-navy-700 text-xs font-bold text-white font-mono">
              {userInitials(user?.full_name)}
            </span>
            <div className={cn('min-w-0 flex-1', collapsed && 'lg:hidden')}>
              <p className="truncate text-xs font-semibold text-white leading-tight">{user?.full_name}</p>
              <p className="truncate text-[11px] capitalize text-sidebar-muted">
                {user?.roles?.join(', ') || user?.user_type}
              </p>
              {/* A coordinator always sees which client they are acting for. */}
              {user?.coordinator?.company_name && (
                <p className="truncate text-[11px] text-sidebar-muted" title={user.coordinator.company_name}>
                  {user.coordinator.company_name}
                </p>
              )}
            </div>
          </div>

          <Button
            variant="ghost"
            size="sm"
            className={cn(
              'w-full justify-start h-8 text-xs text-sidebar-muted hover:bg-navy-800 hover:text-white font-normal',
              collapsed && 'lg:justify-center lg:px-0'
            )}
            onClick={onLogout}
            title={collapsed ? 'Sign out' : undefined}
          >
            <LogOut className="h-3.5 w-3.5" />
            <span className={cn(collapsed && 'lg:hidden')}>Sign out</span>
          </Button>
        </div>
      </div>
    </aside>
  )
}
