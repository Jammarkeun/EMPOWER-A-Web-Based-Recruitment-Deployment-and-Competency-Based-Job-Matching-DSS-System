import * as React from 'react'
import { Sun, Moon, Monitor } from 'lucide-react'
import { useTheme } from '@/contexts/ThemeContext'
import { Button } from '@/components/ui/button'

const LABELS = {
  light: 'Light theme',
  dark: 'Dark theme',
  system: 'Following your system setting',
}

const ICONS = {
  light: Sun,
  dark: Moon,
  system: Monitor,
}

/**
 * Cycles light, dark, and system.
 *
 * A cycling button rather than a dropdown: three options do not justify a menu,
 * and the current state is legible from the icon alone. The title and aria-label
 * name the state in words so it is not carried by the icon shape alone.
 */
export default function ThemeToggle({ className }) {
  const { theme, cycle } = useTheme()
  const Icon = ICONS[theme] ?? Monitor

  return (
    <Button
      variant="ghost"
      size="icon"
      onClick={cycle}
      className={className}
      title={LABELS[theme]}
      aria-label={`${LABELS[theme]}. Click to change.`}
    >
      <Icon className="h-4 w-4" />
    </Button>
  )
}
