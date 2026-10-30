# The CDE deployment workflow, and where it lives in the system

The source for everything below is the recorded interview with CDE Manpower
Services (`CDE Manpower Transcribe.txt`), in which the agency described its own
process from first contact to deployment, and separately its two routes out of
employment. Nothing here is invented: where the recording is silent, this
document says so rather than filling the gap.

The purpose is to answer, for every stage, the five questions that decide
whether a workflow is actually implemented or merely drawn:

- **Who can move it?**
- **What action triggers the move?**
- **What is recorded?**
- **What does the applicant see?**
- **What does the agency see?**

---

## 1. The process as the agency described it

> "Sa online po ang nangyayari, magse-send sila through Messenger… ang response
> po namin sa kanila ay ina-advise sila na pumunta ng physical dito sa opisina."

Applications arrive through Messenger, by email, or in person — but **every
route ends at the office**. Online contact does not begin an application; it
produces an instruction to come in. Applicants who walk in sign a physical
application log sheet.

> "Meron po kaming folder 1, folder 2, folder 3 ng applicants."

Applicants are filed by how complete their papers are. **Folder 3** holds those
who submitted only a resume. **Folder 2** holds those whose primary requirements
are complete but who are still pending. **Folder 1** holds those who were called,
told to complete the medical laboratories, and now have both primary and final
requirements — *"anytime pwede na namin silang isalang sa kliyente."*

> "Iya-assess namin 'yung nasa folder 1. Or 'yung nasa folder 2, if wala sa
> folder 1."

A client requisition arrives with its own criteria — the recording's example is
three men at 5'5" able to lift 25–50 kg and stand for six hours, and two women at
5'3" with relevant experience. The agency assesses Folder 1 against it, falling
back to Folder 2 if Folder 1 cannot fill the request.

> "Sila 'yung tinatawagan namin at 'pag tinawagan namin sila, kinakailangan
> nilang i-comply lahat ng documents. Once na na-comply nila 'yung documents,
> that was the time na ii-schedule namin sila for training."

Selected applicants are called, must complete every document, and are then
scheduled for training. They are brought to the client with an **endorsement
letter**, the deployment details, and a copy of all their requirements — the
agency keeps a copy and the client keeps a copy.

> "Sasalubungin sila ni company coordinator… mag-undergo sila ng ilang hours to
> training. And then after ng training, iya-assess sila ng company client,
> iya-assess namin sila, at mag-a-assist ng sarili si empleyado, si applicant
> kung gusto niya 'yung trabaho."

Three separate assessments follow training: the client assesses the candidate,
the agency assesses the candidate, **and the candidate assesses the job**. That
third one is easy to miss and is a genuine decision point — people do say no.

> "Ang deployment po at ang deployment details ay sinasagutan ni kliyente. Siya
> ang naglalagay anong department… kailan ilalagay, sino 'yung supervisor, ano
> 'yung biometrics number or employee number."

**The client fills in the deployment details, not the agency.** The admin
receives that completed form and enters it: *"si applicant 1 ay naging employee
192. Siya ay dineploy sa Chocolate Department ng Best Tiwi Food Products
Corporation ngayong August 3, 2026."*

> "Dalawang pintuan lang ang meron sa CDE para ikaw ay magtapos ng employment.
> It's either resignation or termination."

Employment ends only two ways. Resignation carries a 30-day rendering period.
Termination follows violations that have accumulated past the company's
threshold. The first recording's example was a fifth AWOL; the follow-up
interview corrected this to the **fourth offence**, which is what the system now
defaults to — and holds as configuration rather than code, because it is the
agency's handbook. Either way there is a
**clearance** signed by every office the employee dealt with, and unsettled
obligations are deducted from the last pay.

> "Lahat ng documentation will… ah would be ah back to zero after 1 year… Pero
> 'yung legal documents na 'yan, hindi lang 'yan 1 year. 5 years, 10 years."

Disciplinary records reset annually; legal documents are kept for five to ten
years.

---

## 2. The same process, as system states

