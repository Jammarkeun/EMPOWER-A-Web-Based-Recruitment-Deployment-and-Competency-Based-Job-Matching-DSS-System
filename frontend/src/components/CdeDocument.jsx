import * as React from 'react'
import { LogoMark } from '@/components/Logo'
import { cn } from '@/lib/utils'

/**
 * The parts of CDE's own paper form that every printed EMPOWER document
 * shares: the letterhead and the signature line.
 *
 * Copied from CDE's Manpower Requisition Form (received 29 October 2026). The
 * server's PDF and Excel reports draw the same thing in
 * resources/views/reports/document.blade.php and App\Exports\ReportExport.
 */

/** As printed on CDE's form. Reports pass the agency's details from Settings instead. */
export const CDE_LETTERHEAD = {
  agency: 'CDE Manpower Services',
  address: 'Sitio Bigayan, Brgy. Calios, Santa Cruz, Laguna',
}

/**
 * Logo on the left, the agency and its address centred, the document's title
 * in bold capitals beneath. The caller draws the ruling around it.
 */
export function CdeLetterhead({ title, agency = CDE_LETTERHEAD.agency, address = CDE_LETTERHEAD.address, className }) {
  return (
    <header className={cn('flex items-center gap-4 px-4 py-3', className)}>
      <LogoMark size="lg" plain />
      <div className="flex-1 text-center">
        <p className="text-[13pt]">{agency}</p>
        <p>{address}</p>
        <p className="mt-2 font-bold tracking-wide">{String(title ?? '').toUpperCase()}</p>
      </div>
      {/* Balances the logo so the lettering sits in the middle of the page. */}
      <div className="w-12" aria-hidden="true" />
    </header>
  )
}

/** "Label:" then the name in capitals over the line, with a note beneath it. */
export function SignatureLine({ label, name, note, className }) {
  return (
    <div className={cn('flex items-end gap-2', className)}>
      <span className="shrink-0">{label}</span>
      <div className="w-64 text-center">
        <p className="min-h-[1.25rem] border-b border-black font-semibold uppercase">{name}</p>
        {note && <p className="text-[9pt]">{note}</p>}
      </div>
    </div>
  )
}
