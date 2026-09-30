# Chapters 1–2 against the built system

An audit of `EMPOWER_Chapters_1_and_2_ALIGNED.pdf` against the code as it stands.
Every claim below was checked against the source, not from memory; the file and
line that settles each one is named so a panel member's question can be answered
by opening it.

The paper is, on the whole, unusually accurate — most capstone chapters overstate
what was built, and this one mostly understates it. **Five statements are wrong
and should be corrected before submission.** A further eight describe things the
system now does that the paper does not claim.

---

## 1. Five corrections needed

### 1.1 The Administrator cannot adjust permissions — only assign roles

**Where:** p. 5 ("assign roles and adjust the permissions attached to them"),
repeated in Scope, p. 11–12 ("assign roles and adjust their permissions").

**What is built:** `UserController` assigns a role to a user with `syncRoles()`
(`app/Http/Controllers/UserController.php:98`, `:135`), and `GET /users/roles`
lists the three roles read-only. There is **no endpoint and no screen that
changes which permissions belong to a role.** The three roles are seeded with
`is_system_role = true` and their permission sets are fixed in
`RolesAndPermissionsSeeder`.

**Suggested wording:** "…create and manage user accounts, assign each account one
of the system roles, change the system configuration, read the record of changes
made in the system, and finalize a termination."

> This is the one correction that matters most, because a panel member who reads
> "adjust the permissions" will reasonably ask to see that screen.

### 1.2 Deployment is approved by a human resource officer, not only the Administrator

