import * as React from 'react'
import { Link } from 'react-router-dom'
import { ArrowLeft, Lock, ShieldCheck } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { LogoMark } from '@/components/Logo'
import ThemeToggle from '@/components/ThemeToggle'
import { cn } from '@/lib/utils'

/**
 * The exact navy of the supplied artwork.
 *
 * Not an approximation — it is sampled from the logo file, and the brand panel
 * is painted with it so the logo's own baked-in background becomes invisible.
 * What the eye sees is the white monogram sitting directly on the panel, with no
 * square edge anywhere. Change this and the logo reappears as a visible tile.
 */
const BRAND_NAVY = '#03197C'

/**
 * Shared shell for signing in and registering.
 *
 * Split down the middle: the agency on the left, the form on the right. The
 * split earns its place rather than being decoration — these are the only two
 * screens a person can reach without an account, so they are the only chance to
 * say whose system this is and what it is for before asking for a password.
 *
 * The brand panel is hidden below `lg`. On a phone it would push the form below
 * the fold, and someone signing in on their phone at the office gate wants the
 * email field, not the pitch.
 */
export default function AuthLayout({ title, subtitle, points, footer, wide = false, children }) {
  return (
    <div className="flex min-h-screen flex-col bg-background lg:flex-row">
      <BrandPanel points={points} />

      <div className="relative flex flex-1 flex-col">
        <header className="flex items-center justify-between px-5 py-4">
          <Button variant="ghost" size="sm" asChild>
            <Link to="/">
              <ArrowLeft className="h-3.5 w-3.5" />
              Back
            </Link>
          </Button>
          <ThemeToggle />
        </header>

        {/* Registration is a real form with three-across name fields; sign-in is
            two inputs. Centring both vertically would leave the long form
            drifting on a tall screen, so only the short one is centred. */}
        <main
          className={cn(
            'flex flex-1 justify-center px-4 pb-14 pt-2',
            wide ? 'items-start' : 'items-center'
          )}
        >
          <div className={cn('w-full space-y-6', wide ? 'max-w-2xl' : 'max-w-md')}>
            <div className="space-y-2">
              {/* The mark repeats here only where the brand panel is hidden, so
                  a phone still shows whose system this is. */}
              <LogoMark size="lg" className="shadow-sm lg:hidden" />
              <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
              {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
            </div>

            {children}

            {footer && <div className="space-y-3 text-center">{footer}</div>}

            <p className="flex items-center justify-center gap-1.5 text-xs text-muted-foreground">
              <Lock className="h-3 w-3" />
              All activity is recorded in the audit trail.
            </p>
          </div>
        </main>
      </div>
    </div>
  )
}

/**
 * The left half: who this belongs to.
 *
 * Painted in the brand navy in both themes. This panel is the agency's own
 * colour rather than a theme surface — a client's identity is not something that
 * should change because the user prefers dark mode.
 */
function BrandPanel({ points }) {
  return (
    <aside
      className="relative hidden w-full overflow-hidden lg:flex lg:w-[44%] lg:max-w-[34rem] lg:flex-col xl:w-[40%]"
      style={{ backgroundColor: BRAND_NAVY }}
    >
      {/*
        A soft field of light from the top-left, and a faint grid. Both are
        barely-there on purpose: this panel sits behind a logo and a short piece
        of text, and anything with more contrast would compete with them.
      */}
      {/*
        Lit from the lower right, deliberately not the top left.

        The logo relies on the panel being exactly its own navy so its baked-in
        background disappears. Centring this glow at 20% 0% — behind the logo —
        lightened the panel just there and made the logo's square edge visible
        again, which is the one thing painting the panel this colour was meant
        to avoid.
      */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0"
        style={{
          backgroundImage:
            'radial-gradient(ellipse 80% 60% at 85% 100%, rgba(255,255,255,0.14), transparent 65%)',
        }}
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 opacity-[0.07]"
        style={{
          backgroundImage:
            'linear-gradient(rgba(255,255,255,0.6) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.6) 1px, transparent 1px)',
          backgroundSize: '44px 44px',
          // Faded out of the top-left for the same reason as the glow above:
          // the logo needs flat, unmodified navy behind it.
          maskImage: 'radial-gradient(ellipse 75% 65% at 70% 65%, #000, transparent 75%)',
          WebkitMaskImage: 'radial-gradient(ellipse 75% 65% at 70% 65%, #000, transparent 75%)',
        }}
      />

      <div className="relative flex flex-1 flex-col justify-between p-10 xl:p-12">
        <div>
          {/* plain: the panel is already the artwork's own navy, so the logo
              needs no badge — its square edge is invisible against it. */}
          <LogoMark size="xl" plain />

          <p className="mt-6 text-3xl font-semibold leading-tight tracking-tight text-white">
            EMPOWER
          </p>
          <p className="mt-1 text-sm font-medium text-white/70">CDE Manpower Services</p>

          <p className="mt-5 max-w-sm text-balance text-sm leading-relaxed text-white/75">
            Recruitment, deployment, and competency-based job matching — from an applicant walking
            into the office through to their placement, on one record.
          </p>
        </div>

        {points?.length > 0 && (
          <ul className="mt-10 space-y-3.5">
            {points.map((point) => (
              <li key={point.title} className="flex gap-3">
                <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-white/10">
                  <point.icon className="h-3.5 w-3.5 text-white/80" />
                </span>
                <div className="min-w-0">
                  <p className="text-sm font-medium text-white">{point.title}</p>
                  <p className="text-xs leading-relaxed text-white/60">{point.body}</p>
                </div>
              </li>
            ))}
          </ul>
        )}

        <p className="mt-10 flex items-center gap-1.5 text-xs text-white/50">
          <ShieldCheck className="h-3.5 w-3.5" />
          Records held under the Data Privacy Act of 2012 (RA 10173)
        </p>
      </div>
    </aside>
  )
}

/** A plain card wrapper matching the form styling on both auth screens. */
export function AuthCard({ className, children }) {
  return (
    <div className={cn('rounded-xl border bg-card p-5 shadow-sm', className)}>{children}</div>
  )
}
