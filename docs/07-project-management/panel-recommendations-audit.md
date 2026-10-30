# Panel recommendations against the built system

An audit of the **Summary of Recommendations** from the title proposal defense
(Group BWD7, 15 July 2026) against EMPOWER as it stood on 8 October 2026, and
what was changed in response. Every finding was checked in the code and then
exercised end to end — through the API against a copy of the MariaDB database,
and in a browser — rather than judged from whether a screen existed.

Scope decisions taken with the team before any change:

- **"Client company management" means HR-managed client records**, not a client
  login. That keeps the documented scope and matches the CDE recording, in which
  the agency, not the client, enters requests and deployment details.
- **The DTR is encoded by HR** from the client's time records. The client's
  biometric export format is unknown, so no import was built.
- **Payroll stays out.** Nothing added computes pay.

---

## Summary

| # | Recommendation (as written on the sheet) | Status found | Now |
| --- | --- | --- | --- |
| 1 | Develop an HR system to manage the employment of the applicant | Partially implemented | Implemented |
| 2 | HR process should be from application to resignation/termination | Partially implemented | Implemented |
| 3 | Payroll is not included | Compliant (absent) | Unchanged — still absent |
| 4 | Add client company management | Partially implemented | Implemented |
| 5 | Carefully craft the matching of applicants to the job posting | Partially implemented; parts incorrect | Implemented |
| 6 | Application process | Mostly implemented; two gaps | Implemented |
| 7 | Fix the flow of "Documents" — view based on the name, all of that applicant's documents | Implemented the other way round | Implemented |
| 8 | DTR to compare with schedule | Not implemented (previously excluded from scope) | Implemented |
| 9 | In termination, add a confirmation box | Not implemented | Implemented |

---

## 1–2. The employment lifecycle, application to resignation or termination

**Found.** Recruitment through deployment was solid: an applicant becomes an
employee through `DeploymentService`, keeping one record from application to
exit. The end of the lifecycle was not:

- The separation API existed, but **there was no screen to grant clearance,
  complete a resignation, or finalise a termination.** The last stage existed
  only in the API.
- Completing a separation changed the **employee** record but never the
  **applicant** record, which stayed `active` forever. The applicant list showed
  former workers as employed, and the portal told a dismissed employee "You are
  currently deployed."
- The applicant screen offered **"Resigned" and "Terminated" as one-click
  buttons** that changed the status word directly — no termination record, no
  review, no confirmation. The same endpoint would accept `deployed` with no
  employee record behind it.
- A termination's review could only end in dismissal; a case filed in error
  stayed "for review" for ever. Several open cases could be filed at once, and a
  completed resignation could still be edited.
- A placement was closed on the day the paperwork was processed, not on the
  exit or termination date.

**Changed.**

- `SeparationService` now moves the applicant record with the employee
  (through the lifecycle service, so it is historised, audited, and notified),
  dates the placement's end by the exit or termination date, allows one open
  case of each kind, and adds **withdrawal** of a termination with a required
  reason. A data migration repairs records separated before the fix.
- `deployed`, `active`, `resigned`, and `terminated` are **workflow-owned**
  (`config/empower.php → workflow_owned_statuses`): the status endpoint refuses
  them with 409 and the screen no longer offers them.
- The employee record has a separation panel: clearance, exit date, accept,
  withdraw, complete; finalise or withdraw a termination; open the resignation
  letter or termination documents. A "Needs action" view on Separations lists
  open cases.
- History is preserved throughout: nothing is deleted, every move is in the
  status history and audit trail, and an archive snapshot is written.

## 3. Payroll is not included

**Found.** No salary, payslip, deduction, or contribution tables or logic.
**Changed.** Nothing. The new attendance comparison reports minutes against a
schedule and is explicitly not priced.

## 4. Client company management

**Found.** Companies could be added and listed; departments added; positions
and standing criteria managed; per-department employee lists existed. Missing:
no screen to **edit a company or deactivate it**, no way to **edit or
deactivate a department**, and the workspace showed only the **latest ten**
requests and placements, with "applicants" on a position not openable.

**Changed.** The company workspace now has *Edit company* (details and status,
with a confirmation before deactivation that says what it does), department
editing, full **Requests** and **Employees** tabs, an applicant-centric
**Documents** tab, and a position's applicant count opens those applicants. The
backend already supported these; all relationships are database-driven and work
for any future client.

## 5. Matching applicants to the job posting

**Found.** The engine itself is sound and defensible: rule-based, configured
per request, with mandatory gates kept out of the score and a line-by-line
explanation. But in practice it could not do its job:

- **Nothing could record an applicant's education, work experience, skills, or
  certifications.** Those tables were filled only by the demo seeder, so every
  real applicant scored zero on them whatever their resume said.
- A list of required **skills was treated as alternatives**: holding one of four
  earned full points.
- The requirements typed on the request (age range, height, education,
  experience, certificates) **never reached the ranking**, and the client's
  standing criteria were returned by the API but ignored by the screen.
- The "View Trace Details" dialog read fields the API does not send, so it
  showed **0% and no breakdown for every candidate**, with suitability labels
  based on hard-coded 80/60 cut-offs that contradicted the configured bands.