**Where:** p. 8 (§2.4, "subject to shortlisting by the human resource officer and
approval by the administrator") and p. 14 (limitation: "all final matching and
placement decisions still have to be approved by the administrator").

**What is built:** `deployment.create` is granted to the **HR** role
(`RolesAndPermissionsSeeder.php:121`). An HR officer can record a deployment
without an administrator. What the system actually guarantees is that deployment
is a *separate, deliberate act by an authorised person* — never something the
matching engine does.

**Suggested wording (§2.4):** "…subject to shortlisting and a separate,
deliberate deployment decision recorded by an authorised officer."

**Suggested wording (limitation):** "It is not an autonomous decision maker. Every
placement is recorded by an authorised member of staff as a separate and
deliberate act, and the matching engine cannot deploy anyone."

*Alternative:* if the panel expects administrator approval as described, that is a
one-line change to the permission seeder — but the paper and the system must agree,
and today they do not.

### 1.3 The system runs on two database platforms, not one

**Where:** p. 13 ("the records are held in a PostgreSQL database").

**What is built:** the connection is chosen by one line in `.env`. MySQL/MariaDB
is used locally for development and demonstration; Supabase PostgreSQL is the
platform for the defense. Schema and queries are written to run on both — see the
three-way date branch in `DashboardController::monthlyTrend()` and the
`havingRaw` in the position backfill migration, both of which exist precisely
because a MySQL-only spelling fails on PostgreSQL.

**Suggested wording:** "…the records are held in a relational database, with
MySQL used for local development and PostgreSQL (hosted on Supabase) in
deployment; the schema and queries are written to run on either."

> Worth correcting: you will almost certainly demonstrate on XAMPP MySQL, and a
> panel that has read "PostgreSQL" may notice.

### 1.4 The one-year violation rule *is* now automatic

**Where:** p. 15 ("nor does it clear violation records after one year on its own").

**What is built:** since the latest revalidation, `EmployeeViolation::scopeActive()`
excludes any offence older than the configured window automatically, and
`Employee::reachedViolationThreshold()` counts only those still inside it. The
record itself is never deleted — it stays on file, stays in the disciplinary
report, and is marked "no longer counting" on screen.

The sentence as written now undersells the system and, read strictly, is wrong.

**Suggested wording:** "The system does not terminate an employee who reaches the
fourth offense. It counts only the offences that fall within the agency's
one-year window, marks older ones as no longer counting while keeping them on
file, and raises the employee for review when the threshold is reached; filing,
reviewing, and finalizing the termination remain decisions made by people."

### 1.5 Status changes and document reviews notify the applicant, not the office

**Where:** p. 8 (§2.7) and p. 13 ("notifies the office of status changes, reviewed
documents, and documents due to expire").

**What is built:** `ApplicationStatusChanged` and `RequirementReviewed` are
addressed to the **applicant**. The **office** is notified of online
self-registrations, documents about to expire (`CheckExpiringDocuments.php:66`
selects admin and hr users), an applicant's answer to a placement, and an
employee reaching the review threshold.

**Suggested wording:** "…the system notifies an applicant when their status
changes or a document is reviewed, and notifies the office of online
registrations, documents due to expire within the next thirty days, and employees
who have reached the disciplinary review threshold."

---

## 2. Eight things the system does that the paper does not claim

None of these is an error. They are features you built and are not getting credit
for, and several answer questions the interview explicitly raised.

| # | Built | Where it belongs |
| --- | --- | --- |
| 1 | **Training is enforced before endorsement.** An applicant cannot move to client evaluation without a completed training record. Configurable, default on. | §2.4 or the Scope; the paper mentions training as a stage but never says it is required |
| 2 | **Company → department → employee drill-down.** The administrative officer asked for exactly this on p. 5. It is built: each department is clickable and lists the people placed in it, with active and former counts. | Scope; and it closes the loop on the p. 5 quote |
| 3 | **Position catalogue per client company.** Applicants choose from roles the agency actually places rather than typing free text; a new client's roles appear without a code change. | Scope, and §2.1 (the portal) |
| 4 | **The applicant's own answer to a placement.** From the first recording: after training the candidate decides whether they still want the job. Recorded, and a decline blocks deployment until cleared. | §2.1 and §2.5 |
| 5 | **Review threshold flagging.** Reaching the fourth offence notifies the office and flags the record. | §2.5 |
| 6 | **Structured non-progression reasons.** The paper names the two reasons on p. 4; the system now records them as chosen values so they can be counted, rather than as free text. | Scope |
| 7 | **Configurable policy and retention.** The violation window, threshold, training requirement, unhired-applicant retention, and legal-document retention are administrator-editable settings, not constants. | Scope; strengthens the "configurable" claim in §2.4 |
| 8 | **Notification preferences and a Settings section** for both staff and portal users. | §2.7 |

---

## 3. Verified accurate — no change needed

Checked and correct, with the file that proves each:

- **164 employees as of August 2026**, and **25–50 applicants** in an ordinary
  month. Neither figure is hard-coded anywhere in logic.
- **Folder 3 / 2 / 1 recomputed from documents actually verified, never set by
  hand** — `FolderCategoryService::recalculate()`, called on every requirement
  change. `folder_category` is excluded from `$fillable` on the Applicant model
  specifically so it cannot be mass-assigned.
- **A lapsed document no longer counts toward the classification** —
  `ApplicantRequirement::countsAsComplete()` checks expiry.
- **The portal resolves every record from the signed-in account** —
  `PortalController` accepts no record identifier in any route.
- **An online registration is held until an officer confirms identity** —
  `ApplicantLifecycleService::assertGatesSatisfied()` refuses every transition
  except archiving while `awaiting_identity_check` is true.
- **OCR returns a confidence value and the source line, and writes nothing** —
  `OcrService.php:160–171` returns `value`, `confidence`, `needs_review`, and
  `source`; `DocumentScanController` creates no record.
- **The reading service deskews, denoises, and rescales before recognition** —
  `backend/ocr_service/preprocessing.py`, deskew capped at ±15°.
- **The portal legibility check stores nothing** —
  `PortalController::checkReadable()`.
- **Hard filters first, then weighted scoring** — gates are excluded from both the
  numerator and the denominator in `CompetencyScoringService::score()`, which is
  a stronger claim than the paper makes and worth mentioning in the defense.
- **Weights are per-request and every point is explained** — `request_criteria`
  carries `created_by`, so each weight traces to a named officer.
- **Termination begins as a draft and finalization is a separate permission** —
  `status` defaults to `draft`; `separation.finalize_termination` is excluded from
  the HR role.
- **Records archived rather than deleted** throughout.
- **Dashboard covers all seven named figures** — application rates, folder
  distribution, deployment status, open requests, violations by type,
  terminations, resignations (`DashboardController::summary()`).
- **Exports to PDF, Excel, and CSV** — one `ReportBuilder`, three formats.
- **Audit trail is administrator-only** — `audit.view` is not in the HR set.
- **Thirty-day expiry warning** — `empower.expiry_warning_days`.
- **Private bucket, links expiring in minutes** — `DocumentStorageService`.
- **No payroll, no timekeeping, no leave tracking, no client login, no
  retirement** — all confirmed absent.

---

## 4. Three small things

1. **Figure 1's arrows render as `?`.** The IPO diagram shows a literal question
   mark between the boxes where a downward arrow belongs — a font-encoding
   artifact. Replace with `↓` in a font that has it, or use a drawn arrow.
2. **"payroll assistance" on p. 2** describes a service the *agency* provides, two
   pages before the scope excludes payroll from the *system*. Both are true, but a
   reader may catch it. Suggest: "…and payroll assistance, the last of which is
   outside the scope of this study."
3. **The factory's 17 departments** (p. 2) versus the six seeded in the demo. Not
   an error — the paper describes the real client — but if you demonstrate live,
   have an answer ready for why the screen shows six.

---

## 5. One thing to decide

§2.4 and the p. 14 limitation currently say the **administrator** approves
placements. The system currently lets an **HR officer** record one. Either is
defensible, but they must match. Changing the paper is free; changing the system
is a one-line permission move plus a test. The paper's own p. 5 role description
lists deployment under the HR officer's duties, which suggests the paper's
*intent* is HR — so the two sentences in §2.4 and the limitations are most likely
the drafting error rather than the design.

---

*Prepared by checking each claim against the source. Where this document and the
code disagree, the code is what a panel will be shown, and this document is the
defect.*