The lifecycle is an **allow-list**, not a free-text field:
`config/empower.php → applicant_transitions` names the only statuses each status
may move to, and `ApplicantLifecycleService` is the single doorway through which
any change passes. Anything else is refused with HTTP 409. That is what stops a
record jumping from "Applied" to "Deployed" and skipping the document checks —
precisely the failure the paper folders allowed.

| Status | Who may set it | What triggers it | What is recorded | Applicant sees | Agency sees |
| --- | --- | --- | --- | --- | --- |
| `applied` | Applicant (self-registration) or HR at the counter | Registration submitted, or a record typed at the office | Applicant record, reference code, chosen position, document checklist | "Visit our office to complete your application", with the checklist and address | The applicant in the awaiting-identity-check list |
| — *identity check* — | HR / admin (`applicants.change_status`) | Officer confirms the person against their ID at the counter | `identity_verified_at`, `identity_verified_by` | The office card disappears; the application is live | The record is released into screening |
| `initial_screening` | HR / admin | Officer advances the record after receiving the resume | History row with actor, timestamp, reason | "Your application is being reviewed" | Folder 3 — resume only |
| `incomplete_requirements` | HR / admin | Screening finds documents missing | Same, plus the outstanding list | The list of what is still needed | Which documents are outstanding |
| `primary_requirements_complete` | HR / admin, or automatically | Every required primary document verified | Folder recalculated to Folder 2 | "Your primary documents are complete" | Folder 2 — pending medicals |
| `pending_final_requirements` | HR / admin, or automatically | Applicant is called in to complete the laboratories | Same | "Please complete your medical examination" | Awaiting medicals |
| `ready_for_deployment` | HR / admin, or automatically | Primary **and** final requirements verified | Folder recalculated to Folder 1 | "All your requirements are verified" | Folder 1 — deployable |
| `training_scheduled` | The training workflow only, on an HR / admin action (`training.update`) | Applicant enrolled in a session on its roster; undone if they are removed from it or the session is cancelled | Enrolment, by whom and when | "You are scheduled for training", naming the session, its date and location; a reminder and the Training step on their dashboard, and a My training page. Told again if the session moves, is cancelled, or they are taken off it | The training roster; the applicant record's Training card |
| `training_completed` | The training workflow only, on an HR / admin action (`training.update`) | Completion recorded for an applicant marked present, on or after the session date | Attendance and completion, each with the officer and time | "Awaiting endorsement to a client company" | Ready to endorse |
| `client_evaluation` | HR / admin (`matching.shortlist`) | Applicant endorsed to the client | Shortlist entry against the request | "Your profile has been endorsed" — **and the accept/decline question appears** | Who is with which client |
| *placement response* | **The applicant, and only them** | Applicant answers on their own portal | `placement_response`, `placement_responded_at`, `placement_response_note`; audit entry; HR notified | Their own answer, shown back | "Accepted" or "Declined" on the applicant record |
| `approved` | HR / admin | Client company confirms acceptance | History row | "The client company has approved your application" | Cleared to deploy |
| `deployed` → `active` | HR / admin (`deployment.create`) | Deployment recorded from the client's completed deployment details | Employee record, employee number, biometric number, department, supervisor, date; request headcount incremented | Their employment details | The deployment register |
| *resignation request* | **The employee**, on their portal (any active deployed employee, whatever their login was made as), or HR at the office (`separation.create`) | Employee asks to leave; the employee and filing date come from the server, and they must acknowledge that the request does not end their employment | Resignation `filed` with source, requested last day, their reason and remarks, optional letter; history entry; HR notified. **Employment status unchanged** | The request and its progress under My employment | Separations list, "Portal request" filter |
| *resignation review* | HR (`separation.create`) | HR asks for more information (`info_requested`, with a message the employee sees), accepts it with a confirmed last working day, or closes it with an internal reason | Status, confirmed exit date, clearance, message to the employee; history entry per change; employee notified once per save that changed something | HR's message, the confirmed last day, clearance, next steps; a reply box while information is requested; withdrawal only before acceptance (our default) | The case and its full history on the employee record |
| `resigned` | Completed by HR (`separation.approve`) after an explicit confirmation | Notice rendered, clearance marked "cleared", exit date set, then completed | Resignation, rendering days, exit date, clearance status; placement ended **on the exit date**; employee **and applicant** record moved to `resigned`; archive snapshot | "Your resignation is complete", with the last day and next steps | The separations list ("Needs action" until completed or withdrawn) |
| `terminated` | Filed by HR (`separation.create`) for review; finalised **only** by an administrator (`separation.finalize_termination`) after a confirmation that requires typing the employee number | Violations reach the company threshold and the agency decides to dismiss | Termination record (filed by, finalised by and when), optional supporting document, optional message to the employee; placement ended **on the termination date**; employee **and applicant** record moved to `terminated`; archive snapshot; clearance then tracked | Nothing until it is finalised. Then a notice (effective date, HR's message, clearance, next steps, contact) and a neutral notification; never the internal reason, remarks or documents. They may confirm receipt, which is not agreement | The notice's delivery, read, opened and acknowledged state; the separations list |
| *termination withdrawn* | HR or administrator | The review concludes the grounds do not hold | Termination kept on file as `withdrawn`, with who withdrew it, when, and the required reason | Nothing changes, and they are not told of a case they never saw | The separations history |
| `archived` | HR / admin (`archives.create`) | Record closed off | Archive entry with reason and actor | "This record has been archived" | The archive |

**Every one of these writes a row to `application_status_history`** (from, to,
reason, actor, timestamp) and an entry to the audit trail. The five questions an
auditor asks — who changed it, when, from what, to what, and why — are all
answerable for every transition, which is what the spreadsheet process could
never do.

### The gates

Two rules are enforced by the system rather than by memory:

1. **The folder is derived, never typed.** `FolderCategoryService::recalculate()`
   recomputes Folder 1/2/3 from the documents actually verified, every time a
   requirement changes. Nobody can move a folder by hand, so the filing cannot
   drift out of step with reality.
2. **Deployment requires Folder 1.** `DeploymentService::assertDeployable()`
   refuses unless the applicant is `approved` or `ready_for_deployment`, the
   request can still take a worker, the client company is active, the applicant
   is in Folder 1, they are not already actively deployed, **and they have not
   declined the placement**.
3. **Training, employment and separation statuses belong to their workflows.**
   `training_scheduled` and `training_completed` are reached only through a
   session's roster, `deployed` and `active` only by recording a deployment, and
   `resigned` and `terminated` only by completing a resignation or finalising a
   termination (`config/empower.php → workflow_owned_statuses`). The generic
   status endpoint refuses them with HTTP 409, and the applicant screen does not
   offer them, so nobody can be "terminated" — or "trained" — by a button that
   changes a word with no record behind it.
4. **Endorsement requires a recorded completion.** Moving an applicant to
   `client_evaluation` is refused unless a completion is recorded in a session
   that was not cancelled (`require_training_before_deployment`, on by default).
   The applicant screen says so before the click rather than after.
5. **Ending employment is confirmed twice.** The screen asks for a confirmation
   that names the person and what will happen (for a termination, by typing the
   employee number), and the API separately refuses to complete a resignation or
   finalise a termination unless the request carries `confirm: true`.

---

## 3. Who owns each stage

| Stage | Applicant | Agency (HR / admin) | Client company |
| --- | --- | --- | --- |
| Registering and choosing a position | ✔ | records walk-ins | — |
| Identity check at the office | presents ID | ✔ confirms | — |
| Submitting documents | ✔ uploads or brings originals | — | — |
| Verifying documents | — | ✔ only | — |
| Folder categorisation | — | derived automatically | — |
| Raising a manpower request | — | records it | ✔ originates it |
| Setting the criteria | — | ✔ | supplies the requirements |
| Ranking candidates | — | runs the evaluation | — |
| Shortlisting and endorsing | — | ✔ | — |
| Training | attends | schedules and records | hosts, via its coordinator |
| Assessing after training | ✔ decides about the job | ✔ assesses | ✔ assesses |
| Deployment details | — | enters what the client supplied | ✔ fills them in |
| Recording the deployment | — | ✔ | — |
| Resignation | ✔ requests (portal) or files at the office | reviews, accepts, clears and completes | — |
| Termination | — | ✔ | reports the violations |
| Reporting an incident at the client site | — | the CDE **coordinator** assigned to that client writes it up and submits it; **HR** validates or dismisses it | reports it to the coordinator |

The pattern worth stating: **the applicant's only decisions are what to apply
for, what to submit, and whether to accept the job.** Every review status and
every lifecycle move belongs to the agency, and the portal exposes no route to
any of them.

---

## 4. What happens when things go wrong

| Situation | What the system does |
| --- | --- |
| Document rejected | Status `rejected` with a written reason; the applicant is notified and sees the reason on their own screen |
| Document needs fixing | Status `needs_correction`; the applicant can send a replacement |
| Documents incomplete | `incomplete_requirements`, with the outstanding list shown to both sides |
| Document expired | A verified document past its expiry stops counting towards the folder, so the applicant silently leaves Folder 1 rather than being deployed on a lapsed clearance |
| Applicant declines the placement | Recorded against the applicant, HR notified, deployment refused until it is cleared. **No status changes** — what a withdrawal means for someone's place in the pool is the agency's judgment, not a form's |
| Applicant never visits the office | Stays in `applied`; `archived` is the only move permitted, so the registration can be closed off |
| Request already filled | `canAcceptDeployment()` refuses another deployment against it |
| Client company deactivated | Its positions leave the application form and it can raise no new requests |
| Applicant already deployed | A second active deployment is refused |
| Status reached with every document verified, then a document lapses or is rejected | The status stays; the applicant list and record show "Documents lapsed" beside it, and deployment stays refused until the folder is back to Folder 1. Whether the status should fall back is still CDE's call (audit Q4) |
| Training session cancelled | Refused if anybody's completion is recorded on it. Otherwise everybody scheduled only for it returns to "ready for deployment"; the roster is kept as a record and the session cannot be reopened (schedule a new one — our rule) |
| Completion recorded in error | Withdrawn from the roster with a confirmation; the applicant returns to "training scheduled", unless they were already endorsed on the strength of it, in which case the withdrawal is refused |
| Session details wrong | Corrected on the session page and audited field by field. Its date cannot move into the future once attendance is recorded, and it cannot be marked under way or completed before its date |
| A worker moves to another client | Not a reassignment: recorded as a new deployment against that client's request, so headcounts and the client's coordinator stay right (decided 2026-10-19) |
| Forgotten password | The office issues a temporary password that must be replaced at the next sign-in; an emailed reset link is offered only once a real mailer is configured |
| Termination filed in error, or the grounds do not hold | Withdrawn with a required reason; the employee stays active and the termination stays on file |
| A second resignation or termination filed while one is open | Refused until the open one is completed, finalised, or withdrawn; for resignations the database refuses it too |
| An employee tries a staff separation route on themselves | Refused (403). Their separation actions are requesting, withdrawing (before acceptance) or answering HR about a resignation, and confirming receipt of a notice, all through the portal |
| A former employee signs in | Their portal stays open and read-only for their own record and notice; a new resignation is refused (409). No account is deactivated automatically (an open question for CDE) |

---

## 5. What the recording does not settle

Recorded honestly, because these are our reasonable defaults rather than CDE's
confirmed policy, and each is a question for the next client meeting:

1. **The endorsement letter.** The agency is explicit that applicants are brought
   to the client with an endorsement letter, a copy of the deployment details,
   and a copy of every requirement. The system records the deployment but does
   **not** generate the endorsement letter. Its format, its signatories, and
   whether it needs a reference number are unknown, and inventing a document the
   agency hands to a client would be worse than omitting it.
2. **Where training sits.** The recording places training after the applicant is
   called and has completed their documents, but at the client's site with the
   client's coordinator. The system models it as a session the agency schedules
   and records; training the client hosts is recorded as a session whose location
   is the client's site. How attendance is confirmed when the client hosts it is
   still not settled — the officer who marks attendance is named on the roster,
   but nothing records *how* they knew. Training completed outside EMPOWER, with
   no session on any roster, is **not** accepted (decided 2026-10-19, audit Q7):
   the endorsement gate rests only on a recorded completion.
3. **The three post-training assessments.** The client's assessment, the agency's,
   and the applicant's are described as separate. Only the applicant's is
   recorded distinctly; the other two are collapsed into the move to `approved`.
   Whether the client's assessment needs its own record — a score, a form, a
   named assessor — is unanswered.
4. **The application log sheet.** Walk-in applicants sign a physical transmittal
   sheet. Whether the system should reproduce it, or whether the digital record
   replaces it, was not asked.
5. **The annual reset of disciplinary records.** Violations "go back to zero"
   after a year, and the follow-up interview put the threshold at the fourth
   offence. Both are now applied: an offence stops counting after the configured
   window, and reaching the threshold flags the employee for an administrator to
   review. Neither ends anybody's employment on its own.
6. **Retention.** Confirmed in the follow-up: a disciplinary record clears after
   one year, legal documents are kept five to ten years, and unhired applicants
   are held roughly three months "depending on the situation". All three are now
   configurable policies that prompt a review rather than delete anything, since
   the agency's own wording makes clear these are operating estimates and not
   fixed rules. The *Retention Review* screen lists what is due: an unhired
   applicant whose last recorded activity (application, status change, or a
   document added or reviewed) is older than the configured months, and a
   resigned, terminated or archived record whose legal documents have been kept
   longer than the configured years since it closed. Where each clock starts is
   **our reading**, stated on every row. A reviewer keeps the record on file
   until a date with a reason, or archives an unhired applicant through the
   ordinary status change. Nothing is deleted, and no disposal of documents is
   offered — that would need a procedure CDE has not given.
7. **The requisition's own criteria.** The example criteria — height, lifting
   capacity, hours standing — map onto the criteria catalogue, but "kayang
   bumuhat ng 25 kilos to 50 kilos" is currently free-text under physical
   requirements rather than a scored criterion.

8. **The DTR source and format.** Added after the July 2026 panel
   recommendation ("DTR to compare with schedule"). Workers clock in on the
   client's own biometric system, which is not connected to EMPOWER (confirmed
   by CDE, 26 October 2026). HR keeps the schedule; the DTR is typed in or
   imported from the client's file. No sample export has been received, so the
   import fixes no layout: HR maps each file's columns, one row per employee per
   day (see `docs/07-project-management/dtr-attendance-rework.md`).
