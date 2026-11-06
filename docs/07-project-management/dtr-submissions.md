# DTR file handover: coordinator submits, HR imports (4 November 2026)

The coordinator receives the client's DTR file and hands it to HR in EMPOWER.
HR then checks and imports it with the **existing DTR importer**. Nothing in
this feature parses a file or writes attendance. That stays with
`DtrImportService`, described in `dtr-attendance-rework.md`.

## 1. What existed

- **HR's importer.**
  - Upload with client, period (at most 62 days, ending today or earlier) and a source note.
  - Content checks (the bytes must match the extension; CSV, XLSX and XLS only).
  - Column mapping, row-by-row preview, confirmation.
  - Duplicate-file refusal.
  - Private storage.
  - Import history and audit.
- **DTR periods.** Each upload is filed under its client's DTR period, and HR
  confirms a period complete separately.
- **Coordinators** could already send schedules, reports and requisitions, but
  had no way to hand over a DTR file.

## 2. How it works now

```
Coordinator                         HR (attendance.manage)
───────────                         ──────────────────────
Submit DTR file ─▶ Submitted ──"Check and import"──▶ Under HR review ──import confirmed──▶ Imported
                       │                 (an ordinary import draft)      └─draft discarded─▶ Submitted
                       ├── Return (reason) ──▶ Needs correction ──"Send corrected file"──▶ new submission
                       └── Reject (reason) ──▶ Rejected
```

- **Coordinator → DTR Submissions.**
  - The form offers only the assigned client; the server takes the client from
    the assignment and ignores any id sent.
  - It asks for the coverage dates, a source / handover note and the file, then
    shows a review step before sending. Uploads show progress.
  - The file is checked with the importer's own rules: the same allowed types,
    the 5 MB limit, the 62-day period, the content check, a readable file, and
    "not already imported".
  - The history table shows the status, HR's feedback and the resulting import.
    A returned file is corrected with **Send corrected file**.
- **HR → Attendance (DTR) → DTR submission inbox.**
  - Filters: status (default "waiting for HR"), client, coordinator, coverage
    dates, date sent, search.
  - Opening a submission shows the declared client and coverage, the source
    note, the history, and the original file for download.
  - **Check and import** hands the stored file to `DtrImportService::upload`
    as if HR had uploaded it. It becomes an ordinary import draft, linked by
    `attendance_imports.dtr_submission_id`, and the **existing import dialog**
    opens at its column step.
  - From there the screens are the importer's own: mapping, preview,
    confirmation and result. HR never downloads and re-uploads the file.
  - **Return** and **Reject** both need a reason. Any open import draft from
    the file is discarded first; nothing was written from it.
- **Imported is never set by hand.** `DtrImportService::confirm` calls
  `DtrSubmissionService::imported` inside its own transaction. A failed or
  refused import rolls back with the submission still "Under HR review".
  Discarding the draft puts the submission back to "Submitted".

## 3. Rules

| # | Rule |
| --- | --- |
| H1 | Submitting writes no attendance and creates no import. |
| H2 | Only users with `attendance.manage` (HR, administrators) can open, import, return or reject a submission. Coordinators hold only `coordination.access` and are refused. |
| H3 | A coordinator sees, downloads and corrects only the submissions of their assigned client. Any coordinator of that client may do so, in case the sender was reassigned. |
| H4 | The file as sent is never changed or replaced. HR imports from a copy that the importer stores as its own. A correction is a new submission linked by `resubmission_of_id`, and only a returned submission can be corrected, once. |
| H5 | The same file cannot be sent while a submission of it is waiting or imported, nor once any import of it has completed. HR's direct upload of a file already imported is refused as before. |
| H6 | Rows are not trusted because a coordinator sent them. Every check in the importer applies: employee at this client, dates in the period, times, duplicates in the file, days already on file, and the DTR period's status. |
| H7 | HR's direct upload is unchanged. |

## 4. Data and storage

- Migration `2026_11_04_000400_create_dtr_submissions` (additive):
  - `dtr_submissions`: file metadata, a private storage key and the SHA-256
    hash, declared period, status, resubmission link, reviewer and the
    import that took the file in.
  - `dtr_submission_events`: every step, with its actor, note and import.
  - `attendance_imports.dtr_submission_id`.
- Files go on the private document disk under
  `dtr-submissions/{client}/{uuid}.{ext}`. They are downloaded only through
  `GET …/file`, behind authentication and scope; no public URL is issued.
- Codes: `DTRS-YYYY-NNNN`.

## 5. Notifications and audit

- `DtrSubmissionSubmitted` goes to every active user with `attendance.manage`,
  linking to `/attendance?tab=submissions&submission={id}`.
- `DtrSubmissionReviewed` goes to the submitter when a submission is imported
  (sent only after the import commits), returned or rejected. It links to
  `/coordinator/dtr-submissions/{id}`.
- Each is sent once per state change, never on page loads.
- Audit actions (`record_type = DtrSubmission`): `submit`, `start_import`,
  `return`, `reject`, `import`. The importer's own `upload`, `import` and
  `discard` entries are unchanged and name the submission.

## 6. Tests

- `tests/Feature/DtrSubmissionTest.php`, 9 tests, using the synthetic workers
  of `DtrSampleSeeder` and the files in `docs/09-testing/dtr-samples/`. It
  covers:
  - submission and storage
  - refused files (type, size, content, period)
  - the inbox and its filters
  - downloading the original file
  - import through the existing endpoints and the comparison afterwards
  - a failed import, and discarding its draft
  - return and correction
  - rejection
  - scope and permissions
  - duplicates
  - direct upload still working
- Frontend: `DtrSubmissionForm.test.jsx` and `DtrSubmissionInbox.test.jsx`.

## 7. Open questions for CDE

1. Does the client always send its DTR through the coordinator, or sometimes
   straight to the office? Direct HR upload remains available for the latter.
2. Should the source note be required, for example who at the client released
   the file?
3. Is there a cutoff by which the coordinator must send each period's DTR?
4. Should a coordinator be able to withdraw a file before HR opens it? This is
   not built; for now HR returns it.
