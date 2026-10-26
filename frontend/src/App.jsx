import * as React from 'react'
import { Routes, Route, Navigate, useLocation, Link } from 'react-router-dom'
import { Loader2, ShieldAlert } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { homePathFor } from '@/lib/utils'
import { NotificationsProvider } from '@/contexts/NotificationsContext'
import { Button } from '@/components/ui/button'
import SessionTimeout from '@/components/SessionTimeout'

/*
 * The two public entry points load with the app. Everything else loads when
 * it is first opened (audit PERF-01): an applicant registering on a phone used
 * to download the whole staff system, the coordinator workspace and Chart.js
 * before the form appeared. The layouts are split too, so a portal user never
 * downloads the staff shell and the other way round.
 */
import Landing from '@/pages/Landing'
import Login from '@/pages/Login'

const AppLayout = React.lazy(() => import('@/components/AppLayout'))
const Register = React.lazy(() => import('@/pages/Register'))
const ForgotPassword = React.lazy(() => import('@/pages/ForgotPassword'))
const ResetPassword = React.lazy(() => import('@/pages/ResetPassword'))
const ChangePasswordRequired = React.lazy(() => import('@/pages/ChangePasswordRequired'))
const Dashboard = React.lazy(() => import('@/pages/Dashboard'))
const Applicants = React.lazy(() => import('@/pages/Applicants'))
const ApplicantDetail = React.lazy(() => import('@/pages/ApplicantDetail'))
const JobRequests = React.lazy(() => import('@/pages/JobRequests'))
const JobRequestDetail = React.lazy(() => import('@/pages/JobRequestDetail'))
const Clients = React.lazy(() => import('@/pages/Clients'))
const ClientDetail = React.lazy(() => import('@/pages/ClientDetail'))
const DepartmentDetail = React.lazy(() => import('@/pages/DepartmentDetail'))
const Employees = React.lazy(() => import('@/pages/Employees'))
const EmployeeDetail = React.lazy(() => import('@/pages/EmployeeDetail'))
const Deployments = React.lazy(() => import('@/pages/Deployments'))
const Trainings = React.lazy(() => import('@/pages/Trainings'))
const TrainingDetail = React.lazy(() => import('@/pages/TrainingDetail'))
const Separations = React.lazy(() => import('@/pages/Separations'))
const Archives = React.lazy(() => import('@/pages/Archives'))
const Retention = React.lazy(() => import('@/pages/Retention'))
const AuditLogs = React.lazy(() => import('@/pages/AuditLogs'))
const Reports = React.lazy(() => import('@/pages/Reports'))
const Documents = React.lazy(() => import('@/pages/Documents'))
const Attendance = React.lazy(() => import('@/pages/Attendance'))
const ViolationReports = React.lazy(() => import('@/pages/ViolationReports'))
const ViolationReportReview = React.lazy(() => import('@/pages/ViolationReportReview'))
const CoordinatorDashboard = React.lazy(() => import('@/pages/coordinator/CoordinatorDashboard'))
const CoordinatorCompany = React.lazy(() => import('@/pages/coordinator/CoordinatorCompany'))
const CoordinatorEmployees = React.lazy(() => import('@/pages/coordinator/CoordinatorEmployees'))
const CoordinatorEmployeeDetail = React.lazy(() => import('@/pages/coordinator/CoordinatorEmployeeDetail'))
const CoordinatorDeployments = React.lazy(() => import('@/pages/coordinator/CoordinatorDeployments'))
const CoordinatorReports = React.lazy(() => import('@/pages/coordinator/CoordinatorReports'))
const CoordinatorReport = React.lazy(() => import('@/pages/coordinator/CoordinatorReport'))
const CoordinatorRequests = React.lazy(() => import('@/pages/coordinator/CoordinatorRequests'))
const Users = React.lazy(() => import('@/pages/Users'))
const Settings = React.lazy(() => import('@/pages/Settings'))

const PortalLayout = React.lazy(() => import('@/pages/portal/PortalLayout'))
const PortalOverview = React.lazy(() => import('@/pages/portal/PortalOverview'))
const PortalDocuments = React.lazy(() => import('@/pages/portal/PortalDocuments'))
const PortalEmployment = React.lazy(() => import('@/pages/portal/PortalEmployment'))
const PortalProfile = React.lazy(() => import('@/pages/portal/PortalProfile'))
const PortalSettings = React.lazy(() => import('@/pages/portal/PortalSettings'))

/** Shown while a layout or a public page is first downloaded. */
function FullPageLoader() {
  return (
    <div className="flex min-h-screen items-center justify-center" role="status">
      <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" aria-hidden="true" />
      <span className="sr-only">Loading…</span>
    </div>
  )
}

