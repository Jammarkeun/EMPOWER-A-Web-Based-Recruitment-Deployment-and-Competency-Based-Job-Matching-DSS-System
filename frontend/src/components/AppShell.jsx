import * as React from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import * as DropdownMenu from '@radix-ui/react-dropdown-menu'
import { ChevronDown, LogOut, Menu, PanelLeft, X } from 'lucide-react'
import { LogoMark } from '@/components/Logo'
import PersonAvatar from '@/components/PersonAvatar'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

/**
 * The application chrome shared by the staff system and the applicant portal:
 * a floating sidebar panel, a header of two trays (search on the left, the
 * user's own controls on the right), and the content between them.
 *
 * Each layout keeps its own navigation, state, and sign-out; this file only
 * decides how those look. `collapsed` applies from `lg` up and is expressed
 * entirely through `lg:` classes, so a sidebar collapsed on a laptop never
 * turns the phone drawer into a column of unlabelled icons.
 */

/**
 * Whether the desktop sidebar is collapsed to an icon rail.
 *
 * Remembered across reloads because it is a working preference rather than a
 * transient view state. Wrapped in try/catch: localStorage throws outright in a
 * private window with site data blocked, and a sidebar preference is never
 * worth breaking the layout over.
 */
export function useSidebarCollapsed(storageKey) {
  const [collapsed, setCollapsed] = React.useState(() => {
    try {
      return localStorage.getItem(storageKey) === '1'
    } catch {
      return false
    }
  })

  const toggle = React.useCallback(() => {
    setCollapsed((current) => {
      const next = !current
      try {
        localStorage.setItem(storageKey, next ? '1' : '0')
      } catch {
        // The preference simply will not persist; the toggle still works.
      }
      return next
    })
  }, [storageKey])

  return [collapsed, toggle]
}

