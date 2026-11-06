# Termination review and pre-termination clearance

Built 2026-11-03 on `feature/cde-requirements-integration`. Migration
`2026_11_03_000300_add_termination_review_and_clearance` was applied to local
MariaDB after a backup
(`EMPOWER-db-backups/empower-2026-11-03-before-termination-review-migration.sql`).
**Supabase is not migrated.**

## 1. What was wrong

| Finding | Cause |
|---|---|
| Administrators were never told a termination request was waiting | `SeparationService::fileTermination` sent no notification at all. The only way to find a request was to open the Separations list |
| Approving a request terminated the employee immediately | There was no approval step. The administrator's "finalise" was both the decision and the termination, and `approved_by` recorded whoever finalised |
| No way to return or reject a request | The only outcomes were finalise or withdraw. HR could not correct a filed request |
| Clearance came after the dismissal and gated nothing | Our default S9. One `clearance_status` select, recorded after finalisation |
| Two requests for one employee could race | No row lock and no database guard, unlike resignations |

## 2. The lifecycle now

```
HR files ── draft ──submit──► for_review ──approve──► approved ──finalise (HR)──► finalized
                                 │   ▲                   │  (clearance in progress
                                 │   └──resubmit── returned   → ready for finalisation)
                                 └──reject──► rejected
any open status ──withdraw──► withdrawn
```

- One row per case (`terminations`); the request and the final event stay on it.
  "Clearance in progress" and "Ready for finalisation" are read from the
  checklist, not stored.
- **Approval changes nothing about the employee**: no status change, placement
  still active, no archive, no notice.
- The administrator who reviews may not be the one who filed (403).
- Finalisation re-checks, under row locks, that the request is `approved`, that
  the employee is still `active`, and that every mandatory clearance item is
  `cleared` or `not_applicable`. Then, in one transaction: the employee moves to
  `terminated` (status history), the placement ends on the termination date, the
  record is archived, and the applicant record is closed.
- Permissions: `separation.review_termination` (new, administrators);
  `separation.finalize_termination` now also held by HR. Coordinators hold
  neither.

## 3. The clearance checklist

Copied onto the request when it is approved, from
`config/empower.php → separation.termination_clearance`:

| Item | Kind | Mandatory | Rule |
|---|---|---|---|
| Outstanding obligations verified | obligation | yes | "What was verified, and with whom" is required to clear it: EMPOWER holds no loan records |
| Violation history reviewed | record review | yes | Only "pending review" or "reviewed". Confirms it was read; its content blocks nothing |

Statuses: pending review, requires action, awaiting verification, cleared,
not applicable (reason required; obligations only). Each change records who
verified it and when, a case-history entry, and an audit row. None of it is sent
to the employee.

**Changed 2026-11-03 (same day):**
- The item "Clearance signed by each office concerned" was removed. It was our
  assumption, not a step CDE described. Requests approved before the change keep
  their row in `termination_clearance_items`, untouched, but
  `TerminationClearanceItem::RETIRED` keeps it out of the display, the count,
  editing (409), and the finalisation check.
- The `Reference` field was removed from the clearance forms and the API. The
  `reference` column stays in the table, unused, so nothing recorded there is
  lost. The request reference (`TRM-00005`) is a different thing and is
  unchanged.

## 4. Records shown before finalising

- **Loans and obligations**: there is no loan, salary-advance or cash-advance
  data in EMPOWER. The page says so instead of showing an empty list. The outcome
  is recorded on the obligations item. Nothing is computed, deducted or settled.
- **Violations**: the official `employee_violations` record (HR-validated),
  with expired offences marked; separately, coordinator reports that were never
  validated (submitted, under review, returned, dismissed), labelled "reports,
  not findings". Drafts are left out. Nothing is changed or recalculated.
- **Incidents**: `incident_reports` have no employee column. The page lists
  site reports from the employee's own placements (same company, same department
  or whole site, inside the deployment dates) as context only. None is attributed
  to the employee, and none is a clearance item.

## 5. Notifications

| Event | Recipient | Title |
|---|---|---|
| Submitted / resubmitted | Every active `separation.review_termination` holder except the filer | Termination request pending review / Corrected termination request pending review |
| Approved / returned / rejected | The filer (or, if that account is inactive, finalisers) | Termination request approved - clearance needed / returned for correction / rejected |
| Finalised | The approver and the filer, not the finaliser | Termination finalised |
| Finalised | The employee (portal) | A notice about your employment (unchanged) |

All are sent after the commit, and only when the save actually moved the status.
Each links to `/separations/terminations/{id}`. The employee is told nothing
before finalisation.

## 6. Existing records

- Terminations finalised under the one-step process: `finalized_by` was set from
  `approved_by` (the administrator who finalised). They get no approval date and
  no checklist, are shown as "finalised under the earlier process", and keep the
  after-the-fact clearance select.
- `for_review` requests: `submitted_at` was set from `created_at` (filing with
  that status was the only way to reach it). They now wait for an administrator.
- Nothing else was backfilled, and no case changed status.

## 7. For CDE to confirm

- **T1** Is this clearance list right for a termination: an obligations check
  and a review of the violation history? Is anything else checked? (C7)
- **T2** Must obligations be settled before separation, or is deduction from the
  final pay enough? The current rule records either as long as it is written down.
- **T3** Who keeps loan and cash-advance records, and should EMPOWER ever hold
  them? Payroll stays out of scope.
- **T4** Is one administrator's approval enough, or does a termination need
  another sign-off?
