import * as React from 'react'
import { UserRound } from 'lucide-react'
import { cn } from '@/lib/utils'

/** Initials from whatever parts of the name we actually have. */
export function initialsOf(name) {
  const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean)

  if (parts.length === 0) return ''
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()

  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}

const SIZES = {
  xs: 'h-7 w-7 text-[10px]',
  sm: 'h-9 w-9 text-xs',
  md: 'h-11 w-11 text-sm',
  lg: 'h-16 w-16 text-lg',
  xl: 'h-24 w-24 text-2xl',
}

const ICON_SIZES = {
  xs: 'h-3.5 w-3.5',
  sm: 'h-4 w-4',
  md: 'h-5 w-5',
  lg: 'h-7 w-7',
  xl: 'h-10 w-10',
}

/**
 * A person's picture, or their initials when there is no picture to show.
 *
 * The one avatar used for applicants and employees everywhere they appear -
 * their own portal and every staff screen - so the fallback looks the same in
 * all of them. The initials are always drawn underneath and the picture is laid
 * over them, which means a picture still loading, a picture never uploaded, and
 * a link that has expired or points at a file that is gone all end in the same
 * place: the person's initials, never a broken-image icon.
 *
 * `alt` names the picture for a screen reader where it stands alone. Beside a
 * visible name it is left out, because reading the name twice helps nobody.
 */
export default function PersonAvatar({ src, name, size = 'sm', alt, className, onError }) {
  const [failedSrc, setFailedSrc] = React.useState(null)
  const showImage = Boolean(src) && failedSrc !== src
  const initials = initialsOf(name)

  return (
    <span
      className={cn(
        'surface-highlight relative inline-flex shrink-0 select-none items-center justify-center overflow-hidden rounded-full font-semibold text-white',
        SIZES[size],
        className
      )}
      role={alt ? 'img' : undefined}
      aria-label={alt}
      aria-hidden={alt ? undefined : true}
    >
      {initials ? <span>{initials}</span> : <UserRound className={ICON_SIZES[size]} />}

      {showImage && (
        <img
          // Keyed on the address so a replaced picture is a fresh element
          // rather than the old one being reused.
          key={src}
          src={src}
          alt=""
          draggable={false}
          loading="lazy"
          decoding="async"
          className="absolute inset-0 h-full w-full object-cover"
          onError={() => {
            setFailedSrc(src)
            onError?.()
          }}
        />
      )}
    </span>
  )
}
