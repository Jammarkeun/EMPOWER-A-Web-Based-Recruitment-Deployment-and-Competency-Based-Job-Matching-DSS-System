import * as React from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  LayoutDashboard,
  Users,
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
} from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import { LogoMark } from '@/components/Logo'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import NotificationBell from '@/components/NotificationBell'
import CommandPalette from '@/components/CommandPalette'
import ThemeToggle from '@/components/ThemeToggle'

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
      { to: '/applicants', label: 'Applicants', icon: Users, permission: 'applicants.view' },
      { to: '/trainings', label: 'Training', icon: GraduationCap, permission: 'training.view' },
    ],
  },
  {
    section: 'Deployment',
    items: [
      { to: '/deployments', label: 'Deployments', icon: Truck, permission: 'deployment.view' },
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

export default function AppLayout() {
  const { user, logout, can } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()
  const [mobileOpen, setMobileOpen] = React.useState(false)

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
        <Sidebar
          sections={sections}
          user={user}
          onLogout={handleLogout}
          mobileOpen={mobileOpen}
          onNavigate={() => setMobileOpen(false)}
        />

        <main className="min-w-0 flex-1">
          {/* Sits above the page content rather than in the sidebar, so search
              and the unread badge stay reachable while scrolling a long list. */}
          <div className="sticky top-0 z-30 hidden items-center gap-2 border-b bg-card/80 px-8 py-2 backdrop-blur lg:flex no-print">
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

function Sidebar({ sections, user, onLogout, mobileOpen, onNavigate }) {
  return (
    <aside
      className={cn(
        'no-print w-64 shrink-0 border-r bg-card',
        'lg:sticky lg:top-0 lg:block lg:h-screen',
        mobileOpen ? 'fixed inset-x-0 top-[57px] bottom-0 z-30 block overflow-y-auto' : 'hidden'
      )}
    >
      <div className="flex h-full flex-col">
        <div className="hidden items-center gap-2.5 border-b px-5 py-4 lg:flex">
          <LogoMark size="sm" />
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold leading-tight">EMPOWER</p>
            <p className="truncate text-xs text-muted-foreground">CDE Manpower Services</p>
          </div>
        </div>

        <nav className="flex-1 space-y-5 overflow-y-auto px-3 py-4" aria-label="Main">
          {sections.map((section) => (
            <div key={section.section}>
              <p className="px-2 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {section.section}
              </p>
              <ul className="space-y-0.5">
                {section.items.map((item) => (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      end={item.end}
                      onClick={onNavigate}
                      className={({ isActive }) =>
                        cn(
                          'flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm transition-colors',
                          isActive
                            ? 'bg-primary/10 font-medium text-primary'
                            : 'text-foreground/80 hover:bg-accent hover:text-foreground'
                        )
                      }
                    >
                      <item.icon className="h-4 w-4 shrink-0" />
                      <span className="truncate">{item.label}</span>
                    </NavLink>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>

        <div className="border-t p-3">
          <div className="mb-2 px-2">
            <p className="truncate text-sm font-medium">{user?.full_name}</p>
            <p className="truncate text-xs capitalize text-muted-foreground">
              {user?.roles?.join(', ') || user?.user_type}
            </p>
          </div>
          <Button variant="ghost" size="sm" className="w-full justify-start" onClick={onLogout}>
            <LogOut className="h-4 w-4" />
            Sign out
          </Button>
        </div>
      </div>
    </aside>
  )
}