- Rankings went **stale silently**: people who left the pool kept their old rank
  (two people could be "#1"), and changed criteria were not flagged.
- An applicant could set **their own interview ratings** through a staff route.

**Changed.**

- A **matching profile** editor (HR only) records education, experience,
  skills, certificates, and the scored screening details; skill and certificate
  names are suggested from those already used by applicants and in clients'
  criteria, so both sides use the same words.
- Skills are **graded by coverage** — 3 of 4 required earns 75% of the points —
  and a mandatory skills list requires all of them. Certificates remain
  "any one of these". Each list criterion shows ✓/✗ per item. The rule is the
  catalogue's own type, editable in Settings.
- The criteria form **starts from the request's stated requirements and the
  client's standing criteria**; the request page marks each stated requirement
  "used in ranking" or not, and names free-text ones that cannot be scored.
- The trace dialog reads the real result. Rankings are flagged stale when the
  criteria or a ranked applicant's scored profile change, departed applicants
  leave the ranking (a shortlisted one is kept as history, unranked), each row
  shows what the applicant applied for and where they stand now, and Deploy is
  offered only to someone actually deployable.

CDE assesses its whole pool against a requisition, so the position an applicant
applied for is **shown, not scored**.

## 6. Application process

**Found.** Registration, the in-person identity check, the Folder 1/2/3
derivation, document gates, the training gate, and the applicant's own
placement response were all enforced on the server, and the portal explains
each status in plain words. Two gaps: the employment statuses could be set by
hand (above), and an applicant could edit fields the engine scores through the
staff edit route.

**Changed.** Both closed; the applicant's own edits go only through the portal
profile (contact details and availability).

## 7. Documents, by applicant

**Found.** The Documents page was organised **by document type** — the opposite
of the recommendation — so an officer read names off a list of medical
certificates.

**Changed.** Documents opens on a list of **applicant names**, each with what
they have sent, what is verified, and what awaits review (those first). A name
opens that applicant's complete checklist — uploaded or verified at the counter
— with the same review controls as the applicant record. It is retrieved
through the applicant relationship on the server, and an applicant cannot read
another's documents. The by-type view remains as a second tab.

## 8. DTR compared with schedule

**Found.** Not present; the August timeline had excluded daily time-in/out.

**Changed.** Per employee: a work schedule with effective dates (night shifts
supported) and an *Encode DTR* grid. The comparison reports, per day, on
schedule / late / left early / past scheduled end / absent / missing punch /
rest-day work, with monthly totals, and a workforce **Attendance (DTR)** page
lists who needs attention. A late or absent day can start a pre-filled violation
record; nothing is recorded automatically. New permissions `attendance.view`
and `attendance.manage` are granted to HR and administrators.

**Corrected 2026-10-29.** The first version counted a scheduled day with no
record as *absent* and offered a violation straight from it. Since CDE confirmed
the DTR stays in the client's biometrics, that would have shown nearly every day
as an absence. The comparison now says "awaiting DTR" until the DTR for a day is
known to be received; flagged days go to a review queue (`attendance.review`),
and only a verified one can become a violation. Schedules can be corrected or
voided, every DTR change keeps a revision, leave and holidays can be recorded,
and a provisional, column-mapped DTR import was added. Details:
`dtr-attendance-rework.md`.

## 9. Confirmation on termination

**Found.** There was no finalisation screen at all, filing had no confirmation,
and the applicant screen allowed a one-click "Terminated".

**Changed.** Filing a termination shows a review step. Finalising opens a
confirmation box naming the employee, company, reason, and date, listing what
will happen, and **disabled until the employee number is typed**. Completing a
resignation and deactivating a client are confirmed the same way. The API
independently refuses to finalise or complete without `confirm: true`.

---

## Access control fixes found on the way

Staff routes were reachable by portal accounts, and three policy fallbacks let
an owner through. An **employee could file a termination against themselves**,
**mark their own clearance "cleared"**, and an **applicant could edit their own
scored profile**. All three are now staff-only and covered by tests.

---

## How it was verified

- **Automated tests:** 260 passing (221 before; 39 added in
  `SeparationWorkflowTest`, `MatchingAccuracyTest`, `DocumentsByApplicantTest`,
  `AttendanceTest`).
- **End to end over HTTP, on a copy of the MariaDB database:** one applicant from
  self-registration to termination — 40 of 40 checks.
- **In a browser (Chrome):** documents, matching trace, client workspace,
  matching profile, DTR, termination review and confirmation — 19 of 19 checks,
  no console errors.
- **Not yet run on PostgreSQL/Supabase.** The new queries use the
  database-agnostic builder (date comparisons through `whereDate`, aggregates
  with `reorder()`), but they have not been executed against PostgreSQL.

## Open questions for CDE

1. Can the client export its DTR, and in what format? (Needed before an import.)
2. Is there a grace period for lateness? (Setting exists; defaults to none.)
3. How is overtime approved? (Shown only as time past the scheduled end.)

---

*Where this document and the code disagree, the code is what the panel will be
shown, and this document is the defect.*
