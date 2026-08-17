import * as React from 'react'
import { Link, Navigate } from 'react-router-dom'
import {
  ArrowRight,
  Users,
  ClipboardList,
  FolderCheck,
  Scale,
  Truck,
  ScrollText,
  Lock,
  FileText,
  MapPin,
  UserPlus,
} from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { Button } from '@/components/ui/button'
import ThemeToggle from '@/components/ThemeToggle'
import { LogoMark } from '@/components/Logo'
import { cn } from '@/lib/utils'

/**
 * The front door.
 *
 * Written as an institutional page rather than a product landing page. This
 * system holds employment records and disciplinary files for a manpower agency;
 * the tone that fits is a government or professional-services site, not a
 * software marketing page with a gradient hero and testimonials.
 *
 * Its real job is routing. Two kinds of people arrive here — HR staff who work
 * in the system daily, and applicants who want to know whether they got the job
 * — and they need different doors. Everything below the fold exists to explain
 * the system to a third audience: the review panel.
 */
/*
 * The sections the header navigates between.
 *
 * Kept as data rather than repeated in the markup so the nav, the scroll-spy,
 * and the sections themselves cannot drift out of step — a nav item pointing at
 * an anchor that no longer exists is the classic way this breaks.
 */
const SECTIONS = [
  { id: 'capabilities', label: 'What it does' },
  { id: 'process', label: 'How it works' },
  { id: 'matching', label: 'Matching' },
]

export default function Landing() {
  const { isAuthenticated, user } = useAuth()

  // Anyone already signed in is sent straight to their own side of the system.
  if (isAuthenticated) {
    const isPortal = ['applicant', 'employee'].includes(user?.user_type)
    return <Navigate to={isPortal ? '/portal' : '/'} replace />
  }

  return (
    <div className="min-h-screen bg-background">
      <SiteHeader />
      <Hero />
      <Capabilities />
      <Process />
      <DecisionSupport />
      <SiteFooter />
    </div>
  )
}

/**
 * Tracks which section is currently in view, for the header nav.
 *
 * Uses an observer rather than a scroll handler: a scroll listener fires on
 * every frame and has to measure the whole page each time, which is the usual
 * reason a sticky nav feels sticky in the wrong sense.
 *
 * The top margin matches the header height so a section counts as "current"
 * when it reaches the underside of the header, not when it touches the top of
 * the window and is already hidden behind it.
 */
function useActiveSection(ids) {
  const [active, setActive] = React.useState(null)

  React.useEffect(() => {
    const elements = ids.map((id) => document.getElementById(id)).filter(Boolean)
    if (elements.length === 0) return

    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((entry) => entry.isIntersecting)
          .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)

        if (visible.length > 0) setActive(visible[0].target.id)
      },
      { rootMargin: '-72px 0px -55% 0px', threshold: 0 }
    )

    elements.forEach((element) => observer.observe(element))
    return () => observer.disconnect()
  }, [ids])

  return active
}

function SiteHeader() {
  const active = useActiveSection(React.useMemo(() => SECTIONS.map((s) => s.id), []))

  return (
    <header className="sticky top-0 z-40 border-b bg-background/85 backdrop-blur">
      <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-5 py-3">
        <a href="#top" className="flex items-center gap-2.5">
          <LogoMark size="sm" />
          <div className="leading-tight">
            <p className="text-sm font-semibold tracking-tight">EMPOWER</p>
            <p className="hidden text-xs text-muted-foreground sm:block">CDE Manpower Services</p>
          </div>
        </a>

        {/*
          Hidden below `md`. A row of anchors squeezed onto a phone competes
          with the two buttons that actually matter, and the page is short
          enough to scroll.
        */}
        <nav className="hidden md:flex md:items-center md:gap-1" aria-label="Page sections">
          {SECTIONS.map((section) => (
            <a
              key={section.id}
              href={`#${section.id}`}
              aria-current={active === section.id ? 'true' : undefined}
              className={cn(
                'relative rounded-md px-3 py-1.5 text-sm transition-colors',
                active === section.id
                  ? 'text-foreground'
                  : 'text-muted-foreground hover:text-foreground'
              )}
            >
              {section.label}
              {/* The indicator is a child of the active link rather than a
                  sliding element, so it cannot desync from what is highlighted. */}
              {active === section.id && (
                <span className="absolute inset-x-3 -bottom-px h-0.5 rounded-full bg-primary" />
              )}
            </a>
          ))}
        </nav>

        <div className="flex items-center gap-1.5">
          <ThemeToggle />
          <Button variant="ghost" size="sm" asChild>
            <Link to="/login">Sign in</Link>
          </Button>
          <Button size="sm" asChild>
            <Link to="/register">
              Apply now
              <ArrowRight className="h-3.5 w-3.5" />
            </Link>
          </Button>
        </div>
      </div>
    </header>
  )
}

