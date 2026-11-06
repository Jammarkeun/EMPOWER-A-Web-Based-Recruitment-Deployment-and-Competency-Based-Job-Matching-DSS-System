import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import * as React from 'react'

/*
 * Leaflet cannot draw in jsdom, so the map itself is replaced by a stand-in
 * that shows what it was asked to display and lets the test "click" and
 * "drag" the way a person would on the real map.
 */
vi.mock('@/components/map/MapCanvas', () => ({
  default: ({ view, marker, onPick, onStatus, interactive = true, label }) => (
    <div data-testid={interactive ? 'picker-map' : 'preview-map'} aria-label={label}>
      <span>
        view {view.lat},{view.lng} z{view.zoom}
      </span>
      <span>{marker ? `marker ${marker.lat},${marker.lng}` : 'no marker'}</span>
      {interactive && (
        <>
          <button type="button" onClick={() => onPick({ lat: 14.2814571, lng: 121.4161847 })}>
            map click
          </button>
          <button type="button" onClick={() => onPick({ lat: 14.2820001, lng: 121.4170002 })}>
            drag pin
          </button>
          <button type="button" onClick={() => onStatus?.('error')}>
            tiles fail
          </button>
        </>
      )}
    </div>
  ),
}))

const { CompanyLocationCard, CompanyLocationField, LocationPickerDialog } = await import('./CompanyLocation')

function Harness({ initial = { latitude: null, longitude: null } }) {
  const [location, setLocation] = React.useState(initial)
  const [name, setName] = React.useState('Best Tiwi Foods')
  return (
    <>
      <input aria-label="Company name" value={name} onChange={(e) => setName(e.target.value)} />
      <CompanyLocationField {...location} companyName={name} onChange={setLocation} />
    </>
  )
}

describe('Company location', () => {
  it('places, moves, and confirms a pin; cancelling keeps the rest of the form', async () => {
    render(<Harness />)
    expect(screen.getByText('No location selected')).toBeInTheDocument()

    await userEvent.clear(screen.getByLabelText('Company name'))
    await userEvent.type(screen.getByLabelText('Company name'), 'Tiwi Foods Inc.')

    // Open, place a pin, then cancel: nothing is kept, the name is untouched.
    await userEvent.click(screen.getByRole('button', { name: 'Pin company location' }))
    let dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByTestId('picker-map')).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Confirm location' })).toBeDisabled()
    await userEvent.click(within(dialog).getByRole('button', { name: 'map click' }))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByText('No location selected')).toBeInTheDocument()
    expect(screen.getByLabelText('Company name')).toHaveValue('Tiwi Foods Inc.')

    // Again: place it, move it, confirm.
    await userEvent.click(screen.getByRole('button', { name: 'Pin company location' }))
    dialog = await screen.findByRole('dialog')
    await userEvent.click(await within(dialog).findByRole('button', { name: 'map click' }))
    expect(within(dialog).getByText('Pinned at 14.281457, 121.416185')).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'drag pin' }))
    expect(within(dialog).getByText('marker 14.2820001,121.4170002')).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Confirm location' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByText('Location selected · 14.282000, 121.417000')).toBeInTheDocument()
    expect(await screen.findByTestId('preview-map')).toHaveTextContent('marker 14.2820001,121.4170002')
    expect(screen.getByLabelText('Company name')).toHaveValue('Tiwi Foods Inc.')

    // Removing it is its own explicit step.
    await userEvent.click(screen.getByRole('button', { name: 'Remove pin' }))
    expect(screen.getByText('No location selected')).toBeInTheDocument()
  })

  it('opens on the saved pin when changing an existing location', async () => {
    render(<Harness initial={{ latitude: 14.28, longitude: 121.41 }} />)

    await userEvent.click(screen.getByRole('button', { name: 'Change location' }))
    const map = await within(await screen.findByRole('dialog')).findByTestId('picker-map')
    expect(map).toHaveTextContent('view 14.28,121.41 z17')
    expect(map).toHaveTextContent('marker 14.28,121.41')
  })

  it('does not place a pin until the map is clicked - not on opening, and not from a search', async () => {
    const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => [{ place_id: 9, display_name: 'Santa Cruz, Laguna', lat: '14.2786', lon: '121.4156' }],
    })
    render(<LocationPickerDialog open onOpenChange={vi.fn()} initial={null} onConfirm={vi.fn()} />)

    const map = await screen.findByTestId('picker-map')
    expect(map).toHaveTextContent('no marker')

    await userEvent.type(screen.getByLabelText('Search for a place'), 'Santa Cruz Laguna{Enter}')
    await userEvent.click(await screen.findByRole('button', { name: 'Santa Cruz, Laguna' }))

    expect(fetch.mock.calls[0][0]).toContain('q=Santa+Cruz+Laguna')
    expect(map).toHaveTextContent('view 14.2786,121.4156 z17')
    expect(map).toHaveTextContent('no marker')
    expect(screen.getByRole('button', { name: 'Confirm location' })).toBeDisabled()
  })

  it('says so when map images fail, and still lets the pin be confirmed', async () => {
    const onConfirm = vi.fn()
    render(<LocationPickerDialog open onOpenChange={vi.fn()} initial={null} onConfirm={onConfirm} />)

    await userEvent.click(await screen.findByRole('button', { name: 'tiles fail' }))
    expect(screen.getByText(/Map images are not loading/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'map click' }))
    await userEvent.click(screen.getByRole('button', { name: 'Confirm location' }))
    expect(onConfirm).toHaveBeenCalledWith({ lat: 14.2814571, lng: 121.4161847 })
  })

  it('shows the saved pin on the company page with a link to open it', async () => {
    render(
      <CompanyLocationCard
        company={{ company_name: 'Tiwi Foods', office_address: 'Sta. Cruz, Laguna', latitude: 14.28, longitude: 121.41 }}
        canEdit
        onEdit={vi.fn()}
      />
    )

    expect(await screen.findByTestId('preview-map')).toHaveTextContent('marker 14.28,121.41')
    expect(screen.getByText('Sta. Cruz, Laguna')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Open in Google Maps/ })).toHaveAttribute(
      'href',
      'https://www.google.com/maps/search/?api=1&query=14.28,121.41'
    )
  })

  it('says when a company has no pin, and offers to add one', async () => {
    const onEdit = vi.fn()
    render(
      <CompanyLocationCard
        company={{ company_name: 'Tiwi Foods', office_address: 'Sta. Cruz, Laguna', latitude: null, longitude: null }}
        canEdit
        onEdit={onEdit}
      />
    )

    expect(screen.getByText('No location pinned yet.')).toBeInTheDocument()
    expect(screen.queryByTestId('preview-map')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Pin location' }))
    expect(onEdit).toHaveBeenCalled()
  })
})

afterEach(() => vi.restoreAllMocks())
beforeEach(() => vi.restoreAllMocks())
