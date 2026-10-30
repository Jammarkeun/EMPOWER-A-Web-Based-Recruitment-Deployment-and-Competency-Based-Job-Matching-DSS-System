import { describe, expect, it } from 'vitest'
import { expiryBadge, isRenewalDue, portalExpiryDetail } from './documentExpiry'

const doc = (state, extra = {}) => ({ expiry_date: '2026-12-31', expiry_assessment: { state, needs_action: false }, ...extra })
const fmt = (d) => d

describe('document expiry wording', () => {
  it('says nothing for a valid document or one without an expiry', () => {
    expect(expiryBadge(doc('valid'))).toBeNull()
    expect(expiryBadge(doc('no_expiry'))).toBeNull()
    expect(expiryBadge({})).toBeNull()
  })

  it('never calls a deployed worker’s lapsed NBI clearance a problem', () => {
    const badge = expiryBadge(doc('lapsed_after_deployment'), 'portal')
    expect(badge.tone).toBe('muted')
    expect(badge.label).toBe('Not needed while employed')
    expect(portalExpiryDetail(doc('lapsed_after_deployment'), fmt)).toMatch(/does not require this to be renewed/)
  })

  it('keeps an unconfirmed rule apart from a real lapse for staff', () => {
    expect(expiryBadge(doc('lapsed_rule_unconfirmed')).label).toMatch(/unconfirmed/)
    expect(expiryBadge(doc('expired')).tone).toBe('destructive')
  })

  it('lets a worker renew only what is due', () => {
    expect(isRenewalDue(doc('renewal_due'))).toBe(true)
    expect(isRenewalDue(doc('renewal_overdue'))).toBe(true)
    expect(isRenewalDue(doc('lapsed_after_deployment'))).toBe(false)
    expect(isRenewalDue(doc('valid'))).toBe(false)
  })
})