function Hero() {
  return (
    <section id="top" className="radial-fade border-b scroll-mt-20">
      <div className="mx-auto max-w-6xl px-5 py-16 sm:py-24">
        <div className="mx-auto max-w-3xl text-center">
          <p className="mb-4 inline-flex items-center gap-1.5 rounded-full border bg-card px-3 py-1 text-xs font-medium text-muted-foreground">
            <MapPin className="h-3 w-3" />
            Sta. Cruz, Laguna
          </p>

          <h1 className="text-balance text-3xl font-semibold tracking-tight sm:text-5xl">
            Recruitment and deployment,
            <br className="hidden sm:block" /> on one record
          </h1>

          <p className="mx-auto mt-5 max-w-xl text-balance text-base text-muted-foreground sm:text-lg">
            EMPOWER manages the full employment lifecycle for CDE Manpower Services — from an
            applicant walking into the office through to their deployment, and every record in
            between.
          </p>
        </div>

        {/*
          Three doors, given equal weight. Job seekers are by far the largest
          group arriving here and the only one who cannot get in without a route
          of their own, so applying is first and marked as the primary action.
          An applicant checking their status is not a lesser visitor than an HR
          officer, and burying their route in a footer link is how a portal ends
          up unused.
        */}
        <div className="mx-auto mt-11 grid max-w-4xl gap-4 sm:grid-cols-2 md:grid-cols-3">
          <EntryCard
            icon={UserPlus}
            title="Applying for work"
            description="Register, see exactly which documents to bring, and get your reference number."
            action="Start my application"
            to="/register"
            primary
          />
          <EntryCard
            icon={FileText}
            title="Already applied"
            description="Check your application status, documents, and employment details."
            action="Open the portal"
            to="/login"
          />
          <EntryCard
            icon={Users}
            title="Agency staff"
            description="Manage applicants, requests, deployments, and employee records."
            action="Sign in"
            to="/login"
          />
        </div>

        <p className="mt-6 text-center text-xs text-muted-foreground">
          Registering online reserves your reference number. You still need to visit the office in
          person to submit your documents.
        </p>
      </div>
    </section>
  )
}

function EntryCard({ icon: Icon, title, description, action, to, primary }) {
  return (
    <Link
      to={to}
      className="group flex flex-col rounded-xl border bg-card p-5 text-left shadow-sm transition-all hover:border-primary/40 hover:shadow-md focus-visible:border-primary/40"
    >
      <div
        className={
          primary
            ? 'mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-primary text-primary-foreground'
            : 'mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-secondary text-secondary-foreground'
        }
      >
        <Icon className="h-5 w-5" />
      </div>

      <p className="font-medium">{title}</p>
      <p className="mt-1 flex-1 text-sm text-muted-foreground">{description}</p>

      <span className="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary">
        {action}
        <ArrowRight className="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" />
      </span>
    </Link>
  )
}

/*
 * Written against the problems the agency actually described, not as a feature
 * list. Each item names what it replaces, because "centralised records" means
 * nothing next to "records live in folders and three spreadsheets".
 */
const CAPABILITIES = [
  {
    icon: ClipboardList,
    title: 'Manpower requests',
    body: 'Requests from client companies recorded against a department, with headcount tracked as workers are deployed.',
  },
  {
    icon: FolderCheck,
    title: 'The folder system, digitally',
    body: 'The agency’s Folder 1, 2, and 3 filing, maintained automatically from the documents actually verified. No manual re-filing.',
  },
  {
    icon: Users,
    title: 'Applicant lifecycle',
    body: 'Application through screening, requirements, training, and client evaluation, with every status change dated and attributed.',
  },
  {
    icon: Truck,
    title: 'Deployment and employment',
    body: 'An applicant becomes an employee without losing their recruitment history, and their placements are tracked over time.',
  },
  {
    icon: Lock,
    title: 'Secure documents',
    body: 'Clearances and medical results held in private storage, reachable only through links that expire within minutes.',
  },
  {
    icon: ScrollText,
    title: 'Full audit trail',
    body: 'Every consequential action recorded with who performed it and what changed — as the Data Privacy Act expects.',
  },
]

