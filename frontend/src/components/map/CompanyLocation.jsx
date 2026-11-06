import * as React from 'react'
import { ExternalLink, Loader2, MapPin, MapPinOff, Search } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { MAP_CONFIG, externalMapUrl, formatCoordinates, hasPin, searchPlaces } from '@/lib/geo'

/*
 * A client company's pinned location, picked the way a location is shared in
 * a chat app: find the place, tap the exact spot, check the pin, confirm.
 *
 * The pin is only ever where somebody put it. Nothing is guessed from the
 * address, no default point is saved, and the viewer's own location is never
 * asked for. Searching moves the map; it does not place the pin.
 */

// Leaflet and its stylesheet are fetched only when a map is shown.
const MapCanvas = React.lazy(() => import('./MapCanvas'))

/** Stands in for the map if its code could not be downloaded. */
class MapBoundary extends React.Component {
  state = { failed: false }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  render() {
    if (this.state.failed) {
      return (
        <div className="flex h-full items-center justify-center rounded-md border bg-muted/40 p-4 text-center text-sm text-muted-foreground">
          The map could not be loaded. Check the internet connection and try again. Nothing has been changed.
        </div>
      )
    }
    return this.props.children
  }
}

export function LazyMap(props) {
  return (
    <MapBoundary>
      <React.Suspense
        fallback={
          <div className="flex h-full items-center justify-center rounded-md border bg-muted/40 text-sm text-muted-foreground">
            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            Loading map…
          </div>
        }
      >
        <MapCanvas {...props} />
      </React.Suspense>
    </MapBoundary>
  )
}

const TILE_ERROR = 'Map images are not loading. Check the internet connection; a pin can still be placed and kept.'

// -------------------------------------------------------------------- picker

/**
 * The map dialog. `initial` is the saved pin, or null. Nothing is handed back
 * until the person confirms, so cancelling leaves the form as it was.
 */
