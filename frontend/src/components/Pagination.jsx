import { Button } from '@/components/ui/button'

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
