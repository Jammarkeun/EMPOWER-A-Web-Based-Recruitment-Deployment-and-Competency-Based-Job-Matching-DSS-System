import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { authWith, fail, ok, renderAt, toastDouble } from '@/test/doubles'

const api = vi.hoisted(() => ({ upload: vi.fn() }))
const auth = vi.hoisted(() => ({ current: null }))
const toast = vi.hoisted(() => ({ current: null }))
vi.mock('@/lib/api', () => api)
vi.mock('@/contexts/AuthContext', () => ({ useAuth: () => auth.current }))
vi.mock('@/components/ui/toast', () => ({ useToast: () => toast.current }))

const { default: ProfilePhotoUploader } = await import('./ProfilePhotoUploader')

/*
 * jsdom decodes no images and has no object URLs, so these stand in: every
 * chosen file reads as a 300 × 300 picture, which the uploader accepts.
 */
class DecodedImage {
  naturalWidth = 300
  naturalHeight = 300
  set src(_url) {
    queueMicrotask(() => this.onload?.())
  }
}

function chooseAndSave(container) {
  const user = userEvent.setup()
  const picture = new File(['png-bytes'], 'me.png', { type: 'image/png' })

  return user
    .upload(container.querySelector('input[type="file"]'), picture)
    .then(() => user.click(screen.getByRole('button', { name: /save picture/i })))
    .then(() => picture)
}

describe('ProfilePhotoUploader', () => {
  beforeEach(() => {
    vi.stubGlobal('Image', DecodedImage)
    URL.createObjectURL = vi.fn(() => 'blob:preview')
    URL.revokeObjectURL = vi.fn()
    toast.current = toastDouble()
    auth.current = { ...authWith([]), mergeUser: vi.fn(), renewPhoto: vi.fn() }
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('saves a staff member’s picture to their own account and shows it at once', async () => {
    api.upload.mockReturnValue(ok({ photo_url: '/signed/staff.png' }, { message: 'Your profile picture has been updated.' }))
    const { container } = renderAt(
      <ProfilePhotoUploader endpoint="/auth/profile/photo" description="Shown beside your name at the top of every page." />
    )

    expect(screen.getByText('Shown beside your name at the top of every page.')).toBeInTheDocument()

    const picture = await chooseAndSave(container)

    await waitFor(() => expect(api.upload).toHaveBeenCalledTimes(1))
    const [endpoint, form] = api.upload.mock.calls[0]
    expect(endpoint).toBe('/auth/profile/photo')
    expect(form.get('photo')).toBe(picture)

    // The account's one copy is updated, so the header changes with it.
    await waitFor(() => expect(auth.current.mergeUser).toHaveBeenCalledWith({ photo_url: '/signed/staff.png' }))
    expect(toast.current.success).toHaveBeenCalled()
  })

  it('still saves an applicant’s picture through the portal by default', async () => {
    api.upload.mockReturnValue(ok({ photo_url: '/signed/applicant.png' }))
    const { container } = renderAt(<ProfilePhotoUploader />)

    await chooseAndSave(container)

    await waitFor(() => expect(api.upload).toHaveBeenCalledWith('/portal/profile/photo', expect.any(FormData)))
  })

  it('names the server’s reason when a picture is refused, and keeps the current one', async () => {
    api.upload.mockImplementation(() => fail({ status: 422, errors: { photo: ['Use a JPG, PNG, or WebP image.'] } }))
    const { container } = renderAt(<ProfilePhotoUploader endpoint="/auth/profile/photo" />)

    await chooseAndSave(container)

    expect(await screen.findByRole('alert')).toHaveTextContent('Use a JPG, PNG, or WebP image.')
    expect(auth.current.mergeUser).not.toHaveBeenCalled()
  })
})
