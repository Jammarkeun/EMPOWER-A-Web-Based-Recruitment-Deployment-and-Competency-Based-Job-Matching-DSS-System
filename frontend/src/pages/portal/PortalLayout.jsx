import * as React from 'react'
import { Outlet, useNavigate } from 'react-router-dom'
import { LogOut, FileText, Home, Briefcase, UserRound, SlidersHorizontal, GraduationCap } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import NotificationBell from '@/components/NotificationBell'
import ThemeToggle from '@/components/ThemeToggle'
import { useNotifications, useMarkSeenOnVisit } from '@/contexts/NotificationsContext'
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
  const { countsFor } = useNotifications()
  const navigate = useNavigate()
  const toast = useToast()
  const [mobileOpen, setMobileOpen] = React.useState(false)
  const [collapsed, toggleCollapsed] = useSidebarCollapsed(SIDEBAR_KEY)
  const closeMobile = React.useCallback(() => setMobileOpen(false), [])

  useEscapeToClose(mobileOpen, closeMobile)

  // From the employment record behind the login, not the account type: a login
  // made at registration stays "applicant" after its owner is deployed.
  const isEmployee = Boolean(user?.employment?.has_record) || user?.user_type === 'employee'

  /*
   * Each section carries its own unread count, not the global one.
   *
   * A notification is counted on the section for the page it opens (see
   * countsFor), so news about documents shows on "My documents" and a
   * placement on "My employment" once there is one - before then it lands on
   * "My application", which covers every portal page beneath it. The
   * categories are the fallback for a notification with no page.
   */
  const links = [
    { to: '/portal', label: 'My application', icon: Home, end: true, categories: ['application', 'deployment'] },
    { to: '/portal/documents', label: 'My documents', icon: FileText, categories: ['requirements'] },
    { to: '/portal/training', label: 'My training', icon: GraduationCap, categories: ['training'] },
    ...(isEmployee
      ? [{ to: '/portal/employment', label: 'My employment', icon: Briefcase, categories: ['employment'] }]
      : []),
    { to: '/portal/profile', label: 'Profile', icon: UserRound, categories: [] },
  ]

  const settingsLink = { to: '/portal/settings', label: 'Settings', icon: SlidersHorizontal }
  const role = isEmployee ? 'Employee' : 'Applicant'
  const unreadByLink = countsFor(links)
  // Opening a section's page clears its number: it has been seen.
  useMarkSeenOnVisit(links)

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
                unread={unreadByLink.get(link.to) ?? 0}
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
