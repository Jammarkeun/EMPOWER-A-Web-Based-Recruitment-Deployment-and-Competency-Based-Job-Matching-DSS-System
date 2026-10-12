import { clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

/**
 * Merges Tailwind classes so a caller's override actually wins.
 * Without twMerge, passing `p-6` to a component that already sets `p-4` leaves
 * both in the class list and the outcome depends on stylesheet order.
 */
export function cn(...inputs) {
  return twMerge(clsx(inputs))
}

/** Turns a snake_case status into a human label: "ready_for_deployment" -> "Ready For Deployment". */
export function humanise(value) {
  if (!value) return ''
  return String(value)
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

/**
 * Where an account belongs after signing in.
 *
 * Portal accounts go to the portal, coordinators to their client workspace, and
 * staff to the dashboard. None of them should land on a screen built for
 * another kind of account; the API refuses those screens' data anyway.
 */
export function homePathFor(user) {
  if (['applicant', 'employee'].includes(user?.user_type)) return '/portal'
  if (user?.user_type === 'coordinator') return '/coordinator'
  return '/dashboard'
}

/**
 * Today as YYYY-MM-DD on this computer's calendar, for date inputs and their
 * limits. toISOString() gives the UTC date, which in Manila is still
 * yesterday until 8 AM.
 */
export function todayISO() {
  const now = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

export function formatDate(value, fallback = '—') {
  if (!value) return fallback
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return fallback
  return date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
}

export function formatDateTime(value, fallback = '—') {
  if (!value) return fallback
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return fallback
  return date.toLocaleString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function formatNumber(value) {
  if (value === null || value === undefined) return '—'
  return Number(value).toLocaleString('en-PH')
}

/**
 * Maps a workflow value to a badge tone.
 *
 * Deliberately explicit rather than derived: "terminated" and "resigned" are
 * both endings but carry very different weight, and an HR officer scanning a
 * list should not have to read carefully to tell them apart.
 */
export function statusTone(status) {
  const tones = {
    // Applicant lifecycle
    applied: 'muted',
    initial_screening: 'muted',
    incomplete_requirements: 'warning',
    primary_requirements_complete: 'info',
    pending_final_requirements: 'warning',
    ready_for_deployment: 'success',
    training_scheduled: 'info',
    training_completed: 'info',
    client_evaluation: 'info',
    approved: 'success',
    deployed: 'success',
    active: 'success',
    resigned: 'muted',
    terminated: 'destructive',
    archived: 'muted',

    // Requests
    open: 'info',
    in_progress: 'info',
    partially_fulfilled: 'warning',
    fulfilled: 'success',
    closed: 'muted',
    cancelled: 'muted',

    // Documents
    missing: 'destructive',
    submitted: 'info',
    pending: 'warning',
    verified: 'success',
    rejected: 'destructive',
    expired: 'destructive',

    // Separations. A termination awaiting review is the one that needs
    // somebody's attention; a withdrawn one is history that ended quietly.
    filed: 'info',
    accepted: 'info',
    completed: 'muted',
    withdrawn: 'muted',
    draft: 'muted',
    for_review: 'warning',
    finalized: 'destructive',

    // Recommendation bands
    highly_recommended: 'success',
    recommended: 'info',
    reserve_pool: 'warning',
    not_recommended: 'destructive',

    // Folders
    folder_1: 'success',
    folder_2: 'info',
    folder_3: 'muted',
  }

  return tones[status] ?? 'muted'
}
