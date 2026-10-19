import * as React from 'react'
import { Outlet, useNavigate } from 'react-router-dom'
import { LogOut, FileText, Home, Briefcase, UserRound, SlidersHorizontal } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import NotificationBell from '@/components/NotificationBell'
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
 * Shell for the applicant and employee portal.
 *
 * Deliberately a different layout from the staff system rather than the same one
 * with items hidden. An applicant checking their application should not be
 * looking at an interface built for managing hundreds of records: there is no
 * record search here, the content column is narrower, and the sidebar names the
 * portal so it is obvious at a glance which side of the system you are on.
 *
 * What is shared is the *chrome*, not the content: the sidebar, header trays,
 * and profile menu come from AppShell, so the two sides look and behave alike in
 * both themes without a second palette to get wrong in dark mode.
 */

const SIDEBAR_KEY = 'empower.portal.sidebar.collapsed'

export default function PortalLayout() {
  const { user, logout, renewPhoto } = useAuth()
  const { countFor } = useNotifications()
  const navigate = useNavigate()
  const toast = useToast()
  const [mobileOpen, setMobileOpen] = React.useState(false)
  const [collapsed, toggleCollapsed] = useSidebarCollapsed(SIDEBAR_KEY)
  const closeMobile = React.useCallback(() => setMobileOpen(false), [])

  useEscapeToClose(mobileOpen, closeMobile)

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
  ]

  const settingsLink = { to: '/portal/settings', label: 'Settings', icon: SlidersHorizontal }
  const role = isEmployee ? 'Employee' : 'Applicant'

  async function handleLogout() {
    await logout()
    navigate('/login', { replace: true })
    toast.success('Signed out', 'Your session has ended.')
  }

  return (
    <ShellFrame
      mobileOpen={mobileOpen}
      onCloseMobile={closeMobile}
      mobileBar={
        <ShellMobileBar mobileOpen={mobileOpen} onToggle={() => setMobileOpen((open) => !open)} controls="portal-sidebar">
          <ThemeToggle className={headerIconButton} />
          <NotificationBell />
        </ShellMobileBar>
      }
      sidebar={
        <ShellSidebar
          id="portal-sidebar"
          label="Portal"
          subtitle={`${role} portal`}
          collapsed={collapsed}
          mobileOpen={mobileOpen}
          onToggleCollapsed={toggleCollapsed}
          footer={
            <ul className="space-y-0.5">
              <SidebarLink {...settingsLink} collapsed={collapsed} onNavigate={closeMobile} />
              <SidebarAction icon={LogOut} label="Log out" onClick={handleLogout} collapsed={collapsed} />
            </ul>
          }
        >
          <SidebarGroup label="Portal">
            {links.map((link) => (
              <SidebarLink
                key={link.to}
                {...link}
                unread={countFor(link.categories)}
                collapsed={collapsed}
                onNavigate={closeMobile}
              />
            ))}
          </SidebarGroup>
        </ShellSidebar>
      }
    >
      <div className="px-4 pb-8 pt-2 sm:px-6 lg:px-0 lg:pb-4 lg:pt-0">
        <ShellHeader>
          <ThemeToggle className={headerIconButton} />
          <NotificationBell />
          <ProfileMenu
            name={user?.full_name}
            subtitle={role}
            photoUrl={user?.photo_url}
            onPhotoError={renewPhoto}
            details={[role]}
            items={[
              { to: '/portal/profile', label: 'Profile', icon: UserRound },
              { to: settingsLink.to, label: 'Settings', icon: SlidersHorizontal },
            ]}
            onLogout={handleLogout}
          />
        </ShellHeader>

        {/*
          Narrower than the staff system on purpose. An applicant is reading
          their own single record, and a line of text spanning a wide monitor
          is harder to read, not more informative.
        */}
        <div className="mx-auto max-w-3xl">
          {/* Pages load on demand; the portal frame stays put while one arrives. */}
          <React.Suspense fallback={<LoadingState label="Loading…" />}>
            <Outlet />
          </React.Suspense>
        </div>

        <footer className="mx-auto max-w-3xl pb-4 pt-8">
          <p className="text-center text-xs text-muted-foreground">
            Questions about your application? Contact the CDE Manpower Services office in Sta.
            Cruz, Laguna.
          </p>
        </footer>
      </div>
    </ShellFrame>
  )
}
