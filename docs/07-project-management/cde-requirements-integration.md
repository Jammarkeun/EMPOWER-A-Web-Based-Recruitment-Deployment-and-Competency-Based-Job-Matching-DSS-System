# CDE Requirements Integration (meeting of 26 October 2026)

Branch: `feature/cde-requirements-integration` · Written 28 October 2026

This records what the 26 October 2026 meeting with CDE Manpower Services asked
for, what was built, what is still provisional, and what is waiting on CDE. The
source is the meeting recording (`Original Recording.txt`, Tagalog and
English). Where the recording and an earlier validation disagree, the newer
statement is noted, but a figure is only changed in the system once the
official document behind it is received.

## 1. What CDE asked for, and what was built

| # | CDE said (meeting time) | Built | Status |
|---|---|---|---|
| 1 | Three coordinator functions: "violation, incident report, requisition" (12:30, 15:30) | Coordinator sidebar and dashboard now offer all three. | Confirmed |
| 2 | Violation report: pick the client first, then type to search the employee; ~164–170 workers at one client (10:00–12:30) | Type-to-search employee picker, searching on the server inside the coordinator's client. The old dropdown loaded 100 workers and stopped, so workers past the 100th could not be reported. | Confirmed |
| 3 | Incident report: "report lang siya" — e.g. at 12:30 the loading department was sent home because the truck would not run; just for the admin to read (8:30) | Incident reports: title, date, time, department, location, details, optional evidence. Names no employee; never becomes a violation. | Confirmed |
| 4 | Admin answers with a typed note: "case resolved" or "case pending" (13:00); the coordinator can add notes (14:30) | Append-only note thread. The office marks a case pending or resolved with its note; the coordinator adds follow-ups but cannot change the status. | Confirmed |
| 5 | Requisition: date requested, date needed, how many, how many women and men; CDE has a blank form and will send a photo (7:30–8:00) | Requisition module, now on CDE's own Manpower Requisition Form (section 2, received 29 October 2026). | Confirmed; open questions in section 2 |
| 6 | Supporting evidence is optional (14:00) | Evidence and attachments are optional everywhere. | Confirmed |
| 7 | NBI clearance only matters "upon employment"; once deployed its lapsing "hindi na nagmamatter" (2:30–3:30) | Per-document "after deployment" rule. A deployed worker's lapsed NBI clearance is kept, shown as "not required while employed", never flagged. | Confirmed for NBI |
| 8 | Medical is annual (with chest X-ray, done for all deployed workers in the first week of January); health card is renewed even while employed (0:30–1:00, 3:30–4:00) | Annual medical and health card marked "renewed while employed": tracked after deployment, renewal due/overdue shown, renewals keep the old version. | Confirmed |
| 9 | Validity "depende sa clinic" (1:30) | No validity period is assumed anywhere; the date on the document is used. | Confirmed |
| 10 | A client can gain a department; the short code is "just a label" (4:30–6:00) | Short code optional (derived from the name when blank). A department with workers still in it cannot be switched off. Department page lists moves in and out. | Confirmed |
| 11 | A worker's department can be changed any time (14:00) | Already supported by Reassign (same client), with history. No change needed. | Confirmed (existing) |
| 12 | Admin sees "lahat ng device na pumapasok sa system", to guard against hacking (21:30) | Sign-in Activity: devices signed in now, sign-in history including failed attempts, and "sign this device out". Administrator only. | Confirmed |

## 2. The requisition form — CDE's own, from 29 October 2026

CDE sent a photo of a **filled-in** copy of its Manpower Requisition Form (not
a blank). The form is now the coordinator's form; requisitions record
`form_version = cde-2026-10`.

| CDE label (as printed) | In EMPOWER | Rule |
|---|---|---|
| Header: CDE Manpower Services · Sitio Bigayan, Brgy. Calios, Santa Cruz, Laguna · MANPOWER REQUISITION FORM | Form heading; print copy | — |
| Date requested | `date_requested` | Required, not in the future |
| Date Needed | `required_by` | Required, not before Date requested |
| Total Manpower Needed (sample: "1 male for SC Sealer") | `workers_needed` + `male_count` / `female_count` + position | Number and position required; men/women optional, sum ≤ total |
| Required Qualification(s) | `qualifications` | Optional |
| Other Remarks | `other_remarks` (new) | Optional |
| Reason of Request: New Position / Replacement / Additional Manpower / Reliever Manpower / Others: ____ | `request_reason` (new), `request_reason_details` (new) | One required; "Others" needs its text; "Replacement" may name whom (the sample wrote "of marinduque D.") |
| Requested by (signature) | The coordinator who files it (`requested_by`) | Automatic |
| Acknowledged by (Ivan James S. Domingo, Administrative Officer) | Whoever approves it, from the status history | Automatic; no new step |

