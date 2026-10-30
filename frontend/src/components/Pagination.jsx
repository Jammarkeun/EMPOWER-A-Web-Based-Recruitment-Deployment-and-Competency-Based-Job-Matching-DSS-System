import * as React from 'react'
import { Button } from '@/components/ui/button'

/**
 * Moves to the last page when the page asked for is past the end.
 *
 * A bookmarked or stale `?page=`, or a list that shrank while somebody worked
 * through it (verify one person and come back), used to land on an empty page
 * that read "nothing matches" while the count above it said otherwise.
 */
export function useClampPage(meta, onPage) {
  const target = meta && meta.total > 0 && meta.current_page > meta.last_page ? meta.last_page : null
  const latest = React.useRef(onPage)
  latest.current = onPage

  React.useEffect(() => {
    if (target) latest.current(target)
  }, [target])
}

/**
 * Previous / next paging for any list the API returns page by page.
 *
 * Lives here rather than in a page so that every list can use it without
 * importing another screen's code - which, once pages load on demand, would
 * pull that whole screen into the bundle of every page that pages.
 */
export function Pagination({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null

  return (
    <div className="flex items-center justify-between pt-2 text-xs text-muted-foreground font-mono tabular-nums">
      <div>
        Showing page <span className="font-semibold text-foreground">{meta.current_page}</span> of{' '}
        <span className="font-semibold text-foreground">{meta.last_page}</span> ({meta.total} total)
      </div>
      <div className="flex items-center gap-1">
        <Button
          variant="outline"
          size="sm"
          disabled={meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
          className="h-8 text-xs font-sans"
        >
          Previous
        </Button>
        <Button
          variant="outline"
          size="sm"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
          className="h-8 text-xs font-sans"
        >
          Next
        </Button>
      </div>
    </div>
  )
}
