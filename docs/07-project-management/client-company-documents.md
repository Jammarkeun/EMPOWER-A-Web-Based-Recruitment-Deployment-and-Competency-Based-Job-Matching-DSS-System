# Client company documents

Implemented 4 November 2026 on the System Expert's suggestion: CDE can keep a
client company's own business and government documents — a BIR registration,
a mayor's permit, a licence — on the company's record.

**Titles are free text.** There is no list of document types, no document
every company must have, and nothing marks a company compliant or
non-compliant. CDE has not named any document as mandatory; if it does, that
rule would be added separately.

## Where it is

| Place | What changed |
| --- | --- |
| **Client companies** list | New **Documents** column: "3 documents" or "None uploaded" (muted, not a warning). Either opens the company's Documents tab. |
| **Add client company** | Now three steps: *Company details → Documents (optional) → Review*, then one **Add client**. |
| Company page → **Documents** tab | **Company documents** section on top (new); the existing **Workers' documents** (requirements of people placed there) below it, unchanged. |

## Adding a company with documents

The company is created first (`POST /clients`), then each document is uploaded
to it one at a time with its own progress bar. A document that fails is named,
kept as typed, and can be corrected and retried against the company already
saved — the company is never created twice. A validation error on the company
itself (e.g. a duplicate name) returns to the details step before anything is
saved. An entry that was added but never filled in is dropped, not refused.

Sending the same file under the same title again (a retry whose first attempt
actually reached the server, or a double-click) returns the copy already saved
instead of adding a duplicate.

## Each document

| Field | Required | Notes |
| --- | --- | --- |
| Title | Yes | Up to 150 characters, typed by the uploader. Never derived from the file name. |
| File | Yes | Up to 10 MB. PDF, images (JPG, PNG, WEBP, GIF, BMP, TIFF, HEIC), Word, Excel, PowerPoint, OpenDocument, RTF. |
| Issue date | No | Not in the future. |
| Expiry date | No | Not before the issue date. Left empty, the document is never shown as expired. |
| Remarks | No | Up to 1,000 characters. |

The type is judged from the file's content by the shared
`DocumentUploadPolicy` (the same checks as applicant documents). Company
documents additionally refuse content that is only plain text or CSV: the
shared policy would store such a file as a harmless `.txt` whatever it was
called, which would leave a "permit.pdf" in the list that is really a note.

## Storage and access

- Files go through `DocumentStorageService` to the private document disk
  (`DOCUMENT_DISK`; refuses a public disk) under
  `client-documents/{company id}/{uuid}.{detected extension}`. The original
  name is kept for display only. The storage key is never sent to the browser.
- **Open** fetches a signed link that expires in minutes; **Download** streams
  the file through an authorised request, named after the original file.
- Every route is nested under the company and every document is looked up
  through it, so another company's document id returns 404.

| Action | Permission | Roles today |
| --- | --- | --- |
| List, open, download | `clients.view` | HR, Administrator |
| Add, edit details, replace file | `clients.update` | HR, Administrator |
| Remove | `clients.delete` | Administrator |

Coordinators hold none of these (only `coordination.access`), including for
the company they are assigned to.

## Replace and remove

- **Replace file** stores the new file, points the record at it, then deletes
  the old file. If saving the record fails, the new file is removed and the
  document is unchanged. The old file's name, type, size and SHA-256 stay in the
  audit trail (`replace_file`).
- **Remove** is a soft delete: hidden from the list, with `deleted_by` and the
  time recorded. The file stays in storage, so a mistaken removal can be
  recovered from the database.
- Add, edit, replace and remove are all written to the audit trail under the
  `clients` module with the company's id.

## Database

Migration `2026_11_04_000100_create_client_company_documents_table` — one new
table, additive only. No existing company is given a row.

`client_company_documents`: `client_company_id` (FK), `title`, `description`,
`issued_on`, `expires_on`, `file_path`, `file_name`, `file_mime`,
`file_size_bytes`, `file_hash`, `uploaded_by`/`uploaded_at` (current file),
`created_by`, `updated_by`, `deleted_by`, timestamps, `deleted_at`.

## API (`/api/v1`)

| Method | Path |
| --- | --- |
| GET | `clients/{client}/documents?search=` |
| POST | `clients/{client}/documents` (multipart: `title`, `file`, optional `description`, `issued_on`, `expires_on`) |
| PATCH | `clients/{client}/documents/{document}` |
| POST | `clients/{client}/documents/{document}/file` (replace) |
| DELETE | `clients/{client}/documents/{document}` |
| GET | `clients/{client}/documents/{document}/link` |
| GET | `clients/{client}/documents/{document}/download` |

Uploads are rate-limited to 30 a minute per account (`company-document-upload`).
`GET /clients` now includes `documents_count`.
