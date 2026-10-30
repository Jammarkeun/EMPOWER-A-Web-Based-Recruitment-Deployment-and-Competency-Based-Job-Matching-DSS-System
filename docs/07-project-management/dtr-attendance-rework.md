# DTR and work-schedule comparison: audit and rework (29 October 2026)

This document records why the attendance feature built after the July 2026 panel
("DTR to compare with schedule") was reworked, what it does now, and which rules
are still waiting on CDE. There is one attendance workflow in EMPOWER: the
**Attendance (DTR)** menu item, and the **Attendance (DTR)** tab on each
employee's record. Nothing parallel was added.

## 1. What was wrong

| Problem | Effect |
| --- | --- |
| A scheduled day with no time record was computed as **absent** and counted in `days_absent` | CDE said on 26 October that the DTR stays in the client's biometrics and that encoding it is hard, so most days have nothing on file. Almost every scheduled day would have been shown, including to the coordinator, as an absence. |
| A "Violation" button on an absent or late DTR row opened the official violation form, pre-filled as "absences" | Missing data could be turned into discipline in two clicks, with no verification step. |
| No review workflow | There was nowhere to record what HR found, who checked, or how a flagged day ended. |
| Clearing a day **deleted** its record; editing overwrote it; the audit entry held only the dates | The times a comparison or a violation had been based on were lost. |
| A schedule could not be corrected or voided, and had no client, department, break or status | A typo could only be "fixed" by a new schedule from a later date, and a schedule set at one client carried over to the next. |
| No import, no record of where a time came from, no protection against repeating a file | Every DTR day had to be typed in again from the client's sheet. |
| Times that never met the shift were measured against it | For example, an evening punch against a day shift produced "570 minutes late". |
| "Overtime" in the data | Implied an overtime rule that nobody has confirmed. |
| The client filter used the employee's *current* client | Past months of a worker who had since transferred were filtered wrongly. |

## 2. What it does now

