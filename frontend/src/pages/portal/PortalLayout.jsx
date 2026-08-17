import * as React from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { LogOut, FileText, Home, Briefcase } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/components/ui/toast'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import NotificationBell from '@/components/NotificationBell'
import ThemeToggle from '@/components/ThemeToggle'
import { LogoMark } from '@/components/Logo'

/**
 * Shell for the applicant and employee portal.
 *
 * Deliberately a different layout from the staff system rather than the same
 * one with items hidden. An applicant checking their application should not be
 * looking at an interface built for managing hundreds of records, and the
 * visual difference makes it obvious at a glance which side of the system you
 * are on.
 */
export default function PortalLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const toast = useToast()

  const isEmployee = user?.user_type === 'employee'

  const links = [
    { to: '/portal', label: 'My application', icon: Home, end: true },
    { to: '/portal/documents', label: 'My documents', icon: FileText },
    ...(isEmployee ? [{ to: '/portal/employment', label: 'My employment', icon: Briefcase }] : []),
  ]

  async function handleLogout() {
    await logout()
    navigate('/login', { replace: true })
    toast.success('Signed out', 'Your session has ended.')
  }

  return (
    <div className="min-h-screen bg-muted/30">
      <header className="border-b bg-card">
        <div className="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3">
          <div className="flex items-center gap-2.5">
            <LogoMark size="sm" />
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold leading-tight">EMPOWER</p>
              <p className="truncate text-xs text-muted-foreground">CDE Manpower Services</p>
            </div>
          </div>

          <div className="flex items-center gap-1">
            <ThemeToggle />
            <NotificationBell />
            <Button variant="ghost" size="sm" onClick={handleLogout}>
              <LogOut className="h-4 w-4" />
              <span className="hidden sm:inline">Sign out</span>
            </Button>
          </div>
        </div>

        <nav className="mx-auto max-w-3xl px-4" aria-label="Portal">
          <ul className="flex gap-1 overflow-x-auto">
            {links.map((link) => (
              <li key={link.to}>
                <NavLink
                  to={link.to}
                  end={link.end}
                  className={({ isActive }) =>
                    cn(
                      'flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm transition-colors',
                      isActive
                        ? 'border-primary font-medium text-primary'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                    )
                  }
                >
                  <link.icon className="h-4 w-4" />
                  {link.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
      </header>

      <main className="mx-auto max-w-3xl px-4 py-6">
        <Outlet />
      </main>

      <footer className="mx-auto max-w-3xl px-4 pb-8 pt-2">
        <p className="text-center text-xs text-muted-foreground">
          Questions about your application? Contact the CDE Manpower Services office in Sta. Cruz, Laguna.
        </p>
      </footer>
    </div>
  )
}
