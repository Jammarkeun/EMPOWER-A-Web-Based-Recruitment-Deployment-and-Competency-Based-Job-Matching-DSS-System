# Work schedules: coordinator writes, HR approves, DTR compared (4 November 2026)

This document records how a deployed worker's schedule now reaches the DTR
comparison. Before this change only HR could set a schedule. Now the
coordinator at the client writes it down, HR approves or returns it, and only
an approved schedule is compared with the DTR. Everything else about
attendance (the comparison, the review queue, the import, leave and holidays)
is unchanged and is described in `dtr-attendance-rework.md`.

## 1. What already existed

- **Schedules.** `employee_schedules`, set by HR with `attendance.manage` on the
  employee's Attendance (DTR) tab. Each one is stamped with its placement and
  can be corrected (with a reason) or voided, never deleted. A new schedule
  from a date closes the previous one the day before.
- **Comparison.** `AttendanceComparison` reads only schedules in force
  (`status = active`). It handles night shifts, rest days, leave and holidays.
  A day without a DTR on file is "Awaiting DTR", never an absence.
- **Review.** The review queue (`attendance.review`) and the DTR import
  (provisional, with column mapping) were already in place.
- **Coordinators.** They saw a read-only monthly summary and could not touch
  schedules.

## 2. What was added

```
Coordinator                                HR (attendance.manage)
───────────                                ──────────────────────
draft ──submit──▶ submitted (Pending HR review) ──approve──▶ approved ─▶ employee_schedules row
  │                   │                                                 (compared with the DTR)
  │                   └──return (reason required)──▶ returned ──edit + submit──▶ submitted …
  └──────── withdraw (draft, submitted or returned) ──▶ withdrawn (kept on file)
```

- **Table `schedule_submissions`** holds the proposal, in the same shape as a
  schedule, plus `source_note` (where the hours came from), the status, the
  schedule it would replace, and the schedule its approval created.
- **Table `schedule_submission_events`** records every step: who took it,
  from which status to which, the note, and on an edit the fields that changed.
- **Approval** calls `AttendanceService::setSchedule`, the same method HR's
  direct entry uses. That method now locks the employee row, so two schedules
  for the same person are checked one after the other. It also accepts an
  optional end date.

### Screens

| Who | Where | What |
| --- | --- | --- |
| Coordinator | **Work Schedules** (new sidebar item) | Their client's schedules. Returned ones are listed first. |
| Coordinator | `/coordinator/schedules/new`, `/coordinator/schedules/{id}` | A form while the schedule is a draft or returned; read-only once it is with HR or decided. Shows the approved schedule now in force, the server's checks, and the history. |
| Coordinator | Employee page → **Work schedule** card | The approved schedule, the schedules sent to HR, and "Propose schedule" or "Propose a change". |
| HR | Attendance (DTR) → **Schedules** tab | A queue with search and filters by client, coordinator, status and start date. Opening a schedule shows the employee's placement, the proposal beside the approved schedule (changed rows marked), the checks, the history, and **Approve** / **Return**. |
| HR | Employee → Attendance (DTR) tab | Schedules waiting for HR are listed with a link to the queue. The schedule in force says who proposed it and who approved it. |

## 3. Rules

| # | Rule | Enforced in |
| --- | --- | --- |
| W1 | Submitting approves nothing. A schedule waiting for HR, or returned, is never compared with the DTR. | Only `employee_schedules` rows are compared. |
| W2 | Only a user with `attendance.manage` can approve or return a schedule. Coordinators never hold it. | Route policy and `ScheduleSubmissionService::review` |
| W3 | Nobody reviews a schedule they wrote. | Service (403) |
| W4 | Returning needs a written reason, which is shown to the coordinator and notified to them. | Service (422) |
| W5 | An approved schedule is never edited from here. A change is a new submission from a later date, which closes the old schedule the day before. | Service (409 on edit or withdraw) |
| W6 | Schedules only move forward. A schedule cannot start on or before the start of one already approved. | `setSchedule`, under an employee row lock |
| W7 | Only one schedule per employee can be with HR at a time (submitted or returned). | Service, under an employee row lock |
| W8 | A coordinator cannot back-date a schedule over days already compared with an approved one. If time passes while a schedule waits and approving would change past days, HR must tick a confirmation. Reviews already made keep the snapshot they were made on. | Service (422; `confirm_replaces_past`) |
| W9 | The schedule must start on a day the worker is placed at the coordinator's client, on or after the hire date. The break must be shorter than the shift. | Service (same rules as HR's entry) |
| W10 | A source is required before submitting. | Service (422) |
| W11 | A coordinator sees only their assigned client. A draft is visible only to the coordinator who wrote it. Any coordinator of the client may correct a returned schedule, in case its writer was reassigned. | `CoordinatorScoped` and the service |