function Capabilities() {
  return (
    <section id="capabilities" className="border-b scroll-mt-16">
      <div className="mx-auto max-w-6xl px-5 py-16">
        <div className="max-w-2xl">
          <h2 className="text-2xl font-semibold tracking-tight">
            One system, replacing folders and spreadsheets
          </h2>
          <p className="mt-2 text-muted-foreground">
            Applicant records currently sit in physical folders, employee details across Excel and
            Google Sheets, and violations apart from both. EMPOWER brings them onto a single record.
          </p>
        </div>

        <div className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {CAPABILITIES.map((item) => (
            <div key={item.title} className="rounded-lg border bg-card p-5">
              <item.icon className="h-5 w-5 text-primary" />
              <p className="mt-3 font-medium">{item.title}</p>
              <p className="mt-1.5 text-sm text-muted-foreground">{item.body}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}

/*
 * The five stages of the agency's real process, in order.
 *
 * Written from the applicant's side rather than the system's, because that is
 * who reads this page. The office visit is stated plainly at step two: it is the
 * step people most often assume registering online replaces, and finding out
 * otherwise after waiting a month is the worst outcome this page can produce.
 */
const PROCESS = [
  {
    title: 'Register',
    body: 'Create an account from home and see exactly which documents to bring. You get a reference number straight away.',
  },
  {
    title: 'Visit the office',
    body: 'Bring your documents to Sta. Cruz. Our staff check your identity against a valid ID — this is what starts your application.',
  },
  {
    title: 'Screening',
    body: 'Documents are verified and filed. You can follow which are complete and which are still outstanding from your account.',
  },
  {
    title: 'Matching',
    body: 'When a client company raises a request, eligible applicants are scored against its criteria and ranked for HR to review.',
  },
  {
    title: 'Deployment',
    body: 'An HR officer decides and records the placement. Your record carries forward into employment without being retyped.',
  },
]

/**
 * The process, as a numbered sequence.
 *
 * A horizontal run on a wide screen and a vertical list on a narrow one, with
 * the connecting rule drawn behind the markers so the steps read as one path
 * rather than five separate cards.
 */
function Process() {
  return (
    <section id="process" className="border-b scroll-mt-16">
      <div className="mx-auto max-w-6xl px-5 py-16">
        <div className="max-w-2xl">
          <h2 className="text-2xl font-semibold tracking-tight">From application to placement</h2>
          <p className="mt-2 text-muted-foreground">
            The agency's process, unchanged. The system records each step and shows both sides where
            an application has reached.
          </p>
        </div>

        <ol className="mt-10 grid gap-8 md:grid-cols-5 md:gap-5">
          {PROCESS.map((step, index) => (
            <li key={step.title} className="relative flex gap-4 md:flex-col md:gap-0">
              {/*
                The connector belongs to the step it leaves, not to the list, so
                it is positioned relative to its own marker and cannot drift out
                of alignment as the column count changes. The last step has none
                — the sequence should read as finishing, not trailing off.

                Vertical while the list is stacked, horizontal once it runs
                across; the extra 1.25rem at `md` bridges the grid gap.
              */}
              {index < PROCESS.length - 1 && (
                <span
                  aria-hidden="true"
                  className="absolute left-4 top-9 -ml-px h-[calc(100%-0.25rem)] w-px bg-border md:left-8 md:top-4 md:ml-0 md:h-px md:w-[calc(100%-2rem+1.25rem)]"
                />
              )}

              <span className="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border bg-card text-sm font-semibold text-primary shadow-sm">
                {index + 1}
              </span>
              <div className="min-w-0 md:mt-4">
                <p className="font-medium">{step.title}</p>
                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{step.body}</p>
              </div>
            </li>
          ))}
        </ol>

        <p className="mt-8 rounded-lg border-l-2 border-primary bg-muted/40 p-4 text-sm">
          Registering online does not replace the office visit. It means you arrive with the right
          documents and keep your place in the queue.
        </p>
      </div>
    </section>
  )
}

/**
 * The competency engine gets its own section.
 *
 * It is the part of the system that most needs explaining, and the part most
 * easily misread as "the computer picks who gets hired". Saying plainly that it
 * is rule-based, transparent, and advisory is worth more space than another
 * feature card.
 */
function DecisionSupport() {
  return (
    <section id="matching" className="border-b bg-muted/40 scroll-mt-16">
      <div className="mx-auto max-w-6xl px-5 py-16">
        <div className="grid gap-10 lg:grid-cols-2 lg:items-center">
          <div>
            <div className="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
              <Scale className="h-5 w-5" />
            </div>

            <h2 className="text-2xl font-semibold tracking-tight">
              Matching that explains itself
            </h2>

            <p className="mt-3 text-muted-foreground">
              HR sets what matters for a position and what each factor is worth. The system scores
              every eligible applicant against those criteria and ranks them — showing exactly how
              each point was awarded.
            </p>

            <p className="mt-3 text-muted-foreground">
              It is rule-based, not artificial intelligence. Every score traces back to a weight an
              HR officer entered, which is what makes a recommendation defensible to a client
              company and to the applicant who was not selected.
            </p>

            <p className="mt-4 rounded-md border-l-2 border-primary bg-card p-3 text-sm">
              The system recommends. It never hires. Deployment stays a separate, deliberate act by
              a person.
            </p>
          </div>

          {/* A worked example beats a description. These are the real figures the
              engine produces for a production helper posting. */}
          <div className="rounded-xl border bg-card p-5 shadow-sm">
            <div className="mb-4 flex items-baseline justify-between gap-3 border-b pb-3">
              <div>
                <p className="text-sm font-medium">Maria Cristina Santos</p>
                <p className="text-xs text-muted-foreground">Production Helper</p>
              </div>
              <div className="text-right">
                <p className="text-2xl font-semibold tabular-nums">85%</p>
                <p className="text-xs text-success">Highly recommended</p>
              </div>
            </div>

            <div className="space-y-3">
              <ScoreRow label="Educational attainment" score={15} weight={15} note="College graduate against a required high school" />
              <ScoreRow label="Relevant experience" score={10} weight={20} note="18 months against a range of 0 to 36 months" />
              <ScoreRow label="Certifications" score={10} weight={10} note="Holds Food Safety NC II" />
              <ScoreRow label="Distance from worksite" score={4.6} weight={5} note="3.4 km against a range of 0 to 40 km" />
            </div>

            <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">
              Every line is shown to the HR officer before any decision is recorded.
            </p>
          </div>
        </div>
      </div>
    </section>
  )
}

function ScoreRow({ label, score, weight, note }) {
  const percentage = weight > 0 ? (score / weight) * 100 : 0

  return (
    <div>
      <div className="flex items-baseline justify-between gap-3 text-sm">
        <span>{label}</span>
        <span className="shrink-0 tabular-nums text-muted-foreground">
          {score} / {weight}
        </span>
      </div>
      <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted">
        <div className="h-full rounded-full bg-primary" style={{ width: `${percentage}%` }} />
      </div>
      <p className="mt-1 text-xs text-muted-foreground">{note}</p>
    </div>
  )
}

function SiteFooter() {
  return (
    <footer className="mx-auto max-w-6xl px-5 py-10">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2.5">
          <LogoMark size="xs" />
          <div className="text-sm leading-tight">
            <p className="font-medium">EMPOWER</p>
            <p className="text-xs text-muted-foreground">CDE Manpower Services, Sta. Cruz, Laguna</p>
          </div>
        </div>

        <p className="max-w-md text-xs text-muted-foreground">
          Holds personal and sensitive personal information protected under the Data Privacy Act of
          2012 (RA 10173). Access is restricted and all activity is recorded.
        </p>
      </div>

      <p className="mt-8 border-t pt-5 text-xs text-muted-foreground">
        Capstone project, Laguna State Polytechnic University.
      </p>
    </footer>
  )
}
