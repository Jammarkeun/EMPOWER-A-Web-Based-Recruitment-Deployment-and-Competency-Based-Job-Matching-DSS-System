import * as React from 'react'
import { AlertCircle, Camera, Check, Loader2 } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { upload } from '@/lib/api'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import PersonAvatar from '@/components/PersonAvatar'

/*
 * The same limits the server enforces. Checked here only so a wrong file is
 * caught before it is sent; the server checks every upload again regardless.
 */
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const MAX_BYTES = 5 * 1024 * 1024
const MIN_PIXELS = 64

/** Resolves to a message describing what is wrong with the file, or null. */
function checkImage(file) {
  if (!ACCEPTED_TYPES.includes(file.type)) {
    return Promise.resolve('Use a JPG, PNG, or WebP image.')
  }

  if (file.size > MAX_BYTES) {
    return Promise.resolve('The picture must be 5 MB or smaller.')
  }

  // Decoding it is the only way to know the file is really a readable picture
  // and not something renamed to look like one.
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file)
    const image = new Image()

    image.onload = () => {
      URL.revokeObjectURL(url)
      resolve(
        image.naturalWidth < MIN_PIXELS || image.naturalHeight < MIN_PIXELS
          ? `The picture must be at least ${MIN_PIXELS} × ${MIN_PIXELS} pixels.`
          : null
      )
    }
    image.onerror = () => {
      URL.revokeObjectURL(url)
      resolve('This file could not be read as an image. It may be damaged; try saving it again.')
    }
    image.src = url
  })
}

/**
 * The applicant's profile picture: what it is now, and the way to change it.
 *
 * Used on both Profile and Settings, reading the one copy of the picture held
 * with the signed-in account. A successful upload updates that copy, so the
 * header, the overview greeting, and both of these pages change together
 * without a reload.
 *
 * Choosing a file only previews it. Nothing is sent until the applicant saves,
 * and the current picture stays in place until the server confirms that the new
 * one is stored - a failed upload never leaves them with no picture.
 */
export default function ProfilePhotoUploader() {
  const { user, mergeUser, renewPhoto } = useAuth()
  const toast = useToast()
  const inputRef = React.useRef(null)

  const [selected, setSelected] = React.useState(null)
  const [checking, setChecking] = React.useState(false)
  const [uploading, setUploading] = React.useState(false)
  const [error, setError] = React.useState(null)

  const hasPhoto = Boolean(user?.photo_url)

  // The preview is a local object URL; it is released whenever it is replaced
  // or the page is left.
  React.useEffect(() => {
    if (!selected) return undefined
    return () => URL.revokeObjectURL(selected.previewUrl)
  }, [selected])

  async function handleChosen(event) {
    const file = event.target.files?.[0]
    // Cleared so choosing the same file again still fires a change.
    event.target.value = ''
    if (!file) return

    setError(null)
    setChecking(true)
    const problem = await checkImage(file)
    setChecking(false)

    if (problem) {
      setError(problem)
      return
    }

    setSelected({ file, previewUrl: URL.createObjectURL(file) })
  }

  async function save() {
    if (!selected || uploading) return

    setUploading(true)
    setError(null)

    const form = new FormData()
    form.append('photo', selected.file)

    try {
      const response = await upload('/portal/profile/photo', form)
      mergeUser({ photo_url: response.data?.photo_url ?? null })
      setSelected(null)
      toast.success('Profile picture updated', response.message)
    } catch (failure) {
      setError(
        failure?.errors?.photo?.[0] ?? failure?.message ?? 'The picture could not be uploaded. Please try again.'
      )
    } finally {
      setUploading(false)
    }
  }

  function cancel() {
    setSelected(null)
    setError(null)
  }

  const busy = checking || uploading

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Profile picture</CardTitle>
        <CardDescription>
          Shown on your account, and to the CDE staff handling your application so they can recognise you.
        </CardDescription>
      </CardHeader>

      <CardContent>
        <div className="flex flex-col items-center gap-5 sm:flex-row">
          <PersonAvatar
            src={selected?.previewUrl ?? user?.photo_url}
            name={user?.full_name}
            size="xl"
            alt={selected ? 'Preview of your new profile picture' : hasPhoto ? 'Your profile picture' : 'No profile picture yet'}
            onError={selected ? undefined : renewPhoto}
            className={uploading ? 'opacity-60' : undefined}
          />

          <div className="w-full space-y-3 text-center sm:text-left">
            {selected ? (
              <>
                <p className="text-sm">
                  This is how your new picture will look.{' '}
                  <span className="text-muted-foreground">Save it to replace {hasPhoto ? 'your current one' : 'the initials'}.</span>
                </p>
                <div className="flex flex-wrap justify-center gap-2 sm:justify-start">
                  <Button onClick={save} disabled={uploading}>
                    {uploading ? <Loader2 className="animate-spin" /> : <Check />}
                    {uploading ? 'Uploading…' : 'Save picture'}
                  </Button>
                  <Button variant="outline" onClick={cancel} disabled={uploading}>
                    Cancel
                  </Button>
                </div>
              </>
            ) : (
              <>
                <p className="text-sm text-muted-foreground">
                  JPG, PNG, or WebP, up to 5 MB. A clear photo of your face works best.
                </p>
                <Button
                  variant={hasPhoto ? 'outline' : 'default'}
                  onClick={() => inputRef.current?.click()}
                  disabled={busy}
                >
                  {checking ? <Loader2 className="animate-spin" /> : <Camera />}
                  {hasPhoto ? 'Change picture' : 'Upload picture'}
                </Button>
              </>
            )}

            {error && (
              <p role="alert" className="flex items-start justify-center gap-1.5 text-sm text-status-danger-text sm:justify-start">
                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <span>{error}</span>
              </p>
            )}
          </div>
        </div>

        <input
          ref={inputRef}
          type="file"
          accept={ACCEPTED_TYPES.join(',')}
          className="sr-only"
          tabIndex={-1}
          aria-hidden="true"
          onChange={handleChosen}
        />
      </CardContent>
    </Card>
  )
}