export function LocationPickerDialog({ open, onOpenChange, initial, onConfirm, companyName }) {
  const [pin, setPin] = React.useState(initial)
  const [view, setView] = React.useState(() => viewFor(initial))
  const [query, setQuery] = React.useState('')
  const [results, setResults] = React.useState(null)
  const [searching, setSearching] = React.useState(false)
  const [searchError, setSearchError] = React.useState('')
  const [tilesFailed, setTilesFailed] = React.useState(false)

  React.useEffect(() => {
    if (!open) return
    setPin(initial)
    setView(viewFor(initial))
    setQuery('')
    setResults(null)
    setSearchError('')
    setTilesFailed(false)
    // Reset each time it opens, from the pin as it then stands.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  async function search() {
    const text = query.trim()
    if (!text || searching) return

    setSearching(true)
    setSearchError('')
    try {
      const found = await searchPlaces(text)
      setResults(found)
    } catch {
      setResults(null)
      setSearchError('The search is not available right now. Move the map by hand instead.')
    } finally {
      setSearching(false)
    }
  }

  function confirm() {
    if (!pin) return
    onConfirm(pin)
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>Pin company location</DialogTitle>
          <DialogDescription>
            Find {companyName || 'the establishment'}, then click the exact spot on the map. Drag the pin, or click
            somewhere else, to move it.
          </DialogDescription>
        </DialogHeader>

        {/* Not a <form>: this dialog sits inside the company form, and a
            nested submit would send that form too. */}
        <div className="space-y-2">
          <div className="flex gap-2">
            <Input
              aria-label="Search for a place"
              placeholder="Company name, street, barangay, or town"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault()
                  search()
                }
              }}
            />
            <Button type="button" variant="outline" onClick={search} disabled={searching || !query.trim()}>
              {searching ? <Loader2 className="h-4 w-4 animate-spin" /> : <Search className="h-4 w-4" />}
              Search
            </Button>
          </div>
          {searchError && <p className="text-xs text-destructive">{searchError}</p>}
          {results && (
            <div className="rounded-md border">
              {results.length === 0 ? (
                <p className="p-2 text-xs text-muted-foreground">No place found. Try a shorter name, or move the map by hand.</p>
              ) : (
                <ul className="max-h-36 overflow-y-auto text-sm">
                  {results.map((result) => (
                    <li key={result.id}>
                      <button
                        type="button"
                        className="w-full px-3 py-1.5 text-left hover:bg-muted"
                        onClick={() => setView({ ...result, zoom: MAP_CONFIG.pinnedZoom, key: Date.now() })}
                      >
                        {result.label}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              <p className="border-t px-3 py-1.5 text-xs text-muted-foreground">
                A result moves the map there. Click the exact spot to place the pin.
              </p>
            </div>
          )}
        </div>

        <div className="h-[55vh] min-h-[280px]">
          {open && (
            <LazyMap
              view={view}
              marker={pin}
              onPick={setPin}
              onStatus={(status) => setTilesFailed(status === 'error')}
              label="Map. Click to place the company's pin."
            />
          )}
        </div>
        {tilesFailed && <p className="text-xs text-warning">{TILE_ERROR}</p>}

        <p className="flex items-center gap-1.5 text-sm" role="status">
          <MapPin className="h-4 w-4 shrink-0 text-primary" />
          {pin ? `Pinned at ${formatCoordinates(pin.lat, pin.lng)}` : 'No pin yet. Click the map where the establishment is.'}
        </p>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="button" onClick={confirm} disabled={!pin}>
            Confirm location
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function viewFor(pin) {
  return pin
    ? { lat: pin.lat, lng: pin.lng, zoom: MAP_CONFIG.pinnedZoom, key: 'pin' }
    : { ...MAP_CONFIG.defaultCenter, zoom: MAP_CONFIG.defaultZoom, key: 'default' }
}

// -------------------------------------------------------------- form section

/**
 * The "Company location" part of the add and edit forms. The coordinates live
 * in the form's own state and are saved with it; this only changes them on
 * an explicit Confirm or Remove.
 */
export function CompanyLocationField({ latitude, longitude, onChange, error, companyName }) {
  const [picking, setPicking] = React.useState(false)
  const pinned = hasPin(latitude, longitude)
  const current = pinned ? { lat: latitude, lng: longitude } : null

  return (
    <div className="space-y-2 rounded-md border bg-muted/20 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium">Company location</p>
          <p className="text-xs text-muted-foreground" role="status">
            {pinned ? `Location selected · ${formatCoordinates(latitude, longitude)}` : 'No location selected'}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {pinned && (
            <Button type="button" variant="ghost" size="sm" onClick={() => onChange({ latitude: null, longitude: null })}>
              <MapPinOff className="h-3.5 w-3.5" />
              Remove pin
            </Button>
          )}
          <Button type="button" variant="outline" size="sm" onClick={() => setPicking(true)}>
            <MapPin className="h-3.5 w-3.5" />
            {pinned ? 'Change location' : 'Pin company location'}
          </Button>
        </div>
      </div>

      {pinned && (
        <div className="h-40">
          <LazyMap view={{ lat: latitude, lng: longitude, zoom: 16, key: `${latitude},${longitude}` }} marker={current} interactive={false} label="Selected company location" />
        </div>
      )}
      {error && <p className="text-xs text-destructive">{error}</p>}

      <LocationPickerDialog
        open={picking}
        onOpenChange={setPicking}
        initial={current}
        companyName={companyName}
        onConfirm={(pin) => onChange({ latitude: pin.lat, longitude: pin.lng })}
      />
    </div>
  )
}

// ------------------------------------------------------------- profile card

/**
 * The saved location on the company's page: the company's own pin, never the
 * viewer's position.
 */
export function CompanyLocationCard({ company, canEdit, onEdit }) {
  const pinned = hasPin(company.latitude, company.longitude)

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-2 text-base">
          <MapPin className="h-4 w-4 text-primary" />
          Company location
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <p className="text-sm">{company.office_address}</p>
        {pinned ? (
          <>
            <div className="h-52">
              <LazyMap
                view={{ lat: company.latitude, lng: company.longitude, zoom: 16, key: `${company.latitude},${company.longitude}` }}
                marker={{ lat: company.latitude, lng: company.longitude }}
                interactive={false}
                label={`Pinned location of ${company.company_name}`}
              />
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-mono text-xs text-muted-foreground">
                {formatCoordinates(company.latitude, company.longitude)}
              </span>
              <a
                href={externalMapUrl(company.latitude, company.longitude)}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
              >
                Open in Google Maps
                <ExternalLink className="h-3.5 w-3.5" />
              </a>
            </div>
          </>
        ) : (
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm text-muted-foreground">No location pinned yet.</p>
            {canEdit && (
              <Button variant="outline" size="sm" onClick={onEdit}>
                <MapPin className="h-3.5 w-3.5" />
                Pin location
              </Button>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