export default function App() {
  const { isAuthenticated, user } = useAuth()

  return (
    /*
     * One source for every unread count on screen.
     *
     * Mounted here rather than inside each layout so the figure survives moving
     * between the staff system and the portal, and so it polls exactly once
     * regardless of how many badges are displayed. Disabled while signed out,
     * because an unauthenticated poll would be a 401 a minute.
     */
    <NotificationsProvider enabled={isAuthenticated && !user?.must_change_password}>
    {/* Warns before a sign-in ends and signs out cleanly once it has. Here
        rather than in a layout so it covers every signed-in page, the
        change-password screen included. */}
    {isAuthenticated && <SessionTimeout />}
    <React.Suspense fallback={<FullPageLoader />}>
    <Routes>
      {/* Public front door. Signed-in visitors are redirected onward by the
          Landing component itself, so a bookmark of "/" behaves sensibly for
          both staff and portal users. */}
      <Route path="/" element={<Landing />} />
      <Route path="/login" element={<Login />} />

      {/* Getting back into an account: through the office, or by an emailed
          link when the server can send one. */}
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />

      {/* The only page an account on an office-issued password can use. */}
      <Route
        path="/change-password"
        element={
          <RequireAuth>
            <ChangePasswordRequired />
          </RequireAuth>
        }
      />

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
        <Route path="profile" element={<PortalProfile />} />
        <Route path="settings" element={<PortalSettings />} />
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

        {/* The coordinator's workspace. Every screen reads from the
            coordinator API, which scopes to the assigned client on the
            server; the guard only spares staff a screen that would refuse
            them. */}
        <Route path="/coordinator" element={<Guard permission="coordination.access"><CoordinatorDashboard /></Guard>} />
        <Route path="/coordinator/company" element={<Guard permission="coordination.access"><CoordinatorCompany /></Guard>} />
        <Route path="/coordinator/employees" element={<Guard permission="coordination.access"><CoordinatorEmployees /></Guard>} />
        <Route path="/coordinator/employees/:id" element={<Guard permission="coordination.access"><CoordinatorEmployeeDetail /></Guard>} />
        <Route path="/coordinator/deployments" element={<Guard permission="coordination.access"><CoordinatorDeployments /></Guard>} />
        <Route path="/coordinator/reports" element={<Guard permission="coordination.access"><CoordinatorReports /></Guard>} />
        <Route path="/coordinator/reports/new" element={<Guard permission="coordination.access"><CoordinatorReport /></Guard>} />
        <Route path="/coordinator/reports/:id" element={<Guard permission="coordination.access"><CoordinatorReport /></Guard>} />
        <Route path="/coordinator/requests" element={<Guard permission="coordination.access"><CoordinatorRequests /></Guard>} />

        <Route path="/job-requests" element={<Guard permission="job_requests.view"><JobRequests /></Guard>} />
        <Route path="/job-requests/:id" element={<Guard permission="job_requests.view"><JobRequestDetail /></Guard>} />

        <Route path="/applicants" element={<Guard permission="applicants.view"><Applicants /></Guard>} />
        <Route path="/applicants/:id" element={<Guard permission="applicants.view"><ApplicantDetail /></Guard>} />

        <Route path="/trainings" element={<Guard permission="training.view"><Trainings /></Guard>} />
        <Route path="/trainings/:id" element={<Guard permission="training.view"><TrainingDetail /></Guard>} />

        <Route path="/deployments" element={<Guard permission="deployment.view"><Deployments /></Guard>} />
        <Route path="/employees" element={<Guard permission="employees.view"><Employees /></Guard>} />
        <Route path="/employees/:id" element={<Guard permission="employees.view"><EmployeeDetail /></Guard>} />
        <Route path="/attendance" element={<Guard permission="attendance.view"><Attendance /></Guard>} />
        <Route path="/violation-reports" element={<Guard permission="violation_reports.view"><ViolationReports /></Guard>} />
        <Route path="/violation-reports/:id" element={<Guard permission="violation_reports.view"><ViolationReportReview /></Guard>} />
        <Route path="/separations" element={<Guard permission="separation.view"><Separations /></Guard>} />

        <Route path="/clients" element={<Guard permission="clients.view"><Clients /></Guard>} />
        <Route path="/clients/:id" element={<Guard permission="clients.view"><ClientDetail /></Guard>} />

        {/* One client's department, and the people placed in it. Gated on
            employees.view rather than clients.view: seeing a company is not the
            same as seeing the names and employment status of its workers, and
            the API draws the line in the same place. */}
        <Route
          path="/clients/:id/departments/:departmentId"
          element={<Guard permission="employees.view"><DepartmentDetail /></Guard>}
        />

        <Route path="/reports" element={<Guard permission="reports.view"><Reports /></Guard>} />

        {/* The document library. Gated on applicants.view because that is whose
            documents these are; anyone who may not see an applicant has no
            business browsing their papers by type either. */}
        <Route path="/documents" element={<Guard permission="applicants.view"><Documents /></Guard>} />

        <Route path="/archives" element={<Guard permission="archives.view"><Archives /></Guard>} />
        <Route path="/retention" element={<Guard permission="archives.view"><Retention /></Guard>} />
        <Route path="/audit-logs" element={<Guard permission="audit.view"><AuditLogs /></Guard>} />
        <Route path="/users" element={<Guard permission="users.view"><Users /></Guard>} />

        {/* Settings is reachable by every signed-in user: the My Account tab
            belongs to them regardless of role. The system tabs inside are
            gated separately on settings.view. */}
        <Route path="/settings" element={<Settings />} />

        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
    </React.Suspense>
    </NotificationsProvider>
  )
}

/**
 * Sends unauthenticated visitors to the login screen, remembering where they
 * were headed so they land there after signing in rather than on the dashboard.
 */
function RequireAuth({ children }) {
  const { isAuthenticated, loading, user } = useAuth()
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

  // The API refuses everything else for this account until it is done.
  if (user?.must_change_password && location.pathname !== '/change-password') {
    return <Navigate to="/change-password" replace />
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
    return <Navigate to={homePathFor(user)} replace />
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
  const { can, user } = useAuth()

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
          <Link to={homePathFor(user)}>Return to dashboard</Link>
        </Button>
      </div>
    )
  }

  return children
}

function NotFound() {
  const { user } = useAuth()

  return (
    <div className="flex flex-col items-center justify-center gap-3 py-20 text-center">
      <p className="text-3xl font-semibold text-muted-foreground">404</p>
      <p className="text-sm text-muted-foreground">This page does not exist.</p>
      <Button variant="outline" size="sm" asChild>
        <Link to={homePathFor(user)}>Return to dashboard</Link>
      </Button>
    </div>
  )
}