9. **Attendance rules.** CDE has not stated a grace period, so lateness is
   counted to the minute (a configurable allowance, default 0). Time recorded
   after the scheduled end is shown as "past scheduled end" — not approved
   overtime — because whether and how overtime is approved is the client's
   policy, and pay is outside this system. A day with nothing on file is
   "awaiting DTR", never an absence, until the DTR for it is known to have been
   received. Nothing in the comparison records a violation: HR reviews each
   flagged day, and only a verified one can be recorded as a violation, through
   the ordinary violation form.

---

## 6. Employment after deployment: schedule and DTR

| What | Who | What is recorded | Where it shows |
| --- | --- | --- | --- |
| Work schedule | HR / admin (`attendance.manage`) | Work days, shift start and end (a shift ending at or before it starts runs past midnight), optional break (shown, never deducted), effective date, and the placement it was set under. A change closes the previous schedule the day before; a wrong one is corrected with a reason or voided, never deleted | Employee record → Attendance (DTR) |
| Daily time record | HR / admin (`attendance.manage`), typed in from the client's DTR or imported from its file | Time-in, time-out, remarks, source (manual or which import and row). Every change, including clearing a day, keeps a revision with the old times and the reason | Same; Attendance (DTR) → DTR imports |
| Leave and holidays | HR / admin (`attendance.manage`) | Approved leave for one employee, or a holiday or non-working day for a client or department; withdrawn with a reason | Attendance (DTR) → Leave & holidays |
| Comparison | Computed on demand, never stored | Per day: on schedule, late, early departure, missing time-in/out, no DTR record (only once the DTR for the day is known to be received), outside the assigned schedule, no schedule to compare with, does not match the shift; or awaiting DTR when there is not enough data | Employee record; Attendance (DTR) review queue and employee summary |
| Review | HR / admin (`attendance.review`) | For review → under verification → verified / corrected → closed, each step with who, when and a note; a verified day may be recorded as a violation through the violation form, and the review keeps the link | Attendance (DTR) → Review queue; the day's detail |
| Reassignment | HR / admin (`deployment.reassign`) | A move to another department, position or supervisor **at the same client** (decided 2026-10-19, audit Q8), with the effective date and a required reason. The previous placement stays as a history row; the audit entry records each field before and after. The date falls between the placement's start (or its last move) and today — a move is recorded once it has happened (our rule). Refused into an inactive department, for a placement that has ended, or when nothing would change | Employee record → Assignment, with every move under its placement |

