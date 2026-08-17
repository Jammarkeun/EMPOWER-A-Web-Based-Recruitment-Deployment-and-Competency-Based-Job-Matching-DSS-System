import * as React from 'react'
import { useNavigate } from 'react-router-dom'
import { createPortal } from 'react-dom'
import { Search, Loader2, CornerDownLeft, ArrowUp, ArrowDown } from 'lucide-react'
import { get } from '@/lib/api'
import { useDebounced } from '@/hooks/useApi'
import { cn } from '@/lib/utils'
import { Badge } from '@/components/ui/badge'

/**
 * Search across every record type, opened with Ctrl+K.
 *
 * This is the direct answer to the agency's stated problem — "HR manually
 * searches applicants using folders and Ctrl + F in Excel". Search that lives on
 * one screen at a time does not replace that: someone at the counter holding a
 * name and a phone number should not first have to decide whether that person is
 * an applicant, an employee, or neither.
 *
 * Fully keyboard driven, because it is used mid-conversation while the person is
 * standing there.
 */
export default function CommandPalette() {
  const [open, setOpen] = React.useState(false)
  const [query, setQuery] = React.useState('')
  const [groups, setGroups] = React.useState([])
  const [loading, setLoading] = React.useState(false)
  const [activeIndex, setActiveIndex] = React.useState(0)

  const inputRef = React.useRef(null)
  const navigate = useNavigate()
  const debouncedQuery = useDebounced(query, 250)

  // Flattened so arrow keys can move across group boundaries without the user
  // thinking about groups at all.
  const flat = React.useMemo(
    () => groups.flatMap((group) => group.results.map((result) => ({ ...result, group: group.label }))),
    [groups]
  )

  // Ctrl+K / Cmd+K to open, Escape to close.
  React.useEffect(() => {
    function onKeyDown(event) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setOpen((current) => !current)
        return
      }

      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [])

  React.useEffect(() => {
    if (open) {
      setQuery('')
      setGroups([])
      setActiveIndex(0)
      // Deferred so the input exists before focus is requested.
      requestAnimationFrame(() => inputRef.current?.focus())
    }
  }, [open])

  React.useEffect(() => {
    if (!open || debouncedQuery.trim().length < 2) {
      setGroups([])
      return
    }

    let cancelled = false
    setLoading(true)

    get('/search', { q: debouncedQuery })
      .then((response) => {
        // Guards against a slower earlier request overwriting newer results.
        if (!cancelled) {
          setGroups(response.data.groups)
          setActiveIndex(0)
        }
      })
      .catch(() => {
        if (!cancelled) setGroups([])
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [debouncedQuery, open])

  function handleKeyDown(event) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActiveIndex((index) => (index + 1) % Math.max(flat.length, 1))
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActiveIndex((index) => (index - 1 + flat.length) % Math.max(flat.length, 1))
    }

    if (event.key === 'Enter' && flat[activeIndex]) {
      event.preventDefault()
      openResult(flat[activeIndex])
    }
  }

  function openResult(result) {
    setOpen(false)
    navigate(result.url)
  }

  if (!open) return <SearchTrigger onOpen={() => setOpen(true)} />

  return (
    <>
      <SearchTrigger onOpen={() => setOpen(true)} />
      {createPortal(
        <div
          className="fixed inset-0 z-[90] flex items-start justify-center p-4 pt-[12vh]"
          role="dialog"
          aria-modal="true"
          aria-label="Search everything"
        >
          <div
            className="absolute inset-0 bg-foreground/40 backdrop-blur-sm"
            onClick={() => setOpen(false)}
          />

          <div className="relative w-full max-w-xl overflow-hidden rounded-xl border bg-card shadow-2xl animate-fade-in">
            <div className="flex items-center gap-3 border-b px-4">
              {loading ? (
                <Loader2 className="h-4 w-4 shrink-0 animate-spin text-muted-foreground" />
              ) : (
                <Search className="h-4 w-4 shrink-0 text-muted-foreground" />
              )}
              <input
                ref={inputRef}
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="Search applicants, employees, requests, clients…"
                className="h-12 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                aria-label="Search"
                autoComplete="off"
              />
              <kbd className="hidden shrink-0 rounded border px-1.5 py-0.5 text-[10px] text-muted-foreground sm:block">
                ESC
              </kbd>
            </div>

            <div className="max-h-[52vh] overflow-y-auto">
              {query.trim().length < 2 ? (
                <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                  Type at least two characters — a name, code, or phone number.
                </p>
              ) : flat.length === 0 && !loading ? (
                <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                  Nothing found for &ldquo;{query}&rdquo;.
                </p>
              ) : (
                groups.map((group) => (
                  <div key={group.type}>
                    <p className="px-4 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                      {group.label}
                    </p>
                    <ul>
                      {group.results.map((result) => {
                        const index = flat.findIndex(
                          (item) => item.url === result.url && item.title === result.title
                        )
                        const active = index === activeIndex

                        return (
                          <li key={result.url}>
                            <button
                              onClick={() => openResult(result)}
                              onMouseEnter={() => setActiveIndex(index)}
                              className={cn(
                                'flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors',
                                active ? 'bg-accent' : 'hover:bg-accent/60'
                              )}
                            >
                              <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">{result.title}</p>
                                <p className="truncate text-xs text-muted-foreground">
                                  {result.subtitle}
                                </p>
                              </div>
                              {result.badge && <Badge tone="muted">{result.badge}</Badge>}
                            </button>
                          </li>
                        )
                      })}
                    </ul>
                  </div>
                ))
              )}
            </div>

            <div className="flex items-center gap-4 border-t bg-muted/40 px-4 py-2 text-[11px] text-muted-foreground">
              <span className="flex items-center gap-1">
                <ArrowUp className="h-3 w-3" />
                <ArrowDown className="h-3 w-3" />
                to move
              </span>
              <span className="flex items-center gap-1">
                <CornerDownLeft className="h-3 w-3" />
                to open
              </span>
              <span className="ml-auto hidden sm:block">
                Results are limited to what your role can view
              </span>
            </div>
          </div>
        </div>,
        document.body
      )}
    </>
  )
}

/**
 * The visible entry point.
 *
 * A shortcut nobody knows about is a shortcut nobody uses, so the trigger shows
 * the key combination rather than relying on people discovering it.
 */
function SearchTrigger({ onOpen }) {
  const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform)

  return (
    <button
      onClick={onOpen}
      className="flex h-8 items-center gap-2 rounded-md border bg-card px-2.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
      aria-label="Search everything"
    >
      <Search className="h-3.5 w-3.5" />
      <span className="hidden sm:inline">Search…</span>
      <kbd className="hidden rounded border bg-muted px-1.5 py-0.5 text-[10px] sm:block">
        {isMac ? '⌘' : 'Ctrl'} K
      </kbd>
    </button>
  )
}
