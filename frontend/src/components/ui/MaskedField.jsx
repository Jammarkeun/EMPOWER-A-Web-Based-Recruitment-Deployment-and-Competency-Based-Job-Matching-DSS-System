import * as React from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { Button } from './button'
import { cn } from '@/lib/utils'

/**
 * MaskedField renders sensitive government IDs or personal identification numbers
 * with a toggleable reveal feature for Data Privacy Act (RA 10173) compliance.
 */
export default function MaskedField({ value, label, className, maskChar = '•' }) {
  const [revealed, setRevealed] = React.useState(false)

  if (!value) {
    return <span className="text-muted-foreground">—</span>
  }

  const strValue = String(value)
  const maskedValue = strValue.length > 4
    ? strValue.slice(0, 3) + maskChar.repeat(Math.max(4, strValue.length - 5)) + strValue.slice(-2)
    : maskChar.repeat(strValue.length)

  return (
    <div className={cn('inline-flex items-center gap-1.5 font-mono text-sm', className)}>
      <span>{revealed ? strValue : maskedValue}</span>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="h-6 w-6 text-muted-foreground hover:text-foreground"
        onClick={() => setRevealed((prev) => !prev)}
        aria-label={revealed ? `Hide ${label ?? 'sensitive field'}` : `Show ${label ?? 'sensitive field'}`}
        title={revealed ? 'Hide sensitive data' : 'Click to reveal sensitive data'}
      >
        {revealed ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
      </Button>
    </div>
  )
}