- **Schedules**
  - Stamped with the placement (deployment, client, department) in force when they begin. A schedule does not apply to days at another placement.
  - May carry a break, which is shown and never deducted.
  - Can be **corrected** (with a reason; the old values go to the audit trail and appear as the schedule's correction history) or **voided** (latest only; the previous schedule runs on). Nothing is deleted.
- **The DTR** arrives two ways into the same `attendance_records`:
  1. Typed in on the employee's tab. Changing or clearing a day already on file needs a reason.
  2. Imported from the client's file on the Attendance page.

  Every change, including clearing a day, is written to `attendance_record_revisions` with its old and new times, who made it, why, and from which import.
- **DTR received.** A scheduled day with nothing on file is **Awaiting DTR**, which means not enough data to compare. It becomes **No DTR record**, a discrepancy to look into rather than an absence, only when the DTR for that day is known to have been received:
  - an import declared it covers the day for that employee, or
  - HR ticked "the DTR for this period has been read in full".
- **Leave and holidays** (`attendance_exceptions`) can be recorded for one employee or for a whole client or department. A covered day is shown as excused. Exceptions are withdrawn with a reason, never deleted.
- **Discrepancies:**
  - late
  - early departure
  - missing time-in or time-out
  - no DTR record
  - outside the assigned schedule (times on a rest day or an excused day)
  - no schedule to compare with
  - does not match the scheduled shift

  Time after the scheduled end is information only.
- **Review**
  - Every flagged day is *For review* until somebody acts on it.
  - Statuses: For review → Under verification → Verified / Corrected → Closed. A closed review is reopened only with a reason.
  - Verified, corrected and closed each need a note. Each step keeps a snapshot of what the comparison showed at the time.
  - Permission: `attendance.review` (HR and administrators), held separately from `attendance.manage`.
- **Violations.** Only a **verified** review can lead to a violation. It goes through the existing violation form, endpoint and `ViolationService`. The violation date must be the reviewed day, and the review keeps the link. Nothing records a violation automatically.
- **Coordinators** still see only the read-only monthly summary for employees at their assigned client. They never see review notes and are refused every attendance endpoint. CDE has not approved more.

## 2a. Two views of one system (follow-up, 29 October 2026)

| | Employee profile → Attendance (DTR) | Attendance (DTR) module |
| --- | --- | --- |
| Purpose | One employee: their schedule, each day's DTR against it, where every flagged day stands | Everyone: review queue, workforce summary, DTR imports, leave and holidays |
| Data entry | Their schedule (set, correct, void), typing in their DTR, their leave | Imports; client-wide holidays and closures |
| Review | Shows the status and history of each flagged day; **Open in review queue** goes to that exact day | Where review decisions are made, and where a verified day becomes a violation |
| Period | `?tab=attendance&month=YYYY-MM` | `?month=YYYY-MM` |

How they are kept as one:

- **One comparison.** Both read `AttendanceService::compare` → `AttendanceComparison`, nothing else. The frontend computes nothing.
- **One review status rule.** `AttendanceReview::statusFor()` decides whether a day is in the queue and at what status. The queue, the review transition, and the `review_status` field on every presented day all use it, so neither screen derives it.
- **Shared counts.** The profile's `review_counts` is counted the same way as the queue's `status_counts`, so "N open in the review queue" matches what the link opens.
- **Cross-links carry the employee and the month.** They use existing routes only, through `employeeAttendancePath` and `reviewQueuePath` in `frontend/src/lib/attendance.js`:
  - Profile → `/attendance?employee_id=…&month=…&review=YYYY-MM-DD`, which opens that day.
  - Queue, summary and leave lists → `/employees/{id}?tab=attendance&month=…`.
  - Malformed parameters are ignored.
  - Employee links appear only to users holding `employees.view`, which `attendance.view` does not imply.
- **Correcting a schedule is the one deliberate exception.** It re-compares that schedule's past days. It requires a reason, keeps the old values in the schedule's correction history, and reviews keep the snapshot they were made on; the correction dialog says this. A schedule *change* (new from a date) never alters earlier days.
- **Verified by `AttendanceConsistencyTest`:**
  - The same answers everywhere: profile, queue, workforce summary, day detail and coordinator summary.
  - They stay the same after a DTR correction, a review move, two overlapping imports and a schedule change.
  - Both views refuse the same people.

## 3. Rules that are ours, not CDE's

| # | Rule | Status |
| --- | --- | --- |
| R1 | Late = any time-in after the scheduled start, past a grace allowance that defaults to **0** (Settings) | CDE has not stated a grace period (client questions 21.2) |
| R2 | Early departure = any time-out before the scheduled end | Not confirmed |
| R3 | Time after the end is shown, never treated as overtime | Overtime approval unknown (21.3) |
| R4 | Breaks are shown, never deducted | Not confirmed |
| R5 | A time-out not after the time-in belongs to the next day; a lone time-out before a night shift's start is the next morning | Our reading of a night-shift DTR |
| R6 | A day is compared only when the DTR for it is known to be received | Our safeguard: missing data is not an absence |
| R7 | Exception types: approved leave, holiday, client non-working day, other | Generic categories; CDE's own leave types are unknown |

## 4. The DTR import is provisional

**No sample of the client's export has been received.** The import therefore
has no fixed layout:

- HR maps the columns of each file: employee identifier (biometrics or employee number), date and its format, time in, time out, and optionally the name and remarks.
- It reads CSV and the first sheet of an Excel workbook.
- It supports **one row per employee per day** only. A raw punch log (one row per punch) is not supported, because pairing punches into shifts needs rules CDE has not given.

Safeguards:

- The file is a draft until HR has seen the row-by-row preview.
- Rows are refused for any of these reasons:
  - unknown employee
  - employee placed at another client
  - name that does not match the employee
  - unreadable date or time
  - outside the declared period or employment, or in the future
  - listed twice with different times
- A day already on file that differs is **left alone** unless HR chooses "replace" and gives a reason.
- The same file (by SHA-256) cannot be imported twice.
- The file is kept on the private document disk, downloadable only by `attendance.manage`.

When CDE sends a sample: check it against the mapping screen, decide whether its
layout should become a saved preset, and if it is a punch log, get CDE's rule
for pairing punches before building that.

## 5. Database changes and existing data

- Migrations `2026_10_29_000100` to `000400`: two new attendance tables (`attendance_imports`, `attendance_coverage_periods`), new columns on `employee_schedules` and `attendance_records`, `attendance_record_revisions`, `attendance_exceptions`, `attendance_reviews`, `attendance_review_events`, and the `attendance.review` permission.
- **All additive.** No column or row is dropped, and each migration rolls back cleanly.
- Before the change, local MariaDB and Supabase both held **0** schedules and **0** time records (checked read-only), so there was nothing to migrate.
- On local MariaDB, the migrations were first run and rolled back on a throwaway copy made from a fresh backup. They were then applied to `empower` with all other row counts unchanged.
- **Supabase has not been migrated.** Run `php artisan migrate` there before deploying this code; the coordinator's employee page and every attendance screen need the new tables.

## 6. Endpoints (all under `/api/v1`, staff only)

| Method and path | Permission |
| --- | --- |
| `GET attendance` (workforce summary) | `attendance.view` |
| `GET attendance/discrepancies` (review queue: `from`, `to`, `client_company_id`, `client_department_id`, `employee_id`, `search`, `discrepancy`, `status`) | `attendance.view` |
| `GET employees/{id}/attendance`, `GET employees/{id}/attendance/days/{date}` | `attendance.view` |
| `POST employees/{id}/attendance/days/{date}/review` | `attendance.review` |
| `POST employees/{id}/attendance` (entries, `reason`, `complete_from`, `complete_to`) | `attendance.manage` |
| `POST employees/{id}/schedules`, `PATCH employees/{id}/schedules/{s}`, `POST …/schedules/{s}/void` | `attendance.manage` |
| `GET/POST attendance/imports`, `GET attendance/imports/{id}`, `POST …/preview`, `…/confirm`, `…/discard`, `GET …/file` | `attendance.manage` |
| `GET attendance/exceptions` (view), `POST attendance/exceptions`, `POST attendance/exceptions/{id}/void` (manage) | as stated |
| `POST employees/{id}/violations` with `attendance_review_id` | `violations.create` and `attendance.review`; review must be verified |

## 7. Tests

- **Backend** (`AttendanceTest`, `AttendanceReviewTest`, `AttendanceImportTest`): comparison rules, night shift, grace, shift mismatch, placement link, correct and void, revisions, coverage, exceptions, review transitions, violation gate, permissions, coordinator scope, CSV and XLSX import, duplicates, conflicts, discard.
- **Frontend**: `Attendance.test.jsx`, `AttendancePanel.test.jsx`.
- **End to end over HTTP on a MariaDB copy:** 30 of 30 checks.
- **Not run:** PostgreSQL. The queries use the database-agnostic builder (`whereDate`, no raw SQL), but CI on PostgreSQL 17 is the first real check.

## 8. Open questions for CDE

Added to `client-questions-part-3.md`, Part 21:

1. Can the client export the DTR, and could CDE share a sample with names removed?
2. Is that export one row per day, or one row per punch?
3. How does the client tell CDE about approved leave and holidays?
4. Is there a grace period, and how is overtime approved?
5. Should coordinators see more attendance detail than the monthly summary?
