# Digital Bio-Data form

Implemented 2 November 2026 on the adviser's recommendation: applicants fill
in their Bio-Data in the portal instead of uploading a separately prepared
file. HR reviews and verifies it like any other requirement, and a PDF can be
printed from what was saved.

## What was replaced and what was kept

| Before | Now |
| --- | --- |
| Bio-Data uploaded as a file through **My documents** | Filled in through **My Bio-Data**. The portal refuses new Bio-Data uploads (`422`, code `use_bio_data_form`) and links to the form. |
| HR verifies the upload, or a paper copy at the counter | Unchanged for uploads and paper copies. A form is verified from the **Review form** viewer on the Bio-Data row. |
| Uploaded Bio-Data files on the checklist | **Retired (user's decision, 2 November 2026)** by migration `2026_11_02_000200_retire_uploaded_bio_data_files`: every uploaded Bio-Data file is detached from the checklist, so it can no longer be viewed or downloaded anywhere. Each file's key, name and verification are kept in `applicant_requirement_history` (reason `upload_retired`) and the files stay in storage; `migrate:rollback` puts them back. A verified Bio-Data **stays verified**, so no folder changes. An upload still waiting, or rejected, went back to "not sent". |
| A verified Bio-Data closed the form | **Every account is asked for the form.** It stays open unless HR verified the form itself. Sending it over an earlier verification (paper copy or retired upload) keeps the requirement complete while HR reviews; returning it leaves that verification standing; verifying it makes the form the basis and records the earlier verification in history (reason `replaced_by_digital_form`). |

Bio-Data is still **one** requirement: the `bio_data` row of the checklist.
The form is another way of submitting it, so folders, the review inbox, the
dashboard and the Documents module read the same row as before.

## Fields and where each comes from

No official CDE Bio-Data template has been provided, so the form asks only
for what EMPOWER already records (`App\Support\BioDataForm`).

| Section | Field | Source of truth | On the form |
| --- | --- | --- | --- |
| Personal | First, middle, last name, suffix | `applicants` | Shown, locked (checked at the office) |
| Personal | Date of birth, age | `applicants.birth_date` | Shown, locked |
| Personal | Sex * | `applicants.sex` | Radio buttons, male/female |
| Personal | Civil status * | `applicants.civil_status` | Single / married / widowed / separated / divorced |
| Personal | Citizenship * | `applicants.nationality` | Text |
| Personal | Height (cm), weight (kg) | `applicants.height_cm`, `weight_kg` | Optional, 100–250 cm, 30–300 kg |
| Address | Present address | `applicants.present_address` | Shown, locked |
| Address | Provincial address | `applicants.provincial_address` | Optional |
| Contact | Contact number, email | `applicants` | Shown; edited under Profile |
| Education * | Level, school, course, year, honors | Declared on the form | Repeating; at least one; level uses the scoring vocabulary |
| Employment | Employer, position, from, to, duties | Declared on the form | Repeating; optional; dates checked (not future, not before birth, end ≥ start) |

\* required to submit. A draft may leave anything blank but nothing malformed.

Declared personal values are written onto `applicants` **only when HR
verifies**, and only where they differ (HR sees the list first; each change
is audited). A change to `sex` or `height_cm` marks earlier rankings out of
date, as an HR edit does. Declared education and employment are **not**
written into the qualifications matching scores: those stay HR-recorded
(`ApplicantQualificationController`), so submitting a Bio-Data never changes
anybody's ranking.

## Tables

- `applicant_bio_data` — one per applicant: the draft (JSON) and when it was saved. Private to the applicant.
- `applicant_bio_data_versions` — one row per submission: the form and the profile as they stood, who sent it, HR's outcome (`verified` / `needs_correction`), reviewer, time, note, and the profile fields verification changed. Never edited after HR's decision.
- `applicant_requirements.submitted_via` — `portal_upload` / `digital_form` / null for older rows. `ApplicantRequirement::scopeSentIn` treats a digital form as "sent in" wherever a file was.

Migration: `2026_11_02_000100_create_applicant_bio_data_tables.php` (additive; RLS enabled on PostgreSQL).

## Workflow

| State | Meaning | Checklist row |
| --- | --- | --- |
| Not started | No draft, nothing sent | unchanged |
| Draft | Saved, not sent; the office cannot see it | unchanged |
| Submitted | Sent, nobody has opened it | `submitted`, `submitted_via = digital_form` |
| Under review | An officer opened it (`first_viewed_at`) | `submitted` |
| Verified | HR accepted it | `verified`, method `online_form` |
| Requires correction | HR sent it back with a note | `needs_correction`, note in `rejection_reason` |

The applicant cannot edit while it is submitted/under review or once
verified. A returned form reopens with the last submission's values; sending
it again creates the next version. Verifying from the checklist itself
(bulk verify, status change) closes the pending version the same way.

## PDF

Rendered on request by DomPDF from `resources/views/bio-data/document.blade.php`
using stored data only, never stored or publicly linked. It shows the status
(draft / submitted / verified / returned), applicant code, version, and
generation date, and says that verifying it does not verify any other
document. Applicants download their own (`/portal/bio-data/pdf`); staff with
document access download any version (`/applicants/{id}/bio-data/pdf`,
audited as an export).

## Awaiting CDE confirmation

1. **The official Bio-Data form.** If CDE has one, its fields, wording and layout replace these.
2. **A printed, signed copy.** Settings → Documents → "Ask for a printed, signed Bio-Data" (off by default). When on, applicants are told to print, sign and bring the PDF, and the PDF gains a signature line. No electronic signature is collected either way.
3. Whether CDE's Bio-Data also asks for place of birth, religion, parents or spouse, emergency contact, government ID numbers, or character references. None were added without a stated need.
4. Whether height and weight should be required.
5. Whether declared education and work history should be copied into the matching profile by HR as a routine step.

## Not done

- **Supabase is not migrated.** Both migrations run there only when deploying. Note that the files in the private bucket are separate from the local `public` disk; retiring detaches them either way and deletes none.
- Local XAMPP `empower` was migrated on 2 November 2026 (backups taken before each step). Result: 49 Bio-Data verified, 16 not sent, no files on the checklist, 48 retired files in history, all 62 portal accounts shown the form as "Not started". No folder changed.
- The demo seeder no longer uploads Bio-Data: a verified one is seeded as a paper copy checked at the counter.
