import * as React from 'react'
import { ShieldCheck } from 'lucide-react'
import { cn } from '@/lib/utils'

/**
 * The CDE Manpower Services logo.
 *
 * The supplied artwork has its navy background baked into the image rather than
 * being transparent, so it is always presented as a rounded badge — the same
 * shape an app icon takes. Dropping a hard-edged blue square onto a white card
 * reads as a sticker stuck on the page; giving it a corner radius and a hairline
 * ring makes it read as part of the interface.
 *
 * That baked-in background is also why the logo is never recoloured for dark
 * mode. It is the client's mark, not a theme token, and it stays exactly as
 * they supplied it in both themes.
 */

/*
 * Two files, chosen by size.
 *
 * The supplied artwork is a lockup: the octagon monogram above the words
 * MANPOWER SERVICES. At the sizes the sidebar and page headers use, that
 * wordmark is around one pixel tall — it reads as a smudge under the octagon
 * and makes the whole badge look blurred. Below 40px the monogram is used on
 * its own, which is what the lettering was never going to survive anyway.
 *
 * Both are cropped from the same original and padded to a square with the
 * artwork's own navy (#03197C) rather than centre-cropped, because the wordmark
 * runs almost edge to edge and a crop would clip the M and the S.
 */
const LOGO_FULL = '/logo/cde-manpower.png'
const LOGO_MARK = '/logo/cde-manpower-mark.png'

const SIZES = {
  xs: 'h-7 w-7 rounded-md',
  sm: 'h-8 w-8 rounded-md',
  md: 'h-10 w-10 rounded-lg',
  lg: 'h-12 w-12 rounded-xl',
  xl: 'h-16 w-16 rounded-2xl',
}

/** Sizes too small to render the wordmark legibly. */
const MARK_ONLY = ['xs', 'sm', 'md']

/**
 * The badge on its own.
 *
 * Falls back to the shield mark if the artwork is missing, so a fresh checkout
 * without the asset still renders a coherent interface instead of a column of
 * broken-image icons.
 */
export function LogoMark({ size = 'md', className, plain = false }) {
  const [failed, setFailed] = React.useState(false)

  if (failed) {
    return (
      <span
        className={cn(
          'flex shrink-0 items-center justify-center bg-primary text-primary-foreground',
          SIZES[size],
          className
        )}
        aria-hidden="true"
      >
        <ShieldCheck className="h-1/2 w-1/2" />
      </span>
    )
  }

  return (
    <img
      // `plain` always takes the mark. It is only used on the brand panel,
      // where the system name is already set in type directly beneath — so the
      // lockup would print MANPOWER SERVICES twice, once legibly and once as an
      // unreadable smudge a few pixels tall.
      src={plain || MARK_ONLY.includes(size) ? LOGO_MARK : LOGO_FULL}
      // Decorative in every placement: each one sits beside the words "EMPOWER"
      // or "CDE Manpower Services" already, and a screen reader announcing the
      // company name twice in a row is noise rather than information.
      alt=""
      aria-hidden="true"
      width={64}
      height={64}
      onError={() => setFailed(true)}
      className={cn(
        'shrink-0 object-cover',
        // `plain` is for surfaces already painted the artwork's own navy, where
        // the badge treatment would be counterproductive: with the backgrounds
        // matching exactly the square edge is invisible, and only the white
        // monogram shows. A ring and a corner radius would draw the edge back in.
        plain ? 'rounded-none' : 'ring-1 ring-black/10 dark:ring-white/15',
        SIZES[size],
        className
      )}
    />
  )
}

/**
 * The badge with the system name beside it.
 *
 * EMPOWER is the product and CDE Manpower Services is the agency that runs it,
 * so both appear: staff need to know which system they are in, and the agency's
 * own name is what makes it theirs.
 */
export function Logo({ size = 'md', className, subtitle = 'CDE Manpower Services' }) {
  return (
    <div className={cn('flex items-center gap-2.5', className)}>
      <LogoMark size={size} />
      <div className="min-w-0">
        <p className="truncate font-semibold leading-tight tracking-tight">EMPOWER</p>
        {subtitle && (
          <p className="truncate text-xs leading-tight text-muted-foreground">{subtitle}</p>
        )}
      </div>
    </div>
  )
}

export default Logo