Not on CDE's form but kept: the client company (from the coordinator's
assignment) and an optional **department**, shown under "For EMPOWER",
because HR needs a department to record the manpower request.

Retired from the form (columns kept, values still shown on earlier
requisitions): the free-text reason, gender requirement dropdown, employment
type, work location, work schedule, compensation notes, additional
instructions, requested by (client contact), supporting attachment. Their
switches were removed from **Settings → System**, so they cannot be turned back
on beside CDE's fields.

Earlier requisitions keep `provisional-2026-10` and say "Filed on the temporary
default form". A draft or returned one is finished on CDE's form before it can
be submitted; what the temporary form recorded stays on it.

The workflow is unchanged: draft → submitted → under review → approved →
fulfilled, with returned and rejected. HR's approved requisitions now carry
the reason, qualifications and remarks into the manpower request's remarks.
Once submitted, a requisition on CDE's form can be printed in the paper form's
layout ("Print form"), with the names EMPOWER recorded on the signature lines,
for signing on paper. No electronic signature is involved.

### Reports use the same layout

Every report in the Reports module (all ten types) is laid out like this form
when exported as PDF or Excel, or printed from the preview: CDE's letterhead,
"Label: value" lines, a boxed summary, the table, and signature lines with the
name over the line. The agency name and address come from **Settings → System
→ Agency details**; the address default is now the one printed on CDE's form.
CSV stays plain data, since a letterhead would break opening it in other tools.

### Open questions for CDE about the form

- **Reason of Request** — can more than one box be ticked (for example 2
  replacements and 1 additional)? EMPOWER allows one.
- **Requested by** — is this always the coordinator, or sometimes the client's
  supervisor? EMPOWER records the coordinator.
- **Acknowledged by** — does it mean approved, or only received? EMPOWER shows
  whoever approved it.
- **Total Manpower Needed** — may one form ask for more than one position?
  EMPOWER takes one position per requisition.
- **Required Qualification(s)** — required or optional? EMPOWER treats it as
  optional.
- Should a scan of the signed paper form be attachable? CDE's form has no such
  field, so the upload was retired.

## 3. Decision register — waiting on CDE

| Item | Missing | What depends on it | Current behaviour |
|---|---|---|---|
| D1 | **Updated sanction board / disciplinary memo** (CDE: "isisend ko sa'yo through PM", 23:00) | Offence threshold (CDE said it is now the **seventh**, previously the fourth); which offences count (AWOL confirmed as a violation); penalty per offence; whether any offence is excluded. | Threshold left at **4** in Settings, comments updated to say a 7 is pending the memo. Reaching it only flags for review; nothing terminates automatically. The administrator changes it in Settings — no code change. Penalty stays a free-text field HR fills in. |
| D2 | ~~Blank CDE Manpower Requisition Form~~ **Received 29 October 2026** (a filled-in copy) | Every requisition field, label, required/optional status and any approval signatures. | Built on CDE's form (section 2). The open questions there remain. |
| D3 | Offence reset "after one year" (23:30) | Whether it is a rolling 12 months from each offence (current) or a calendar-year reset. | Rolling 12 months (`active_window_months` = 12, editable). |
| D4 | NBI clearance is not in the seeded document list (it was question 5.3) | Whether NBI is collected as a required document. | Add "NBI Clearance" in Settings and set "after deployment" to *Valid until deployment only*. The migration does this automatically for any existing type named NBI. |
| D5 | After-deployment rule for police clearance, barangay clearance, drug test, urinalysis, fecalysis, hepatitis B | Whether each must be renewed while employed. CDE only spoke of NBI, the annual medical and the health card. | Left **not confirmed**: counted as before for applicants; after deployment a lapse is shown as "rule not confirmed", never as non-compliance. Settable per type in Settings. |
| D6 | Whether urinalysis, fecalysis and hepatitis B are part of the annual medical | Whether they should also be "renewed while employed". | See D5. |
| D7 | DTR: CDE said the client's biometrics hold it and HR encoding "parang napakahirap" (9:30–10:30) | Whether HR-encoded DTR stays. | Reworked 2026-10-29 (`dtr-attendance-rework.md`): missing DTR is no longer counted as absence, a provisional column-mapped import was added, and flagged days go through HR review. Coordinators still report absences as violations, as CDE described. |
| D8 | Rejected applicants: "Kapag nare-reject, di na namin sinasama" (24:30) | Retention of rejected applicants' documents. | Unchanged (3-month review prompt, nothing deleted automatically). |
| D9 | Compensation on requisitions | Whether coordinators may relay pay details. | Not on CDE's form; retired (the column keeps any earlier value). |
| D10 | Who approves requisitions — HR, the administrator, or both | Review permission. | HR and administrator both hold `requisitions.review`. |