**Checks shown to HR** are worked out from the data as it stands when the
schedule is opened. An error blocks approval: end before start, before the hire
date, no placement, placement changed, a later approved schedule, or another
schedule already with HR. The warnings are: changes past days (needs
confirmation), no rest day, no change from the current schedule, no source,
employee no longer active. Back-dating into days that had no schedule is shown
as information only.

**HR's direct entry is kept.** HR can still set, correct and void schedules on
the employee's tab, for example for a client with no coordinator. Such a
schedule shows "set by" instead of "proposed by … approved by".

## 4. Audit and notifications

- Audit (`module = attendance`, `record_type = ScheduleSubmission`):
  `create`, `update`, `submit`, `return`, `approve`, `withdraw`. The
  `EmployeeSchedule` `create` entry written on approval carries the
  submission's code.
- Notifications (category `coordination`): `ScheduleSubmitted` goes to every
  active user holding `attendance.manage`, linked to
  `/attendance?tab=schedules&submission={id}`. `ScheduleReviewed` goes to the
  coordinator who wrote the schedule, linked to `/coordinator/schedules/{id}`.

## 5. Database

- Migration `2026_11_04_000200_create_schedule_submissions_tables` is additive:
  two new tables, no change to an existing column or row.
- Timestamps are nullable (MariaDB first-timestamp trap). Foreign key and index
  names are within MySQL's and PostgreSQL's limits. Row-level security is
  enabled on PostgreSQL, as for the other tables.
- **Not yet applied to local MariaDB or to Supabase.** The employee Attendance
  (DTR) tab and the coordinator's employee page read the new table, so run
  `php artisan migrate --path=database/migrations/2026_11_04_000200_create_schedule_submissions_tables.php`
  on each (after a backup) before using them.

## 6. DTR data entry and import

Unchanged. The import remains provisional and column-mapped (see
`dtr-attendance-rework.md` §4). It reads one row per employee per day, and
duplicate and invalid rows are refused and reported. No sample of CDE's or the
client's export has been received, and EMPOWER is not connected to any
biometric device.

## 7. Tests

- Backend `tests/Feature/ScheduleSubmissionTest.php` (12 tests) covers:
  - submit, pending, approve
  - client isolation, including a client id sent in the body
  - drafts belonging to another coordinator
  - who may decide, and self-review
  - return with a reason, then resubmit
  - approved schedules not overwritten
  - conflicts
  - back-dating and its confirmation
  - the comparison by date: night shift, rest day, leave, a complete DTR period, awaiting DTR
  - the audit trail
  - consistency between the HR, profile and coordinator views
  - field rules
- Frontend:
  - `CoordinatorSchedule.test.jsx` (4 tests)
  - `ScheduleSubmissionDialog.test.jsx` (5 tests)
- The existing attendance tests (`AttendanceTest`, `AttendanceReviewTest`,
  `AttendanceImportTest`, `AttendanceConsistencyTest`) still pass. They cover
  late arrival, early departure, missing punches, imports, duplicates, the
  review permission, and the two views agreeing.

## 8. Open questions for CDE

1. Who at the client officially gives the agency a worker's schedule (HR, the
   line supervisor, a memo), and should EMPOWER keep a copy of it (an
   attachment) rather than a note?
2. Who may authorise a schedule change, and how far ahead? May a change ever
   apply to days already worked?
3. Should HR keep setting schedules directly, or should every schedule come
   from the coordinator?
4. What format is the client's DTR export: one row per day, or one per punch?
   A sample with names removed would settle it.
5. Is there a grace period for late arrival, and are breaks deducted?
   (Unchanged from `dtr-attendance-rework.md` §8.)
