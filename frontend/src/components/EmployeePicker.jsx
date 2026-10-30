import * as React from 'react'
import { Check, ChevronsUpDown, Loader2, Search, X } from 'lucide-react'
import { get } from '@/lib/api'
import { useDebounced } from '@/hooks/useApi'
import { cn } from '@/lib/utils'

/**
 * Type a name or employee number, then pick from what matches.
 *
 * Asked for by CDE on 26 October 2026: one client has around 170 deployed
 * workers, and scrolling a dropdown of all of them is slow on a phone. The
 * list used to load the first 100 and stop, so anybody past that could not be
 * chosen at all. Searching happens on the server, inside the coordinator's
 * own client - the list can only ever offer workers the coordinator may
 * report on.
 *
 * A combobox in the ARIA sense: the text box owns a listbox, arrow keys move
 * through it, Enter chooses, Escape closes.
 */
export default function EmployeePicker({
  id = 'employee_id',
  value,
  selected,
  onChange,
  endpoint = '/coordinator/employees',
  params,
  placeholder = 'Type a name or employee number…',
  disabled,
  'aria-invalid': ariaInvalid,
  'aria-describedby': ariaDescribedBy,
}) {
  const [query, setQuery] = React.useState('')
  const [open, setOpen] = React.useState(false)
  const [results, setResults] = React.useState([])
  const [loading, setLoading] = React.useState(false)
  const [error, setError] = React.useState(null)
  const [active, setActive] = React.useState(0)
  const debounced = useDebounced(query, 250)
  const listId = `${id}-options`
  const wrapper = React.useRef(null)
  const paramsKey = JSON.stringify(params ?? {})

  React.useEffect(() => {
    if (!open) return undefined

    const controller = new AbortController()
    setLoading(true)
    setError(null)

    get(endpoint, { ...JSON.parse(paramsKey), search: debounced || undefined, per_page: 20 }, { signal: controller.signal })
      .then((response) => {
        setResults(response.data ?? [])
        setActive(0)
      })
      .catch((err) => {
        if (err?.name !== 'CanceledError' && err?.code !== 'ERR_CANCELED') setError(err.message)
      })
      .finally(() => setLoading(false))

    return () => controller.abort()
  }, [open, debounced, endpoint, paramsKey])

  // Closes when focus or a click goes elsewhere.
  React.useEffect(() => {
    if (!open) return undefined
    const close = (event) => {
      if (wrapper.current && !wrapper.current.contains(event.target)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [open])

  function choose(employee) {
    onChange(employee)
    setQuery('')
    setOpen(false)
  }

  function onKeyDown(event) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setOpen(true)
      setActive((i) => Math.min(i + 1, Math.max(results.length - 1, 0)))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActive((i) => Math.max(i - 1, 0))
    } else if (event.key === 'Enter' && open && results[active]) {
      event.preventDefault()
      choose(results[active])
    } else if (event.key === 'Escape') {
      setOpen(false)
    }
  }

  const label = selected ? `${selected.full_name}${selected.employee_number ? ` · ${selected.employee_number}` : ''}` : ''

  return (
    <div ref={wrapper} className="relative">
      <div
        className={cn(
          'flex h-11 w-full items-center gap-2 rounded-control border border-transparent bg-inset px-3 text-sm transition-colors',
          'focus-within:border-brand focus-within:bg-card focus-within:ring-1 focus-within:ring-brand',
          ariaInvalid === 'true' && 'border-destructive',
          disabled && 'cursor-not-allowed opacity-50'
        )}
      >
        <Search className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        <input
          id={id}
          type="text"
          role="combobox"
          aria-expanded={open}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={open && results[active] ? `${id}-option-${results[active].id}` : undefined}
          aria-invalid={ariaInvalid}
          aria-describedby={ariaDescribedBy}
          autoComplete="off"
          disabled={disabled}
          className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-muted-foreground"
          placeholder={label || placeholder}
          value={query}
          onChange={(event) => {
            setQuery(event.target.value)
            setOpen(true)
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={onKeyDown}
        />
        {value && !disabled ? (
          <button
            type="button"
            className="rounded-full p-0.5 text-muted-foreground hover:text-foreground"
            onClick={() => onChange(null)}
            aria-label="Clear the chosen employee"
          >
            <X className="h-4 w-4" />
          </button>
        ) : (
          <ChevronsUpDown className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        )}
      </div>

      {selected && !open && (
        <p className="mt-1 text-xs text-muted-foreground">
          Chosen: <span className="font-medium text-foreground">{label}</span>
          {selected.department && <> · {selected.department}</>}
        </p>
      )}

      {open && (
        <ul
          id={listId}
          role="listbox"
          className="absolute z-50 mt-1 max-h-72 w-full overflow-auto rounded-row border border-border/70 bg-popover p-1 text-sm shadow-float"
        >
          {loading && results.length === 0 ? (
            <li className="flex items-center gap-2 px-3 py-2 text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" /> Searching…
            </li>
          ) : error ? (
            <li className="px-3 py-2 text-destructive">{error}</li>
          ) : results.length === 0 ? (
            <li className="px-3 py-2 text-muted-foreground">
              {debounced ? `No active employee matches "${debounced}".` : 'No active employees.'}
            </li>
          ) : (
            results.map((employee, index) => (
              <li
                key={employee.id}
                id={`${id}-option-${employee.id}`}
                role="option"
                aria-selected={String(employee.id) === String(value)}
                className={cn(
                  'flex cursor-pointer items-center gap-2 rounded-[10px] px-3 py-2',
                  index === active && 'bg-inset-selected'
                )}
                onMouseEnter={() => setActive(index)}
                onMouseDown={(event) => {
                  // Before the input loses focus, so the choice is not lost.
                  event.preventDefault()
                  choose(employee)
                }}
              >
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  {String(employee.id) === String(value) && <Check className="h-4 w-4 text-brand" />}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium">{employee.full_name}</span>
                  <span className="block truncate text-xs text-muted-foreground">
                    {[employee.employee_number, employee.department, employee.position].filter(Boolean).join(' · ')}
                  </span>
                </span>
              </li>
            ))
          )}
        </ul>
      )}
    </div>
  )
}