## 4. How it fits the earlier fixes

- **R-01/R-02** (private documents, content-checked uploads): incident evidence
  and requisition attachments use the same `DocumentStorageService` — private
  disk, random names, extension from the detected content, signed short-lived
  links. A renewal no longer deletes the version that was verified; it is kept
  in `applicant_requirement_history`.
- **R-03** (no internals in errors): new services raise application
  `RuntimeException`s only; database and library errors still reach the
  browser as the generic message.
- **R-04 / coordinator isolation**: all new coordinator routes sit behind the
  `coordinator` middleware and scope by the assignment; tests try another
  client's records, departments and positions. Portal accounts are refused by
  the existing `staff` and `coordinator` gates.
- **R-16**: an approved requisition becomes a manpower request only through the
  ordinary request endpoint, so the request's own status rules apply.
- **R-17** (folder recalculation): the folder rule and the bulk recalculation
  share one function, now aware of the deployment stage; changing a document's
  after-deployment rule triggers a recalculation like any other rule change.
- **R-26** (action permissions): new permissions `incident_reports.*`,
  `requisitions.*` (HR and administrator) and `sessions.*` (administrator
  only). The coordinator role still holds only `coordination.access`.
- **Audit**: every create, submit, review, note, link, upload and revocation is
  in the audit trail; requisition status changes also write a status-history
  table, the same pattern as manpower requests.
- **Dashboard fix found on the way**: "Expired documents" counted only
  documents the daily check had not yet marked, so it dropped to zero each
  night while the documents stayed lapsed. It now counts both, and only for
  applicants not yet deployed.

## 5. Deploying this branch

1. `php artisan migrate` on XAMPP MariaDB and on Supabase. Five new
   migrations, all additive:
   - `2026_10_28_000100_create_incident_reports_table`
   - `2026_10_28_000200_create_manpower_requisitions_table`
   - `2026_10_28_000300_add_incident_and_requisition_permissions`
   - `2026_10_28_000400_add_post_deployment_rule_and_document_history`
     (sets the rule on `medical_result` and `health_card`, and on any type
     named NBI; changes no document status or date)
   - `2026_10_28_000500_record_sign_in_devices`
   - `2026_10_29_000500_adopt_official_cde_requisition_form` (adds
     `request_reason`, `request_reason_details`, `other_remarks`; makes the old
     free-text `reason` nullable; changes no existing row)
   New tables get row-level security on PostgreSQL, as the earlier lockdown
   requires.
2. No `.env` changes. No new scheduled jobs; the daily expiry check keeps its
   schedule.
3. Permissions arrive through the migrations; re-running the seeders is not
   required. Re-running `RequirementTypesSeeder` only re-applies the two rules
   CDE gave and never clears one set in Settings.
4. Sessions signed in before the migration show "Unknown device" until the
   person next signs in.
5. Documents uploaded locally do not travel to Supabase with
   `empower:copy-database` (unchanged limitation).

## 6. Verified here vs. to verify at CDE

Verified locally (SQLite test suite and production build): coordinator
isolation, incident and requisition workflows, the six document-expiry
lifecycle scenarios, department rules, sign-in monitoring, and the full
existing suite. See the branch report for counts.

To verify on the real stacks before the defence (16 November):
- Run the migrations on MariaDB 10.4 and Supabase PostgreSQL; check no new
  timestamp column picked up `ON UPDATE` on MariaDB.
- The coordinator's screens on a phone (CDE: coordinators use "cellphone,
  laptop").
- Sign-in Activity behind CDE's network: the IP shown is what reaches Laravel;
  behind a proxy, trusted proxies must be configured for it to be the
  device's address.
- The daily expiry check against real data, and the dashboard counts after it.
