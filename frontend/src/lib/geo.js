/**
 * The map behind a client company's pinned location.
 *
 * OpenStreetMap: free, no key, nothing to keep secret. Its tile and search
 * services are run by volunteers and ask for light use with attribution, so
 * the map is only loaded when somebody opens one, and searching happens when
 * the person presses Search - never as they type (Nominatim's usage policy
 * forbids autocomplete). Either address can be pointed at another
 * OSM-compatible provider through VITE_MAP_TILE_URL / VITE_MAP_SEARCH_URL.
 */
export const MAP_CONFIG = {
  tileUrl: import.meta.env.VITE_MAP_TILE_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  searchUrl: import.meta.env.VITE_MAP_SEARCH_URL || 'https://nominatim.openstreetmap.org/search',
  // Where the map opens when nothing is pinned yet: Laguna, where CDE and its
  // clients are. Only a starting view - it is never saved as anybody's location.
  defaultCenter: { lat: 14.27, lng: 121.41 },
  defaultZoom: 11,
  pinnedZoom: 17,
}

/** A saved or chosen pin: both coordinates present as numbers. */
export function hasPin(latitude, longitude) {
  return typeof latitude === 'number' && typeof longitude === 'number' && Number.isFinite(latitude) && Number.isFinite(longitude)
}

/** Seven decimal places, as the database keeps them. */
export function roundCoordinate(value) {
  return Math.round(value * 1e7) / 1e7
}

export function formatCoordinates(latitude, longitude) {
  return `${latitude.toFixed(6)}, ${longitude.toFixed(6)}`
}

/** Opens the saved point - not the viewer's own position - in Google Maps. */
export function externalMapUrl(latitude, longitude) {
  return `https://www.google.com/maps/search/?api=1&query=${latitude},${longitude}`
}

let lastSearchAt = 0

/**
 * Places matching a name or address, to move the map near them. A result is
 * somewhere to look, not the pin: the person still clicks the exact spot.
 *
 * At most one request a second, as the provider asks.
 */
export async function searchPlaces(query, { signal } = {}) {
  const wait = lastSearchAt + 1100 - Date.now()
  if (wait > 0) await new Promise((resolve) => setTimeout(resolve, wait))
  lastSearchAt = Date.now()

  const params = new URLSearchParams({ q: query, format: 'jsonv2', limit: '5', countrycodes: 'ph' })
  const response = await fetch(`${MAP_CONFIG.searchUrl}?${params}`, {
    signal,
    headers: { Accept: 'application/json' },
  })
  if (!response.ok) throw new Error('The map search is not available right now.')

  const rows = await response.json()
  return rows.map((row) => ({
    id: row.place_id,
    label: row.display_name,
    lat: Number(row.lat),
    lng: Number(row.lon),
  }))
}
