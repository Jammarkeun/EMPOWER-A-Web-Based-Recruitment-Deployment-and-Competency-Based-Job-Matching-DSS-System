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
| `training_scheduled` | HR / admin (`training.create`) | Applicant enrolled in a training session | Training record, schedule, enrolment | "You have been scheduled for training" | The training roster |
| `training_completed` | HR / admin (`training.update`) | Attendance and completion recorded | Enrolment outcome | "Awaiting endorsement to a client company" | Ready to endorse |
| `client_evaluation` | HR / admin (`matching.shortlist`) | Applicant endorsed to the client | Shortlist entry against the request | "Your profile has been endorsed" — **and the accept/decline question appears** | Who is with which client |
| *placement response* | **The applicant, and only them** | Applicant answers on their own portal | `placement_response`, `placement_responded_at`, `placement_response_note`; audit entry; HR notified | Their own answer, shown back | "Accepted" or "Declined" on the applicant record |
| `approved` | HR / admin | Client company confirms acceptance | History row | "The client company has approved your application" | Cleared to deploy |
| `deployed` → `active` | HR / admin (`deployment.create`) | Deployment recorded from the client's completed deployment details | Employee record, employee number, biometric number, department, supervisor, date; request headcount incremented | Their employment details | The deployment register |
| `resigned` | HR / admin (`separation.create`), filed by the employee | Resignation filed, 30 days rendered, clearance completed | Resignation, rendering days, exit date, clearance status | Their own filing and its clearance state | The separations list |
| `terminated` | HR / admin (`separation.finalize_termination`) | Violations reach the company threshold | Termination record, violations, evidence | The closure of their record | The disciplinary history |
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
| Resignation | ✔ files | processes and clears | — |
| Termination | — | ✔ | reports the violations |

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
   client's coordinator. The system models it as an agency-scheduled session; how
   attendance is confirmed when the client hosts it is not settled.
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
   fixed rules.
7. **The requisition's own criteria.** The example criteria — height, lifting
   capacity, hours standing — map onto the criteria catalogue, but "kayang
   bumuhat ng 25 kilos to 50 kilos" is currently free-text under physical
   requirements rather than a scored criterion.

---

*Prepared for the EMPOWER capstone, from the CDE Manpower Services interview
recording. Where this document and the recording disagree, the recording is
correct and this document is the defect.*
