/**
 * How a document's expiry reads, given where its holder is in their employment.
 *
 * The server decides the state (App\Support\DocumentCompliance); this only
 * words it, once, for both the staff screens and the portal. CDE, 26 October
 * 2026: an NBI clearance matters only upon employment; the annual medical and
 * the health card are renewed while employed; anything CDE has not ruled on is
 * shown as unconfirmed, never as a lapse to act on.
 */
const STATES = {
  expiring: { tone: 'warning', staff: 'Expiring soon', portal: 'Expiring soon' },
  expired: { tone: 'destructive', staff: 'Expired', portal: 'Expired' },
  renewal_due: { tone: 'warning', staff: 'Renewal due', portal: 'Renewal due' },
  renewal_overdue: { tone: 'destructive', staff: 'Renewal overdue', portal: 'Renewal overdue' },
  lapsed_after_deployment: {
    tone: 'muted',
    staff: 'Lapsed after deployment — not required',
    portal: 'Not needed while employed',
  },
  lapsed_rule_unconfirmed: {
    tone: 'muted',
    staff: 'Lapsed — rule after deployment unconfirmed',
    portal: 'Expired',
  },
}

/** The notable states only; valid, no-expiry and untracked documents return null. */
export function expiryBadge(requirement, audience = 'staff') {
  const assessment = requirement?.expiry_assessment
  const meta = assessment && STATES[assessment.state]
  if (!meta) return null
  return { tone: meta.tone, label: meta[audience], needsAction: assessment.needs_action, assessment }
}

/** A worker may send a renewal of an accepted document once it is due. */
export function isRenewalDue(requirement) {
  return ['renewal_due', 'renewal_overdue'].includes(requirement?.expiry_assessment?.state)
}

/** One sentence for the portal's detail line. */
export function portalExpiryDetail(requirement, formatDate) {
  const state = requirement?.expiry_assessment?.state
  const date = formatDate(requirement?.expiry_date)

  switch (state) {
    case 'renewal_due':
      return `Expires on ${date}. Please send the renewed document.`
    case 'renewal_overdue':
      return `Expired on ${date}. Please send the renewed document.`
    case 'lapsed_after_deployment':
      return `Expired on ${date}. CDE does not require this to be renewed while you are employed.`
    case 'lapsed_rule_unconfirmed':
      return `Expired on ${date}. The office will tell you if it needs renewing.`
    default:
      return null
  }
}