/** Closes the mobile drawer on Escape while it is open. */
export function useEscapeToClose(open, close) {
  React.useEffect(() => {
    if (!open) return undefined
    function onKeyDown(event) {
      if (event.key === 'Escape') close()
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [open, close])
}

/** The page frame: mobile bar, sidebar, and the main column. */
export function ShellFrame({ mobileBar, sidebar, mobileOpen, onCloseMobile, children }) {
  return (
    <div className="min-h-screen bg-background font-sans text-foreground">
      {mobileBar}

      <div className="flex lg:gap-6 lg:p-4">
        {mobileOpen && (
          <button
            type="button"
            className="fixed inset-0 z-30 bg-navy-900/30 backdrop-blur-xs lg:hidden"
            aria-label="Close navigation"
            onClick={onCloseMobile}
          />
        )}
        {sidebar}
        <main className="min-w-0 flex-1">{children}</main>
      </div>
    </div>
  )
}

/** The bar a phone sees in place of the sidebar and the header trays. */
export function ShellMobileBar({ mobileOpen, onToggle, controls, children }) {
  return (
    <header className="no-print sticky top-0 z-40 flex items-center justify-between gap-2 bg-background/90 px-4 py-3 backdrop-blur lg:hidden">
      <div className="flex min-w-0 items-center gap-2">
        <LogoMark size="xs" />
        <span className="text-lg font-bold tracking-tight">EMPOWER</span>
      </div>
      <div className="flex items-center gap-1.5">
        {children}
        <Button
          variant="ghost"
          size="icon"
          className="bg-card"
          onClick={onToggle}
          aria-label={mobileOpen ? 'Close navigation' : 'Open navigation'}
          aria-expanded={mobileOpen}
          aria-controls={controls}
        >
          {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
        </Button>
      </div>
    </header>
  )
}

/** The floating sidebar panel. Navigation goes in children, the bottom group in footer. */
export function ShellSidebar({ id, label, subtitle, collapsed, mobileOpen, onToggleCollapsed, footer, children }) {
  return (
    <aside
      id={id}
      className={cn(
        'no-print scrollbar-none shrink-0 rounded-panel bg-sidebar text-sidebar-foreground transition-[width] duration-200 ease-out',
        'lg:sticky lg:top-4 lg:block lg:h-[calc(100vh-2rem)] lg:w-60',
        collapsed && 'lg:w-[76px]',
        mobileOpen ? 'fixed bottom-3 left-3 top-[76px] z-40 block w-72 overflow-y-auto shadow-float' : 'hidden'
      )}
    >
      <div className="flex h-full flex-col px-3 py-6">
        <div
          className={cn(
            'mb-9 flex items-center justify-between gap-2 pl-2.5 pr-1',
            collapsed && 'lg:flex-col lg:gap-3 lg:px-0'
          )}
        >
          <div className="flex min-w-0 items-center gap-2.5">
            <LogoMark size="sm" plain className="shrink-0" />
            <div className={cn('min-w-0 leading-none', collapsed && 'lg:hidden')}>
              <p className="text-[21px] font-bold tracking-tight text-white">EMPOWER</p>
              {subtitle && <p className="mt-1 truncate text-[11px] text-sidebar-muted/90">{subtitle}</p>}
            </div>
          </div>
          <button
            type="button"
            className="hidden h-8 w-8 shrink-0 items-center justify-center rounded-full text-sidebar-muted transition-colors hover:bg-sidebar-hover hover:text-white lg:flex"
            onClick={onToggleCollapsed}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            <PanelLeft className="h-4 w-4" />
          </button>
        </div>

        <nav className="scrollbar-none -mx-1 flex-1 space-y-4 overflow-y-auto px-1" aria-label={label}>
          {children}
        </nav>

        {footer && <div className="mt-4 space-y-0.5 pt-2">{footer}</div>}
      </div>
    </aside>
  )
}

/** A named group of sidebar links. The name is for screen readers; the gap shows it. */
export function SidebarGroup({ label, children }) {
  return (
    <ul aria-label={label} className="space-y-0.5">
      {children}
    </ul>
  )
}

const ITEM_CLASSES = 'group relative flex h-11 w-full items-center gap-2.5 rounded-full pl-1.5 pr-3 text-sm transition-colors'

/**
 * A sidebar link. The current page is a pill in the active navy whose icon sits
 * in its own lighter circle; everything else is soft white text on the panel.
 */
export function SidebarLink({ to, end, icon: Icon, label, unread = 0, collapsed, onNavigate }) {
  return (
    <li>
      <NavLink
        to={to}
        end={end}
        onClick={onNavigate}
        title={collapsed ? label : undefined}
        className={({ isActive }) =>
          cn(
            ITEM_CLASSES,
            collapsed && 'lg:justify-center lg:px-0',
            isActive
              ? 'bg-sidebar-active font-semibold text-white'
              : 'text-sidebar-foreground/80 hover:bg-sidebar-hover hover:text-white'
          )
        }
      >
        {({ isActive }) => (
          <>
            <span
              className={cn(
                'relative flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition-colors',
                isActive ? 'bg-white/15 text-white' : 'text-sidebar-muted group-hover:text-white'
              )}
            >
              <Icon className="h-4 w-4" strokeWidth={isActive ? 2.25 : 2} />
              {unread > 0 && collapsed && (
                <span className="absolute right-0.5 top-0.5 hidden h-2 w-2 rounded-full bg-brand lg:block" />
              )}
            </span>

            <span className={cn('truncate', collapsed && 'lg:hidden')}>{label}</span>

            {unread > 0 && (
              <span
                className={cn(
                  'ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-brand/30 px-1.5 text-[11px] font-semibold tabular-nums text-blue-200',
                  collapsed && 'lg:hidden'
                )}
                aria-label={`${unread} unread`}
              >
                {unread > 9 ? '9+' : unread}
              </span>
            )}
          </>
        )}
      </NavLink>
    </li>
  )
}

/** A sidebar row that runs an action instead of navigating, such as signing out. */
export function SidebarAction({ icon: Icon, label, onClick, collapsed }) {
  return (
    <li>
      <button
        type="button"
        onClick={onClick}
        title={collapsed ? label : undefined}
        className={cn(
          ITEM_CLASSES,
          'text-sidebar-foreground/80 hover:bg-sidebar-hover hover:text-white',
          collapsed && 'lg:justify-center lg:px-0'
        )}
      >
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sidebar-muted group-hover:text-white">
          <Icon className="h-4 w-4" />
        </span>
        <span className={cn('truncate', collapsed && 'lg:hidden')}>{label}</span>
      </button>
    </li>
  )
}

/**
 * The desktop header: a search tray on the left and the user's tray on the
 * right. It stays pinned while the page scrolls beneath it.
 */
export function ShellHeader({ search, children }) {
  return (
    <div className="no-print sticky top-0 z-30 -mt-4 hidden items-center justify-between gap-4 bg-background/85 pb-6 pt-4 backdrop-blur lg:flex">
      {search && <div className="rounded-[22px] bg-card/55 p-1.5">{search}</div>}
      <div className="ml-auto flex items-center gap-1.5 rounded-full bg-card/55 p-1.5">{children}</div>
    </div>
  )
}

/** Classes for a round white icon button in the header tray. */
export const headerIconButton = 'h-10 w-10 rounded-full bg-card text-muted-foreground hover:bg-card hover:text-foreground'


/**
 * Who is signed in, and the way to their account and out of the system.
 *
 * The subtitle stays visible in the header rather than inside the menu, so a
 * coordinator always sees which client they are acting for.
 *
 * `photoUrl` is the person's own profile picture, where they have one;
 * `onPhotoError` lets the caller fetch a fresh link when a signed one expires.
 */
export function ProfileMenu({ name, subtitle, photoUrl, onPhotoError, details = [], items = [], onLogout }) {
  const navigate = useNavigate()

  return (
    <DropdownMenu.Root modal={false}>
      <DropdownMenu.Trigger asChild>
        <button
          type="button"
          className="flex items-center gap-2.5 rounded-full py-0.5 pl-0.5 pr-2.5 text-left transition-colors hover:bg-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          aria-label={`Account menu for ${name ?? 'your account'}`}
        >
          <PersonAvatar src={photoUrl} name={name} size="sm" onError={onPhotoError} />
          <span className="hidden min-w-0 leading-tight sm:block">
            <span className="block max-w-[11rem] truncate text-[13px] font-medium text-foreground">{name}</span>
            {subtitle && (
              <span className="block max-w-[11rem] truncate text-xs text-muted-foreground" title={subtitle}>
                {subtitle}
              </span>
            )}
          </span>
          <ChevronDown className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
        </button>
      </DropdownMenu.Trigger>

      <DropdownMenu.Portal>
        <DropdownMenu.Content
          align="end"
          sideOffset={10}
          className="z-50 w-64 rounded-row border border-border/70 bg-popover p-1.5 text-popover-foreground shadow-float animate-fade-in"
        >
          <div className="px-3 pb-2.5 pt-2">
            <p className="truncate text-sm font-medium">{name}</p>
            {details.filter(Boolean).map((detail) => (
              <p key={detail} className="truncate text-xs text-muted-foreground">
                {detail}
              </p>
            ))}
          </div>
          <DropdownMenu.Separator className="mx-1.5 my-1 h-px bg-border" />

          {items.map((item) => (
            <DropdownMenu.Item
              key={item.to}
              onSelect={() => navigate(item.to)}
              className="flex h-10 cursor-pointer select-none items-center gap-2.5 rounded-[10px] px-3 text-sm outline-none data-[highlighted]:bg-inset"
            >
              <item.icon className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
              {item.label}
            </DropdownMenu.Item>
          ))}

          <DropdownMenu.Item
            onSelect={onLogout}
            className="flex h-10 cursor-pointer select-none items-center gap-2.5 rounded-[10px] px-3 text-sm outline-none data-[highlighted]:bg-inset"
          >
            <LogOut className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
            Log out
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Portal>
    </DropdownMenu.Root>
  )
}
