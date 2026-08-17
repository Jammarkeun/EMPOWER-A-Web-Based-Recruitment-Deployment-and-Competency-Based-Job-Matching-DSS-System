import * as React from 'react'
import { Routes, Route, Navigate, useLocation, Link } from 'react-router-dom'
import { Loader2, ShieldAlert } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import AppLayout from '@/components/AppLayout'
import { Button } from '@/components/ui/button'

import Landing from '@/pages/Landing'
import Login from '@/pages/Login'
import Register from '@/pages/Register'
import Dashboard from '@/pages/Dashboard'
import Applicants from '@/pages/Applicants'
import ApplicantDetail from '@/pages/ApplicantDetail'
import JobRequests from '@/pages/JobRequests'
import JobRequestDetail from '@/pages/JobRequestDetail'
import Clients from '@/pages/Clients'
import ClientDetail from '@/pages/ClientDetail'
import Employees from '@/pages/Employees'
import EmployeeDetail from '@/pages/EmployeeDetail'
import Deployments from '@/pages/Deployments'
import Trainings from '@/pages/Trainings'
import Separations from '@/pages/Separations'
import Archives from '@/pages/Archives'
import AuditLogs from '@/pages/AuditLogs'
import Reports from '@/pages/Reports'
import Users from '@/pages/Users'
import Settings from '@/pages/Settings'

import PortalLayout from '@/pages/portal/PortalLayout'
import PortalOverview from '@/pages/portal/PortalOverview'
import PortalDocuments from '@/pages/portal/PortalDocuments'
import PortalEmployment from '@/pages/portal/PortalEmployment'

export default function App() {
  return (
    <Routes>
      {/* Public front door. Signed-in visitors are redirected onward by the
          Landing component itself, so a bookmark of "/" behaves sensibly for
          both staff and portal users. */}
      <Route path="/" element={<Landing />} />
      <Route path="/login" element={<Login />} />

      {/* Applicant self-registration. Public by necessity — the people who need
          it do not have accounts yet. The record it creates is held before
          screening until an officer confirms identity at the office, so an open
          endpoint cannot put anyone into the recruitment pipeline. */}
      <Route path="/register" element={<Register />} />

      {/* Applicant and employee self-service. Kept as a separate tree with its
          own layout: a portal user should never land in an interface built for
          managing hundreds of records. */}
      <Route
        path="/portal"
        element={
          <RequireAuth>
            <RequirePortal>
              <PortalLayout />
            </RequirePortal>
          </RequireAuth>
        }
      >
        <Route index element={<PortalOverview />} />
        <Route path="documents" element={<PortalDocuments />} />
        <Route path="employment" element={<PortalEmployment />} />
      </Route>

      {/* Staff system. */}
      <Route
        element={
          <RequireAuth>
            <RequireStaff>
              <AppLayout />
            </RequireStaff>
          </RequireAuth>
        }
      >
        <Route path="/dashboard" element={<Guard permission="dashboard.view"><Dashboard /></Guard>} />

        <Route path="/job-requests" element={<Guard permission="job_requests.view"><JobRequests /></Guard>} />
        <Route path="/job-requests/:id" element={<Guard permission="job_requests.view"><JobRequestDetail /></Guard>} />

        <Route path="/applicants" element={<Guard permission="applicants.view"><Applicants /></Guard>} />
        <Route path="/applicants/:id" element={<Guard permission="applicants.view"><ApplicantDetail /></Guard>} />

        <Route path="/trainings" element={<Guard permission="training.view"><Trainings /></Guard>} />

        <Route path="/deployments" element={<Guard permission="deployment.view"><Deployments /></Guard>} />
        <Route path="/employees" element={<Guard permission="employees.view"><Employees /></Guard>} />
        <Route path="/employees/:id" element={<Guard permission="employees.view"><EmployeeDetail /></Guard>} />
        <Route path="/separations" element={<Guard permission="separation.view"><Separations /></Guard>} />

        <Route path="/clients" element={<Guard permission="clients.view"><Clients /></Guard>} />
        <Route path="/clients/:id" element={<Guard permission="clients.view"><ClientDetail /></Guard>} />

        <Route path="/reports" element={<Guard permission="reports.view"><Reports /></Guard>} />

        <Route path="/archives" element={<Guard permission="archives.view"><Archives /></Guard>} />
        <Route path="/audit-logs" element={<Guard permission="audit.view"><AuditLogs /></Guard>} />
        <Route path="/users" element={<Guard permission="users.view"><Users /></Guard>} />

        {/* Settings is reachable by every signed-in user: the My Account tab
            belongs to them regardless of role. The system tabs inside are
            gated separately on settings.view. */}
        <Route path="/settings" element={<Settings />} />

        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  )
}

/**
 * Sends unauthenticated visitors to the login screen, remembering where they
 * were headed so they land there after signing in rather than on the dashboard.
 */
function RequireAuth({ children }) {
  const { isAuthenticated, loading } = useAuth()
  const location = useLocation()

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" state={{ from: location.pathname }} replace />
  }

  return children
}

/** Keeps portal accounts out of the staff system and sends them to their own. */
function RequireStaff({ children }) {
  const { user } = useAuth()

  if (user && ['applicant', 'employee'].includes(user.user_type)) {
    return <Navigate to="/portal" replace />
  }

  return children
}

/** Keeps staff out of the portal, which resolves records from the signed-in user. */
function RequirePortal({ children }) {
  const { user } = useAuth()

  if (user && !['applicant', 'employee'].includes(user.user_type)) {
    return <Navigate to="/dashboard" replace />
  }

  return children
}

/**
 * Hides a route the user's role does not cover.
 *
 * Mirrors the sidebar filtering so a bookmarked or typed URL behaves the same as
 * a hidden menu item. The API enforces the same rule independently; this only
 * avoids showing a screen that would fail to load.
 */
function Guard({ permission, children }) {
  const { can } = useAuth()

  if (!can(permission)) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-20 text-center">
        <div className="rounded-full bg-destructive/10 p-3">
          <ShieldAlert className="h-6 w-6 text-destructive" />
        </div>
        <div className="space-y-1">
          <p className="text-sm font-medium">You do not have access to this section</p>
          <p className="max-w-sm text-sm text-muted-foreground">
            Your role does not include the required permission. Contact the system administrator if
            you believe this is an error.
          </p>
        </div>
        <Button variant="outline" size="sm" asChild>
          <Link to="/dashboard">Return to dashboard</Link>
        </Button>
      </div>
    )
  }

  return children
}

function NotFound() {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-20 text-center">
      <p className="text-3xl font-semibold text-muted-foreground">404</p>
      <p className="text-sm text-muted-foreground">This page does not exist.</p>
      <Button variant="outline" size="sm" asChild>
        <Link to="/dashboard">Return to dashboard</Link>
      </Button>
    </div>
  )
}
