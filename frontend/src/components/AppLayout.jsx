import * as React from 'react'
import { Outlet, useNavigate } from 'react-router-dom'
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
  BarChart3,
  UserCog,
  SlidersHorizontal,
  CalendarClock,
  Flag,
  Handshake,
  Hourglass,
} from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import NotificationBell from '@/components/NotificationBell'
import CommandPalette from '@/components/CommandPalette'
import ThemeToggle from '@/components/ThemeToggle'
import { useNotifications } from '@/contexts/NotificationsContext'
import { LoadingState } from '@/components/ui/states'
import {
  ShellFrame,
  ShellMobileBar,
  ShellSidebar,
  ShellHeader,
  SidebarGroup,
  SidebarLink,
  SidebarAction,
  ProfileMenu,
  headerIconButton,
  useSidebarCollapsed,
  useEscapeToClose,
} from '@/components/AppShell'

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
      { to: '/retention', label: 'Retention Review', icon: Hourglass, permission: 'archives.view' },
      { to: '/users', label: 'Users & Access', icon: UserCog, permission: 'users.view' },
      { to: '/audit-logs', label: 'Audit Trail', icon: ScrollText, permission: 'audit.view' },
    ],
  },
]

/** No permission: every user has a My Account tab in Settings. */
const SETTINGS_LINK = { to: '/settings', label: 'Settings', icon: SlidersHorizontal }

const SIDEBAR_KEY = 'empower.sidebar.collapsed'

/** "hr" reads as HR, "admin" as Admin. */
function roleLabel(role) {
  const text = String(role).replace(/_/g, ' ')
  return text.length <= 2 ? text.toUpperCase() : text.charAt(0).toUpperCase() + text.slice(1)
}

export default function AppLayout() {
  const { user, logout, can } = useAuth()
  const { countFor } = useNotifications()
  const navigate = useNavigate()
  const toast = useToast()
  const [mobileOpen, setMobileOpen] = React.useState(false)
  const [collapsed, toggleCollapsed] = useSidebarCollapsed(SIDEBAR_KEY)
  const closeMobile = React.useCallback(() => setMobileOpen(false), [])

  useEscapeToClose(mobileOpen, closeMobile)

  async function handleLogout() {
    await logout()
    navigate('/login', { replace: true })
    toast.success('Signed out', 'Your session on this computer has ended.')
  }

  const sections = NAVIGATION.map((section) => ({
    ...section,
    items: section.items.filter((item) => can(item.permission)),
  })).filter((section) => section.items.length > 0)

  const roles = (user?.roles?.length ? user.roles : [user?.user_type]).filter(Boolean).map(roleLabel).join(', ')
  // A coordinator always sees which client they are acting for.
  const subtitle = user?.coordinator?.company_name ?? roles

  return (
    <ShellFrame
      mobileOpen={mobileOpen}
      onCloseMobile={closeMobile}
      mobileBar={
        <ShellMobileBar mobileOpen={mobileOpen} onToggle={() => setMobileOpen((open) => !open)} controls="main-sidebar">
          <CommandPalette />
          <ThemeToggle className={headerIconButton} />
          <NotificationBell />
        </ShellMobileBar>
      }
      sidebar={
        <ShellSidebar
          id="main-sidebar"
          label="Main"
          collapsed={collapsed}
          mobileOpen={mobileOpen}
          onToggleCollapsed={toggleCollapsed}
          footer={
            <ul className="space-y-0.5">
              <SidebarLink {...SETTINGS_LINK} collapsed={collapsed} onNavigate={closeMobile} />
              <SidebarAction icon={LogOut} label="Log out" onClick={handleLogout} collapsed={collapsed} />
            </ul>
          }
        >
          {sections.map((section) => (
            <SidebarGroup key={section.section} label={section.section}>
              {section.items.map((item) => (
                <SidebarLink
                  key={item.to}
                  {...item}
                  unread={countFor(item.categories)}
                  collapsed={collapsed}
                  onNavigate={closeMobile}
                />
              ))}
            </SidebarGroup>
          ))}
        </ShellSidebar>
      }
    >
      <div className="mx-auto max-w-[1400px] px-4 pb-8 pt-2 sm:px-6 lg:px-0 lg:pb-4 lg:pt-0">
        <ShellHeader search={<CommandPalette />}>
          <ThemeToggle className={headerIconButton} />
          <NotificationBell />
          <ProfileMenu
            name={user?.full_name}
            subtitle={subtitle}
            details={[roles, user?.coordinator?.company_name]}
            items={[{ to: SETTINGS_LINK.to, label: 'My account', icon: SETTINGS_LINK.icon }]}
            onLogout={handleLogout}
          />
        </ShellHeader>

        {/* Pages load on demand; the shell stays put while one arrives. */}
        <React.Suspense fallback={<LoadingState label="Loading…" />}>
          <Outlet />
        </React.Suspense>
      </div>
    </ShellFrame>
  )
}
