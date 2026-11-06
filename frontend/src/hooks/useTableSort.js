/**
 * A list's sort order, kept in the address bar beside its filters.
 *
 * Sorting is done by the server over the whole list, never on the page in
 * the browser: a page holds twenty rows of perhaps hundreds, and sorting those
 * twenty would put the wrong people at the top. So this only reads and writes
 * `sort_by` / `sort_dir`, which the list sends with its query.
 *
 * Choosing a column sorts it in its natural first direction - dates newest
 * first, names A to Z - and choosing it again reverses it. A new order goes
 * back to page one; the filters stay as they were. The list's own default is
 * left out of the address, so an unsorted list's link stays as it was.
 */
export function useTableSort(searchParams, setSearchParams, { by, dir, firstDirection = {} }) {
  const sortBy = searchParams.get('sort_by') || by
  const asked = searchParams.get('sort_dir')
  const sortDir = asked === 'asc' || asked === 'desc' ? asked : sortBy === by ? dir : firstDirection[sortBy] ?? 'asc'

  function sortOn(column) {
    const nextDir = column === sortBy ? (sortDir === 'asc' ? 'desc' : 'asc') : firstDirection[column] ?? 'asc'
    const next = new URLSearchParams(searchParams)

    if (column === by && nextDir === dir) {
      next.delete('sort_by')
      next.delete('sort_dir')
    } else {
      next.set('sort_by', column)
      next.set('sort_dir', nextDir)
    }
    next.delete('page')
    setSearchParams(next)
  }

  const isDefault = sortBy === by && sortDir === dir

  return {
    sortBy,
    sortDir,
    sortOn,
    // Spread onto each SortableHead.
    head: { sortBy, sortDir, onSort: sortOn },
    // For the query: nothing when it is the server's own default.
    params: isDefault ? {} : { sort_by: sortBy, sort_dir: sortDir },
    // For "clear filters": the order is not a filter, so it stays.
    keep: (params) => {
      if (!isDefault) {
        params.set('sort_by', sortBy)
        params.set('sort_dir', sortDir)
      }
      return params
    },
  }
}
