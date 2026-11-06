import * as React from 'react'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { MAP_CONFIG, roundCoordinate } from '@/lib/geo'
import { cn } from '@/lib/utils'

/*
 * A Leaflet map with at most one pin. Loaded on demand (see map/index), so
 * the library and its tiles are only fetched when somebody opens a map.
 *
 * Leaflet keeps its own state; React only tells it where to look and where
 * the pin is. When `interactive`, a click places the pin and the pin can be
 * dragged; either reports the point through onPick. Nothing here asks for the
 * viewer's own position.
 */

// The pin in EMPOWER's own colour, drawn rather than loaded from Leaflet's
// image files (which bundlers do not resolve).
const PIN_ICON = L.divIcon({
  className: '',
  html: `<svg class="text-primary drop-shadow" width="32" height="42" viewBox="0 0 32 42" aria-hidden="true">
    <path fill="currentColor" d="M16 0C7.2 0 0 7 0 15.7 0 27.5 16 42 16 42s16-14.5 16-26.3C32 7 24.8 0 16 0z"/>
    <circle cx="16" cy="15.5" r="6" fill="white"/>
  </svg>`,
  iconSize: [32, 42],
  iconAnchor: [16, 42],
})

const point = (latlng) => ({ lat: roundCoordinate(latlng.lat), lng: roundCoordinate(latlng.lng) })

export default function MapCanvas({ view, marker, onPick, onStatus, interactive = true, label, className }) {
  const container = React.useRef(null)
  const map = React.useRef(null)
  const pin = React.useRef(null)
  const handlers = React.useRef({ onPick, onStatus })
  handlers.current = { onPick, onStatus }

  React.useEffect(() => {
    const instance = L.map(container.current, {
      zoomControl: interactive,
      dragging: interactive,
      scrollWheelZoom: interactive,
      doubleClickZoom: interactive,
      touchZoom: interactive,
      boxZoom: false,
      keyboard: interactive,
    }).setView([view.lat, view.lng], view.zoom)

    let failures = 0
    L.tileLayer(MAP_CONFIG.tileUrl, { maxZoom: 19, attribution: MAP_CONFIG.attribution })
      .on('load', () => handlers.current.onStatus?.('ready'))
      .on('tileerror', () => {
        // One missing tile is noise; several mean the map is not loading.
        failures += 1
        if (failures === 3) handlers.current.onStatus?.('error')
      })
      .addTo(instance)

    if (interactive) instance.on('click', (event) => handlers.current.onPick?.(point(event.latlng)))

    // A dialog opens with an animation, so the map's size settles after it is
    // made; without this, tiles would be laid out for the wrong size.
    const resize = new ResizeObserver(() => instance.invalidateSize())
    resize.observe(container.current)

    map.current = instance
    return () => {
      resize.disconnect()
      instance.remove()
      map.current = null
      pin.current = null
    }
    // Made once; the view and the pin are followed by the effects below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Moves the map when asked to (a search result, an existing pin).
  React.useEffect(() => {
    map.current?.setView([view.lat, view.lng], view.zoom)
  }, [view.lat, view.lng, view.zoom, view.key])

  React.useEffect(() => {
    const instance = map.current
    if (!instance) return

    if (!marker) {
      pin.current?.remove()
      pin.current = null
      return
    }

    if (pin.current) {
      pin.current.setLatLng([marker.lat, marker.lng])
      return
    }

    pin.current = L.marker([marker.lat, marker.lng], {
      icon: PIN_ICON,
      draggable: interactive,
      keyboard: false,
      title: 'Company location',
    })
      .on('dragend', (event) => handlers.current.onPick?.(point(event.target.getLatLng())))
      .addTo(instance)
  }, [marker?.lat, marker?.lng, interactive])

  return (
    <div
      ref={container}
      role="application"
      aria-label={label}
      // isolate: Leaflet's panes and controls carry z-indexes of 400-1000,
      // which would otherwise sit above the page's own menus and headers.
      className={cn('isolate h-full w-full rounded-md border bg-muted', className)}
    />
  )
}
