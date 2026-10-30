import * as React from 'react'
import { Check, Copy } from 'lucide-react'
import { Button } from '@/components/ui/button'

/**
 * A one-time password, shown once to the officer who generated it.
 *
 * The server keeps only its hash, so this is the only time anybody can read
 * it. The account holder is made to replace it the first time they sign in,
 * which is what stops the office from knowing anybody's working password.
 */
export function TemporaryPassword({ email, password, notice }) {
  const [copied, setCopied] = React.useState(false)

  async function copy() {
    try {
      await navigator.clipboard.writeText(password)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard access can be refused; the password is still on screen.
    }
  }

  return (
    <div className="rounded-md border border-success/40 bg-success/5 p-4">
      <p className="text-sm font-medium">Temporary password issued</p>
      {notice && <p className="mt-1 text-sm text-muted-foreground">{notice}</p>}

      <div className="mt-3 space-y-2">
        {email && (
          <div>
            <p className="text-xs text-muted-foreground">Email</p>
            <p className="font-mono text-sm">{email}</p>
          </div>
        )}
        <div>
          <p className="text-xs text-muted-foreground">Temporary password</p>
          <div className="flex items-center gap-2">
            <code className="rounded bg-muted px-2 py-1 font-mono text-sm" data-testid="temporary-password">
              {password}
            </code>
            <Button type="button" variant="ghost" size="sm" onClick={copy}>
              {copied ? <Check className="h-3.5 w-3.5 text-success" /> : <Copy className="h-3.5 w-3.5" />}
              {copied ? 'Copied' : 'Copy'}
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}
