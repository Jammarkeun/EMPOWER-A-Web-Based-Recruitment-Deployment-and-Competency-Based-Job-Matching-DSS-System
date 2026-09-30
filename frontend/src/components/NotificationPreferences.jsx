import * as React from 'react'
import { Bell, Loader2, Save } from 'lucide-react'
import { useApi } from '@/hooks/useApi'
import { patch } from '@/lib/api'
import { useNotifications } from '@/contexts/NotificationsContext'
import { useToast } from '@/components/ui/toast'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { LoadingState, ErrorState } from '@/components/ui/states'
import { cn } from '@/lib/utils'

/**
 * Which notifications arrive.
 *
 * Switching one off stops the alert and nothing else: the document is still
 * checked, the placement is still recorded. Saying so plainly matters, because
 * somebody muting a category needs to know they are not opting out of the
 * process itself.
 */
export default function NotificationPreferences() {
  const { data, loading, error, refetch, setData } = useApi('/auth/notification-preferences')
  const { refresh } = useNotifications()
  const toast = useToast()
  const [saving, setSaving] = React.useState(false)

  if (loading) return <LoadingState label="Loading your notification settings…" />
  if (error) {
    return (
      <Card>
        <ErrorState message="Unable to load notification settings." onRetry={refetch} />
      </Card>
    )
  }
  if (!data) return null

  function toggle(key) {
    setData((current) => ({
      ...current,
      categories: current.categories.map((category) =>
        category.key === key ? { ...category, enabled: !category.enabled } : category
      ),
    }))
  }

  async function save() {
    if (saving) return

    setSaving(true)

    const preferences = Object.fromEntries(
      data.categories.map((category) => [category.key, category.enabled])
    )

    try {
      const response = await patch('/auth/notification-preferences', { preferences })
      // Muting a category removes nothing already received, but the badge
      // should still be re-read so it agrees with what will arrive next.
      refresh()
      toast.success('Saved', response.message)
    } catch (err) {
      toast.error('Could not save your settings', err.message)
      refetch()
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <Bell className="h-4 w-4" />
          Notifications
        </CardTitle>
        <CardDescription>
          Turning one off only stops the alert. Your documents are still checked and your
          application still moves.
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-3">
        <ul className="divide-y">
          {data.categories.map((category) => (
            <li key={category.key} className="flex items-center justify-between gap-3 py-2.5">
              <span className="text-sm">{category.label}</span>
              <button
                type="button"
                role="switch"
                aria-checked={category.enabled}
                aria-label={category.label}
                onClick={() => toggle(category.key)}
                className={cn(
                  'relative h-5 w-9 shrink-0 rounded-full transition-colors',
                  category.enabled ? 'bg-primary' : 'bg-input'
                )}
              >
                <span
                  className={cn(
                    'absolute top-0.5 h-4 w-4 rounded-full bg-card shadow transition-transform',
                    category.enabled ? 'translate-x-[1.125rem]' : 'translate-x-0.5'
                  )}
                />
              </button>
            </li>
          ))}
        </ul>

        <div className="flex justify-end">
          <Button onClick={save} disabled={saving}>
            {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
            {saving ? 'Saving…' : 'Save preferences'}
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