The comparison computes no pay and applies no deduction: payroll remains out of
scope.

A worker moving to **another client** is not reassigned: the move is recorded as
a new deployment against that client's own request, so each request's headcount
and each client's coordinator see the right people.

---

## 7. The CDE coordinator

A coordinator is a **CDE employee assigned to one client company**: the
agency's person at that client, who receives the client's reports about
deployed workers and passes them to HR. It is an internal account, not a client
login, and the client still has no access to the system.

| What | How it works |
| --- | --- |
| Account | Created by an administrator (Users & Access) with a required client company. The role is fixed to `coordinator`, which holds a single permission, `coordination.access`. |
| Scope | Every coordinator screen reads from `/api/v1/coordinator/*`. The client is resolved on the server from the coordinator's **active assignment**; no endpoint accepts a client id, so editing a URL or query string cannot reach another client. Every HR and administration endpoint is gated by a permission the role does not hold, so those are refused too. |
| What they see | Their client's details, departments, and positions; its employees (position, department, supervisor, employee and biometrics numbers, status, disciplinary record, this month's attendance summary); its deployments; its manpower requests and how far each is filled. Not birth dates, addresses, government numbers, documents, the applicant pool, other clients, or anything to do with pay. |
| Violation reports | Draft → submitted → under HR review → validated (an official violation is recorded, linked to the report, the coordinator, the client, and the department) or dismissed; HR can also return a report for correction. The client and department are stamped from the assignment and the employee's placement. |
| What they cannot do | Record or change an official violation, file or finalise a termination, encode the DTR, change client records, or reach any administration screen. Termination stays with HR and, for finalising, the administrator. |
| Reassignment | An administrator moves a coordinator to another client; the current assignment ends and a new one begins, so the history is kept. Access to the old client ends on the next request; reports made there stay with that client. |
| Deactivation | Blocks sign-in and revokes access at once; their reports remain on file. |
| Notifications | HR hears when a report is submitted; the coordinator hears what HR decided, and when a worker at their client reaches the review threshold. Coordinators receive no agency-wide notices. |
| Audit | Every entry records the actor's role and, for a coordinator, the client they acted for - including sign-in and viewing an employee record. |

---

## 8. Notifications for applicants and employees

A notification is addressed to the record its event concerns — a status change,
a reviewed document or a deployment to the applicant record, a password change
to the login. A portal login reads everything addressed to the person's own
records: the login, their applicant record and, once deployed, their employee
record, whichever of `applicant_id` and `employee_id` links the login to them.
Whether a notification is sent at all is decided by that same login's
preferences (`User::notifiableRecords()` and its reverse,
`User::portalAccountFor()`), so an employee who mutes a category no longer
receives it through the applicant record. Nothing is sent to anybody else's
inbox, and the unread badge counts exactly the rows that inbox reads.

---

*Prepared for the EMPOWER capstone, from the CDE Manpower Services interview
recording. Where this document and the recording disagree, the recording is
correct and this document is the defect.*
