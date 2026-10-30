# EMPOWER REST API Contract

> **Scope of this document.** It describes the endpoints in prose. The
> authoritative list of every route, with its middleware, is
> `php artisan route:list --path=api` in `backend/laravel`. The sections marked
> *(updated 2026-10-19)* were checked against the code on that date.

## API Standards
- Base URL: /api/v1
- Auth: Laravel Sanctum bearer token for protected endpoints.
- Format: application/json, UTF-8.
- Timezone: Asia/Manila. Timestamps are stored as Manila wall time (since the
  12 October 2026 migration) and returned as ISO 8601 with the +08:00 offset.
- Pagination: page, per_page, sort_by, sort_dir.
- An account still on an office-issued password (`must_change_password`) is
  answered **403** with `"code": "password_change_required"` by every
  authenticated endpoint except `GET /auth/me`, `GET /auth/session`,
  `POST /auth/logout` and `POST /auth/change-password`.
- Sign-in limits *(added 2026-10-22, security audit V-02)*: a token stops
  working **720 minutes after sign-in** (`SANCTUM_EXPIRATION`) or after
  **60 minutes without a request of the account's own** (`SANCTUM_IDLE_TIMEOUT`).
  Both values are provisional (client question 20.4); `0` turns a limit off. A
  token refused for either reason is deleted and answered **401** with
  `"code": "session_expired"` or `"code": "session_idle"`. Any other 401 carries
  no code. Requests the client makes on its own (polls) send
  `X-Empower-Background: 1`; they are answered normally but do not restart the
  idle limit.
- Standard Response Envelope:

```json
{
  "success": true,
  "message": "Request completed",
  "data": {},
  "meta": {}
}
```

- Standard Error Envelope:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

## HTTP Status Usage
- 200 OK: Read or update successful.
- 201 Created: Record created.
- 202 Accepted: Async export job queued.
- 204 No Content: Delete successful.
- 400 Bad Request: Business rule violated.
- 401 Unauthorized: Unauthenticated.
- 403 Forbidden: No permission.
- 404 Not Found: Resource missing.
- 409 Conflict: Duplicate or invalid lifecycle transition.
- 422 Unprocessable Entity: Validation errors.
- 500 Internal Server Error: Unexpected server failure.

## Authentication Endpoints

### POST /auth/login
- Public: Yes.
- Request:

```json
{
  "email": "hr@cde.local",
  "password": "Secret123!"
}
```

- Response 200:

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "plain_text_token",
    "token_type": "Bearer",
    "user": {
      "id": 2,
      "full_name": "HR User",
      "email": "hr@cde.local",
      "roles": ["hr"]
    }
  }
}
```

The `user` object also carries `permissions` and `must_change_password`. When
that is `true` the account must call `POST /auth/change-password` before
anything else.

### POST /auth/logout
- Public: No.
- Response 200: Token revoked.

### GET /auth/me
- Public: No.
- Response 200: Current user profile, roles, permissions, `must_change_password`,
  and `session` (as below). The login response's `user` carries `session` too.

### GET /auth/session *(added 2026-10-22)*
- Public: No. Allowed while `must_change_password` is true.
- Counts as activity, so it is what "Stay signed in" calls; it cannot extend the
  720-minute lifetime.
- Response 200: `{ "data": { "idle_timeout_minutes": 60, "expires_at": "2026-10-22T20:00:00+08:00" } }`
  (either is `null` when that limit is off).

### POST /auth/change-password *(updated 2026-10-19)*
- Public: No. Allowed while `must_change_password` is true.
- Request: `current_password`, `password`, `password_confirmation`.
- Rules: the password policy (12+ characters, not a common password) and a new
  password different from the current one. Clears `must_change_password` and
  ends every other session.

### Password recovery *(updated 2026-10-19)*

| Endpoint | Public | Behaviour |
| --- | --- | --- |
| `GET /auth/password-recovery` | Yes | `{ email_reset_available, link_expires_minutes, office: { name, address, contact } }`. Says nothing about any account. |
| `POST /auth/forgot-password` | Yes, 5/hour per address | `{ email }`. **503** `code: email_reset_unavailable` unless `PASSWORD_RESET_EMAIL=true` and a real mailer is configured. Otherwise always **200** with the same message, whether or not an account exists; a link is sent after the response, at most three times an hour per address, never to a deactivated account. |
| `POST /auth/reset-password` | Yes, 10/hour per address | `{ email, token, password, password_confirmation }`. **200** on success: password set, `must_change_password` cleared, every session ended, token deleted (single use). **422** for an invalid, used, expired (60 min) or mismatched link, or a password the policy refuses. |

## Applicant Self-Registration

The only endpoints in the system that write without an authenticated caller.
They exist so a job seeker can create an account and see which documents to
bring before travelling to the office, which removes the agency's most common
support call.

Registering does **not** start an application. The record created here is held
before screening until an HR officer confirms the person's identity in person,
so an open endpoint cannot inject a candidate into the recruitment pipeline or
onto a client's shortlist. See `POST /applicants/{id}/verify-identity`.

### GET /register/requirements
- Public: Yes.
- Purpose: the document checklist shown before sign-up, split into what to bring
  on the first visit (`primary`) and what is only needed once a client accepts
  the applicant (`final`). Also returns the office name and address.
- Response 200:

```json
{
  "success": true,
  "data": {
    "primary": [{ "requirement_name": "PSA Birth Certificate", "is_required": true }],
    "final": [{ "requirement_name": "Medical Examination Result", "is_required": true }],
    "office": { "name": "CDE Manpower Services", "address": "Sta. Cruz, Laguna", "contact": null }
  }
}
```

### GET /register/positions
- Public: Yes.
- Purpose: the positions an applicant may apply for. The application form is
  built from this and nothing else, so a role added for a new client company
  appears on it without a front-end change.
- Only active positions of active client companies are returned. A position with
  no company belongs to the agency's own pool. `is_hiring_now` says whether the
  position has an open manpower request behind it — both kinds are worth
  applying for, and saying which is which is more honest than one flat list.
- Response 200:

```json
{
  "success": true,
  "data": {
    "positions": [
      {
        "id": 3,
        "position_code": "POS-2026-00004",
        "position_title": "Packaging Staff",
        "description": "Packing and labelling finished product.",
        "company": "Best Tiwi Food Products Corporation",
        "is_hiring_now": true
      }
    ]
  }
}
```

### ~~POST /register/check-email~~ *(removed 2026-10-22, security audit V-03)*
- Answered whether any address - staff accounts included - had an account, 20
  times a minute, and the sign-up form never used it. A taken address is still
  reported by `POST /register` itself (5 requests per hour).

### POST /register
- Public: Yes. Throttled to **5 requests per hour** per address, since this is an
  unauthenticated write and a handful an hour is far more than a genuine
  applicant needs.
- Request:

```json
{
  "first_name": "Ana",
  "middle_name": null,
  "last_name": "Reyes",
  "sex": "female",
  "birth_date": "2002-03-14",
  "contact_number": "09171234567",
  "present_address": "Brgy. Pagsawitan, Sta. Cruz, Laguna",
  "preferred_position_id": 3,
  "email": "ana.reyes@example.com",
  "password": "secret1234",
  "password_confirmation": "secret1234"
}
```

- `preferred_position_id` is a reference from `GET /register/positions`, not a
  job title. Storing the reference is what lets the agency rename a position
  later without orphaning everyone who applied for it; the wording the applicant
  was shown is kept alongside it as a record of what they were told they applied
  for.
- It is **required whenever any position is on offer**, and optional when none
  is. An agency between contracts still accepts applicants, and a form that
  could not be submitted because no client was currently hiring would turn away
  the very people the pool exists to hold.
- A position withdrawn between the page loading and the form being submitted
  returns **422** with a message telling the applicant to choose another, rather
  than a validation error implying they did something wrong.

- Response 201: returns a Bearer token so the applicant is signed in
  immediately, along with the reference number to quote at the office.

```json
{
  "success": true,
  "message": "Account created",
  "data": {
    "token": "plain_text_token",
    "token_type": "Bearer",
    "applicant_code": "APP-2026-00004",
    "user": { "user_type": "applicant", "roles": ["portal"], "permissions": [] },
    "next_step": "Visit the CDE Manpower Services office with your documents…"
  }
}
```

- Response 409: an applicant already exists with the same name and date of
  birth. People reapply, and without this the agency ends up holding one person
  under two records with two different histories.
- Response 422: validation failed. `birth_date` must place the applicant at
  least 15 years old, the minimum working age under RA 9231.

Side effects: creates the applicant with `source_channel = "online"`,
`current_status = "applied"`, `created_by = null` and `self_registered_at` set;
creates the full document checklist in `missing` state; assigns the `portal`
role only; and notifies every active admin and HR user that someone will be
visiting.

## Access Control *(updated 2026-10-19)*

Roles and their permissions are seeded (`RolesAndPermissionsSeeder`) and read
only; there are no endpoints that create roles or change their permissions.

### GET /users *(updated 2026-10-30)*
- Paged (`page`, `per_page` up to 100). Filters `search`, `user_type`,
  `is_active`. `user_type=employee` lists portal logins that reach an
  employment record - including a registration login still typed `applicant`
  after its owner was deployed (`User::portalEmployee`); `user_type=applicant`
  lists the other applicant logins. Each row carries `portal_access`
  (`employee` | `applicant` | null); `user_type` itself is never rewritten.
### GET /users/roles
### POST /users
- Staff and coordinator accounts. The password the administrator sets is
  temporary: the account starts with `must_change_password = true`.
### GET /users/{id}
### PATCH /users/{id}
### PATCH /users/{id}/status
### POST /users/{id}/reset-password
- No request body. Generates a temporary password, ends every session, sets
  `must_change_password`, and returns `{ temporary_password, notice }` once.
  The administrator never chooses the password.
### POST /users/portal-access
- `{ applicant_id | employee_id, email }`. Returns the temporary password once;
  the account starts with `must_change_password = true`.
### GET /users/{id}/coordinator-assignments
### POST /users/{id}/coordinator-assignment

Validation highlights:
- email unique.
- a portal account cannot be given a staff role.

## Client Company and Departments

### GET /clients
Filters:
- status, search, business_type.

Each row carries `deployed_count`: active deployments at that client today, the
same figure as `summary.deployed_staff` on `GET /clients/{id}/overview`. The
overview's `open_requests` and `positions_to_fill` count open, in-progress and
partially fulfilled requests, as `GET /job-requests?only_open=1` lists them.

### POST /clients
Request:

```json
{
  "company_name": "Best TV Food Products Corporation",
  "business_type": "Food Manufacturing",
  "contact_person": "Jane Dela Cruz",
  "contact_number": "09171234567",
  "email": "contact@besttv.local",
  "office_address": "Sta. Cruz, Laguna",
  "status": "active"
}
```

### GET /clients/{id}
### PUT /clients/{id}
### GET /clients/{id}/departments
### POST /clients/{id}/departments
### PUT /clients/{id}/departments/{departmentId}

### GET /clients/{id}/departments/{departmentId}/employees
- Who is currently placed in one of a client's departments.
- **Nested under the client on purpose.** The company is part of the address
  rather than a filter the caller may drop, so a department belonging to another
  client does not resolve at all — the answer is **404**, not 403, because
  saying "forbidden" would confirm the department exists somewhere.
- Requires **both** `clients.view` and `employees.view`. Seeing a company is not
  the same as seeing the names and employment status of its workers, and a role
  that may read a client's requests has no automatic claim on its payroll.
- Employees are read from `employees.current_department_id`, which the
  deployment and reassignment services already maintain — so a worker moved
  between departments leaves one list and appears in the other with no separate
  bookkeeping and no join table.
- Ordered current staff first, then former, by surname within each. Former
  employees are still listed: their placement is a matter of record.
- Response 200:

```json
{
  "success": true,
  "message": "Chocolate Department",
  "data": {
    "department": {
      "id": 3,
      "department_name": "Chocolate Department",
      "department_code": "CHOCO",
      "employees_count": 12,
      "former_employees_count": 2,
      "job_requests_count": 4
    },
    "company": { "id": 1, "company_name": "Best Tiwi Food Products Corporation" },
    "employees": [
      {
        "id": 87,
        "employee_number": "EMP-2026-00123",
        "full_name": "Andrea Santos",
        "current_position_title": "Production Staff",
        "current_supervisor_name": "Mark Reyes",
        "employment_status": "active",
        "hire_date": "2026-08-03"
      }
    ]
  },
  "meta": { "current_page": 1, "per_page": 50, "total": 14 }
}
```

`GET /clients/{id}/departments` carries the same two counts against every
department, so the list can show a headcount without a request per row.
`employees_count` is who works there **now**; `former_employees_count` is what
is left in the history, so a department that has lost its whole team reads as
empty rather than as though its records had gone missing.

### GET /clients/{id}/positions
- The roles this client hires for, with `open_request_count` and
  `applicant_count` against each so a position's demand is visible without
  opening another screen.

### POST /clients/{id}/positions
- Requires `clients.update`.
- Request: `{ "position_title": "Machine Operator", "description": "...", "status": "active" }`
- Adding a position here puts it on the public application form immediately.

### PUT /clients/{id}/positions/{positionId}
- Requires `clients.update`. Accepts `position_title`, `description`, `status`.
- Setting `status` to `inactive` **withdraws** the position: it leaves the
  application form but is not deleted, because the job requests and deployments
  referencing it are history and must keep reading correctly.

### GET /positions
- Every position, for the staff screens that attach one to a request or an
  applicant. Filterable by `client_company_id` and `status`.

Business rules:
- Department name must be unique per client.
- Position title must be unique **per client**, not globally — two clients may
  both hire welders.
- A position with a null `client_company_id` belongs to the agency's own pool.
- Recording a manpower request for a title the client has not used before
  creates the position, matched case-insensitively so "Production Helper" and
  "production helper" stay one role.
- Inactive client cannot receive new job request, and its positions are not
  offered to applicants.

## Job Request Management

### GET /job-requests
Filters:
- client_company_id, client_department_id, request_status (a status of the
  request lifecycle; 422 otherwise), date_from, date_to (date requested,
  inclusive), only_open (`1`: open, in progress or partially fulfilled).
- Booleans are sent as `1`/`0`; Laravel's `boolean` rule refuses `true`.

### POST /job-requests
Request:

```json
{
  "client_company_id": 1,
  "client_department_id": 3,
  "position_title": "Production Helper",
  "required_education": "High School Graduate",
  "required_experience_months": 6,
  "required_certifications": ["NC II"],
  "gender_preference": "any",
  "age_min": 18,
  "age_max": 35,
  "height_min_cm": 152,
  "physical_requirement": "Fit to lift 15kg",
  "availability_requirement": "Can start within 7 days",
  "workers_needed": 15,
  "date_requested": "2026-08-04",
  "deployment_deadline": "2026-08-20",
  "request_source": "email"
}
```

### GET /job-requests/{id}
### PUT /job-requests/{id}
### PUT /job-requests/{id}/status
### POST /job-requests/{id}/criteria
### GET /job-requests/{id}/criteria

Criteria request payload:

```json
{
  "criteria": [
    {
      "criteria_code": "education",
      "mandatory_flag": true,
      "weight_score": 15,
      "expected_value": "college_graduate"
    },
    {
      "criteria_code": "experience",
      "mandatory_flag": false,
      "weight_score": 20,
      "min_value": 6
    },
    {
      "criteria_code": "communication",
      "mandatory_flag": false,
      "weight_score": 20,
      "rubric": {
        "excellent": 1,
        "good": 0.8,
        "fair": 0.5,
        "poor": 0.2
      }
    }
  ]
}
```

Business rules:
- At least one active criterion required before evaluation.
- Cannot set status to fulfilled when workers_fulfilled is less than workers_needed.

## Applicant Management

### GET /applicants *(updated 2026-10-30)*
Filters, all applied together on the server with the paging:
- `search` — word by word: every word must appear in the first, middle or last
  name, applicant code, contact number, email or position sought, so
  "Juan Cruz" finds Juan Dela Cruz.
- `current_status` — one of the statuses `GET /applicant-statuses` lists, or
  several separated by commas (any of them); 422 if any is unknown, reported
  under `current_status`. **`deployed` is read from the employment record, not
  the column**: an active employee with an active deployment. Deployment moves
  an applicant through `deployed` and on to `active` in one transaction, so no
  record rests at `deployed`, and a status can disagree with the employment
  record. Every other status is matched on the column.
- `folder_category`, `source_channel` (`walk_in`, `messenger`, `email`,
  `online`), `date_from`, `date_to`.
- `preferred_position_id`; `client_company_id` — applicants who applied for any
  of that client's positions.
- `awaiting_identity_check=1` returns only applicants who registered online and
  have not yet been seen at the office. This is the queue the counter works from.
- `documents_lapsed=1` — status reached with every document verified, folder no
  longer Folder 1.
- `exclude_deployed=1` — leaves out anyone placed today by the same rule as the
  `deployed` filter. The dashboard's "ready for deployment" figure uses it.

Each row carries `status_label`, `stage`, `documents_lapsed` and `placement`:
`{ deployed, company, mismatch }`, by the same rule as the `deployed` filter.
`mismatch` is `status_not_updated` (placed, status not moved on),
`no_active_deployment` (status says employed, no active deployment) or null;
shown, never corrected. Sorted by `sort_by`/`sort_dir` (default newest
application first), then by id, so equal dates page consistently.

### GET /applicant-statuses *(new 2026-10-19)*
- Needs `applicants.view`.
- `{ statuses: [{ value, label, stage, closed, past_document_gate, workflow }], stages: [{ key, label, statuses }], deployable: [...] }`
  in lifecycle order. `deployable` is where a deployment can be recorded from
  today (only `approved` when Settings require the client's approval).

### POST /applicants
### GET /applicants/{id}
### PUT /applicants/{id}
### POST /applicants/{id}/educations
### POST /applicants/{id}/experiences
### POST /applicants/{id}/skills
### POST /applicants/{id}/certifications

Applicant create minimum payload:

```json
{
  "source_channel": "walk_in",
  "first_name": "Maria",
  "last_name": "Santos",
  "present_address": "Sta. Cruz, Laguna",
  "application_date": "2026-08-04"
}
```

### PATCH /applicants/{id}/status
Request:

```json
{
  "to_status": "initial_screening",
  "reason": "Documents received"
}
```

Rules:
- Transition must match lifecycle map.
- Gate validation applies for deployment-related states.
- An applicant who registered online and has not had their identity confirmed
  can only move to `archived`. Any other transition returns 400. Archiving stays
  available so someone who registers and never appears can be cleared out.

### POST /applicants/{id}/verify-identity
- Permission: `applicants.change_status`.
- Purpose: the single action that releases a self-registered applicant into
  screening. Records that a named officer checked this person against their
  documents, which is what the agency's in-person requirement actually means.
- Request: `{ "remarks": "PhilSys National ID" }` (optional).
- Response 200: the applicant, now at `initial_screening` with
  `awaiting_identity_check: false`.
- Response 400: the applicant does not need a check. Records created at the
  office are verified as they are entered.

Side effects: sets `identity_verified_at` and `identity_verified_by`, writes an
audit entry naming the officer, then transitions to `initial_screening`.

### GET /applicants/{id}/timeline
- Returns status transitions, notes, training events, matching events.

## Requirement Management

### GET /requirement-types
### GET /applicants/{id}/requirements

There is deliberately **no staff upload route**. Applicants upload their own
documents through the portal, and for a walk-in the officer verifies the
original across the counter without a file ever being stored. Staff uploading on
an applicant's behalf blurred who actually submitted what, and the audit trail
could no longer answer it.

Each requirement reports three independent facts, which earlier versions
conflated:

| Field | Answers |
| --- | --- |
| `upload_status` | Is there a stored file? (`uploaded` / `not_uploaded`) |
| `status` | What did the officer decide? (`missing`, `submitted`, `pending`, `verified`, `rejected`, `needs_correction`, `expired`) |
| `review_state` | Where does this stand, in the words shown to the applicant? |

`review_state` is derived, never stored, and is one of `not_uploaded`,
`uploaded` ("Upload successful"), `under_review` ("Being checked"), `verified`,
`rejected`, `needs_correction`, `expired`. The distinction that matters is
between the middle two: both are `status: submitted` in the database, and they
are told apart by `first_viewed_at` — whether a member of staff has actually
opened the file. A stored document is **not** under review merely because the
upload succeeded.

### GET /applicants/{id}/requirements/{requirementTypeId}/download
- Mints a short-lived signed URL, and **records that review has begun**:
  `first_viewed_at` and `first_viewed_by` are set on the first opening only, so
  the answer to "has anyone looked at this?" does not move every time the file
  is reopened.
- Every opening is audited, not only the first — for a document covered by
  RA 10173, who looked at it is the question that matters most.
- Returns the updated requirement alongside the URL, so the screen that opened
  it can correct the row in place rather than reloading and losing the reader's
  position in a long checklist.
- `GET /documents/file/{requirementId}` in the document library does exactly the
  same thing, because it is the same act on the same row.

### PATCH /applicants/{id}/requirements/{requirementTypeId}
Request:

```json
{
  "status": "verified",
  "verification_method": "walk_in",
  "verification_note": "Original sighted at the counter",
  "remarks": "Clear and valid"
}
```

### POST /applicants/{id}/requirements/verify-batch
- Verifies several requirements at once, for the counter case where an applicant
  hands over a folder and every paper in it is in order. Verify-only: rejecting
  needs a reason written against a specific document.

### GET /applicants/{id}/folder-category
- Returns folder_3, folder_2, or folder_1 with explanation.

Rules:
- Folder category is derived and read-only.
- Rejected status requires rejection_reason.
- Verification does **not** require a stored file: a walk-in applicant's
  originals are checked across the counter, and `verification_method` records
  how the check was performed.
- An applicant replacing a document clears `first_viewed_at`. A replacement is a
  different document, and the review the previous file had is not review of
  this one.

## Competency Matching

### POST /job-requests/{id}/evaluate
Request:

```json
{
  "candidate_scope": "ready_for_deployment",
  "limit": 100
}
```

Response 200:

```json
{
  "success": true,
  "message": "Evaluation completed",
  "data": {
    "job_request_id": 18,
    "evaluated_candidates": 36,
    "ranked": [
      {
        "applicant_id": 501,
        "name": "Maria Santos",
        "percentage_score": 92.4,
        "recommendation_level": "highly_recommended",
        "hard_filter_pass": true,
        "breakdown": [
          {"criteria": "education", "weight": 15, "score": 15},
          {"criteria": "experience", "weight": 20, "score": 16}
        ]
      }
    ]
  }
}
```

The catalogue returned by `GET /job-requests/{id}/criteria` carries two extra
fields per criterion that the form needs:

| `accepts` | Meaning |
| --- | --- |
| `list` | Free text, as many as the client requires — skills and certifications. Holding **any one** satisfies the criterion. Stored comma-separated in `expected_value`. |
| `choice` | A fixed vocabulary the engine recognises, supplied in `options` (education levels, gender). |
| `none` | Measured numerically; `min_value`/`max_value` apply instead. |

This matters because an unrecognised expected value is **not** a validation
error — it simply never matches, so the criterion silently scores nobody and
explains nothing. Publishing the vocabulary is what stops an officer typing
"College Graduate" where the engine looks for `college_graduate`.

`GET /clients/{id}/criteria` returns the same two fields, so the company-level
form and the request-level form offer identical vocabulary.

### GET /job-requests/{id}/rankings
### POST /job-requests/{id}/shortlist
Request:

```json
{
  "applicant_ids": [501, 523, 547]
}
```

Rules:
- Evaluation does not create deployment.
- HR must explicitly shortlist and confirm.

## Training Management *(updated 2026-10-19)*

### GET /trainings
- Filters: `status` (`scheduled`, `ongoing`, `completed`, `cancelled`),
  `date_from`, `date_to`; paginated, with enrolled / attended / completed counts.
### POST /trainings
### GET /trainings/candidates
- Applicants at a stage where training applies, each with any completed or
  scheduled session. `awaiting_only=1` keeps only those without a completion.
### GET /trainings/{id}
- The session with its roster: attendance and completion, each with who
  recorded it and when.
### PATCH /trainings/{id}
- Details (`training_title`, `training_date`, `location`, `trainer_name`,
  `remarks`) and `status`. Only what is sent changes; corrections are audited
  field by field. Refused (409) when:
  - the status move is not allowed — a cancelled session cannot be reopened, a
    completed one can go back to `scheduled`/`ongoing` to add a late attendee;
  - `ongoing` or `completed` is asked for before the session date;
  - the date would move into the future once attendance or a completion is
    recorded;
  - cancelling a session with a completion recorded on it.
- Response: the session as `GET /trainings/{id}` returns it.
### POST /trainings/{id}/enrollments
- `{ applicant_ids: [...] }`. Each is enrolled or skipped with the reason
  (already on the roster, not at a stage where training applies). Enrolling
  moves the applicant to `training_scheduled`.

Each roster event sends the applicant one `training` notification naming the
session, its date and its location, linked to `/portal/training`: enrolled,
session date or location changed (to those with nothing recorded yet), session
cancelled, taken off the roster, completion recorded, completion withdrawn. The
status moves these cause send no separate status notification.
### PATCH /trainings/{id}/enrollments/{enrollmentId}

Enrollment update payload:

```json
{
  "attendance_status": "present",
  "completion_status": "completed",
  "remarks": "Passed practical demo"
}
```

Rules:
- Applicant must be in eligible status before enrollment.
- No attendance or completion before the session date, or for a cancelled
  session. A completion needs attendance `present` and moves the applicant to
  `training_completed`. Withdrawing a completion moves them back, unless they
  have already been endorsed on the strength of it.
### DELETE /trainings/{id}/enrollments/{enrollmentId}
- Only while nothing is recorded for that enrolment.

`training_scheduled` and `training_completed` cannot be set through
`PATCH /applicants/{id}/status`; they follow the roster. `GET /applicants/{id}`
returns a `training` block — `requirement_met` is the same check the
endorsement gate makes.

## Deployment and Employee Conversion

### POST /deployments
Request:

```json
{
  "applicant_id": 501,
  "job_request_id": 18,
  "client_company_id": 1,
  "client_department_id": 3,
  "position_title": "Production Helper",
  "supervisor_name": "Mark Reyes",
  "employee_number": "EMP-2026-00123",
  "biometric_number": "BIO-99231",
  "deployment_date": "2026-08-12"
}
```

The body is the client company's own **deployment details** form, which the
agency described as filled in by the client and entered by the admin: which
department, from when, under which supervisor, and with which employee and
biometric number. The system records what the client decided; it does not decide
it.

Response:
- Creates deployment.
- Converts applicant to employee.
- Updates request fulfillment counters.
- Walks the applicant through any remaining lifecycle steps to `active`, each
  one historised, so the timeline does not jump from screening to employed.

Refused (400) unless **all** of the following hold:
- the request can still take a worker and its client company is active;
- the applicant is `approved` or `ready_for_deployment`;
- the applicant is in **Folder 1** — primary and final requirements verified;
- the applicant is not already actively deployed;
- the applicant has **not declined the placement**.

### GET /deployments
- Filters client_company_id, client_department_id, deployment_status
  (`active` or `ended`; 422 otherwise), date_from, date_to (deployment date,
  inclusive). `completed` and `transferred`, listed by the original schema, were
  never written by anything and are no longer accepted (2026-10-30).
### GET /deployments/{id}
### POST /deployments/{id}/reassign *(updated 2026-10-19)*
- Needs `deployment.reassign`. Within the employee's **current client only**.
- Request: `client_department_id` (required), `position_title`,
  `supervisor_name`, `effective_date` (required), `remarks` (the reason,
  required), optional `change_type` (`reassignment` by default), optional
  `client_company_id` (must be the current client).
- Refused: 422 for another client, a department of another client or an
  inactive one, a date before the placement began or its last move, a future
  date, or a change that changes nothing; 409 for a placement or employee that
  is no longer active.
- Response: the deployment with its `history`, each move in names
  (`from_department`, `to_department`, positions, `reason`, `changed_by`).
### GET /employees
- Filters search, employment_status (`active`, `resigned`, `terminated`;
  422 otherwise), client_company_id, client_department_id (the employee's
  current placement). There is no `archived` employee status (removed
  2026-10-30; nothing ever set it): archiving a former employee writes a
  snapshot to Archives and leaves the status as it was.
### GET /employees/{id}
### PATCH /employees/{id}

Rules:
- Cannot deploy if request is closed or fulfilled.
- Employee number must be unique.

## Violation Management

### GET /employees/{id}/violations
### POST /employees/{id}/violations
### PUT /employees/{id}/violations/{violationId}

Create payload:

```json
{
  "violation_date": "2026-08-18",
  "violation_type": "late",
  "description": "Reported late for 3 consecutive days",
  "penalty": "Written warning",
  "status": "open"
}
```

Rules:
- Only active employees can receive new violation records.
- *(added 2026-10-29)* An optional `attendance_review_id` records the violation
  from an attendance review. It is refused unless the review is **verified**,
  belongs to this employee, has no violation yet, and `violation_date` is the
  reviewed day; it also needs `attendance.review`.

## Attendance (DTR) *(reworked 2026-10-29)*

Schedules, the DTR (typed in or imported), the comparison, leave and holidays,
and the review of flagged days. Every endpoint, permission and rule is listed in
`docs/07-project-management/dtr-attendance-rework.md` §6. Key rules:

- A scheduled day with nothing on file is `awaiting_dtr`, never an absence,
  until an import or HR declares the DTR for it received.
- Changing or clearing a day already on file requires `reason`; old times are
  kept as revisions.
- An import is a draft until previewed and confirmed; a day that differs from
  the DTR on file is replaced only with `conflict_action: replace` and a reason;
  the same file cannot be imported twice (409).
- Review statuses: `for_review`, `under_verification`, `verified`, `corrected`,
  `closed`; verified, corrected, closed and reopening need a `note`.

## Separation Management

### GET /separations
### POST /employees/{id}/resignations
### PUT /employees/{id}/resignations/{resignationId}
### POST /employees/{id}/resignations/{resignationId}/complete
### POST /employees/{id}/terminations
### POST /employees/{id}/terminations/{terminationId}/finalise
### POST /employees/{id}/terminations/{terminationId}/withdraw
### PUT /employees/{id}/terminations/{terminationId}/clearance

`GET /separations` filters: `type`, `status` (a resignation or termination
status, or `awaiting_clearance` — resignations `filed` or `accepted` and not
yet cleared, the dashboard figure of that name; 422 otherwise), `open_only`,
`source`
(`portal` | `office`, resignations only). Each row carries `source`,
`requested_exit_date`, `clearance_status`, and for a finalised termination
`notice: {viewed, acknowledged}`.

Resignation payload (filed at the office):

```json
{
  "reason": "Personal reasons",
  "filing_date": "2026-09-01",
  "rendering_days": 30,
  "exit_date": "2026-10-01"
}
```

Termination payload:

```json
{
  "reason": "Repeated AWOL",
  "termination_date": "2026-09-20",
  "status": "for_review"
}
```

Resignation update (`PUT`, `separation.create`):

```json
{
  "status": "info_requested",
  "employee_message": "Please confirm whether you can render the full 30 days.",
  "exit_date": "2026-11-28",
  "clearance_status": "in_progress",
  "remarks": "Internal note, never shown to the employee",
  "withdrawal_reason": "Required with status withdrawn; internal"
}
```

Rules:
- Resignation statuses: `filed` (under review), `info_requested`, `accepted`,
  `completed`, `withdrawn`. HR moves `filed → info_requested | accepted |
  withdrawn`, `info_requested → filed | accepted | withdrawn`, `accepted →
  filed | withdrawn`. Completion is its own action (`confirm: true`), from
  `filed` or `accepted`, once clearance is `cleared` and `exit_date` is set.
- `info_requested` needs `employee_message`; `accepted` needs an `exit_date`;
  `withdrawn` needs `withdrawal_reason` (internal).
- A save that changes nothing writes no history and sends no notification.
- One open resignation per employee, enforced by a row lock and by the unique
  `open_employee_id` column.
- `finalise` takes `confirm: true` and an optional `employee_message`, which is
  the only text from the termination the employee sees. Finalising is the only
  event that notifies the employee; drafts, reviews and withdrawals do not.
- Termination clearance (`pending | in_progress | cleared`) is recorded only
  after finalisation.
- The employee record (`GET /employees/{id}`) adds to each case: `source`,
  `requested_exit_date`, `employee_remarks`, `employee_message`,
  `allowed_statuses`, `history`, and for a finalised termination
  `notice: {issued_at, has_portal_account, notification
  (not_sent | not_delivered | unread | read), notification_read_at, viewed_at,
  acknowledged_at}`.
- Separation completion updates employee status.
- Archiving is enabled only after closure checks pass.

## Notifications and Preferences

### GET /notifications/unread-count
- Returns the badge figure **and** the same figure broken down by category, in
  one request:

```json
{ "data": { "unread_count": 5, "by_category": { "application": 3, "requirements": 2 } } }
```

- The categories are a partition of `unread_count`, not a second tally — which
  is what stops the bell and the per-section sidebar counts from disagreeing.
  The client polls this once and reads it everywhere.
- Categories are `application`, `requirements`, `training`, `deployment`,
  `coordination`, `general`, taken from the notification's own payload. A category with nothing unread is absent
  rather than zero.
- Grouped in PHP rather than SQL on purpose: a JSON path expression is spelled
  differently on MySQL, PostgreSQL and SQLite, and one person's unread
  notifications are a handful of rows.

### GET /auth/notification-preferences
### PATCH /auth/notification-preferences
- `{ "preferences": { "application": true, "requirements": false } }`
- Available to every signed-in user. Unrecognised keys are discarded, so a
  hand-made request cannot write preferences nothing will read.
- A muted category stops producing notifications at all —
  `EmpowerNotification::via()` returns no channels — so the badge, the panel and
  the preference agree by construction rather than by filtering after the fact.
- **Absent preferences mean everything is on.** "Never configured" and
  "configured to nothing" are deliberately different, which is why the column is
  nullable.
- Muting suppresses the alert only. Documents are still verified and placements
  still recorded; nothing in the recruitment process depends on a notification
  having been delivered.
- *(updated 2026-10-19)* For a notification addressed to an applicant or
  employee record, the preferences that apply are those of the portal login
  linked to that person by either `applicant_id` or `employee_id`
  (`User::portalAccountFor()`) — the same login whose inbox reads it
  (`User::notifiableRecords()`). Employee logins linked only to the employee
  record used to have their muted categories ignored for anything addressed to
  the applicant record.

## Applicant and Employee Portal

Prefixed `/portal` and gated by the `portal` middleware. **No endpoint here
accepts a record identifier.** Each resolves the applicant or employee from the
signed-in user's own link, so there is nothing in a URL for a curious user to
change. Portal accounts hold no staff permissions at all.

### GET /portal
- The landing view: current status in plain language, the stage number, what
  documents are outstanding, employment details if any, and recent activity.
- `placement_decision_due` is true only while a client is considering the
  applicant (`client_evaluation` or `approved`) and they have not yet answered.
- `application.stages` is the path in lifecycle order (`key`, `label`), the
  same list `GET /applicants/{id}` gives the staff screen; Training is the
  fourth stage, between Requirements and Client evaluation.
- `training` is the block `GET /portal/training` returns. Reading it marks no
  notification read.

### GET /portal/training
- The applicant's own training, read from the roster:
  `{ status, label, explanation, required, sessions: [...] }`.
- `status`: `completed` (a completion recorded in a session that was not
  cancelled — the endorsement gate's own check), else `in_progress`,
  `scheduled` or `missed` from the sessions, else `not_required` (the
  *Require training before deployment* setting is off), `not_reached` (before
  the Training stage), `pending` (at or past it, nothing scheduled) or
  `not_recorded` (deployed with no completion on record).
- Each session: `title`, `training_code`, `date`, `location`, `trainer_name`,
  the applicant's `attendance_status` and `completion_status`, `completed_on`,
  and `state` — `scheduled`, `in_progress` (marked present, session under way
  or over, or its date passed, with no completion recorded), `completed`,
  `missed` (marked absent) or `cancelled`. Remarks and officers' names are not
  included, and no start time exists to include.

### GET /portal/documents
- The applicant's own checklist, each row carrying `review_state` and
  `review_state_label` as described under Requirement Management.

### POST /portal/documents/{requirementTypeId}/upload
- Multipart: `file`, and `expiry_date` where the document expires.
- Lands in `status: submitted` with `review_state: uploaded` — "Upload
  successful". Verification stays with HR against the physical original, so
  **this cannot make anybody deployable**.
- Returns the updated requirement, so the page can correct one row rather than
  reloading and losing the applicant's place in the checklist.
- Refused (409) for a document the office has already accepted.

### POST /portal/documents/check-readable
- Reads a document without storing it, so an applicant can find out at home that
  a photo is too blurred to use. Throttled to 10 per hour: each call is roughly
  forty seconds of CPU on the recognition service.

### GET /portal/documents/{requirementTypeId}/download
- A short-lived link to the applicant's own document. Deliberately does **not**
  set `first_viewed_at`: an applicant reading what they sent is not the office
  reviewing it.

`GET /portal` also returns a `deployment` block, which is **null until the
applicant is genuinely placed**. It requires both signals to agree: the
lifecycle status is `deployed` or `active`, *and* an active deployment row
exists. Being approved by the client, being ready for deployment, or having
every document verified all leave it null — congratulating somebody at those
points would be telling them they have a job they have not got. Every value in
it is read from the deployment the client company filled in, so it survives a
refresh, a new session, and a different device.

### GET /portal/profile
- The applicant's own record, split into `identity` (read-only), `editable`, and
  `account`, with `locked_fields_reason` explaining why the first is read-only.

### PATCH /portal/profile
- Accepts `contact_number`, `email`, `availability_date` and nothing else. Name,
  date of birth, and address were verified against documents at the office, and
  a verified record must not drift away from the paperwork supporting it.

### POST /portal/placement-response
- Request: `{ "response": "accepted" | "declined", "note": "optional" }`
- The one decision in the recruitment process that belongs to the applicant. The
  agency's process has the candidate decide, after training, whether they still
  want the job.
- Records `placement_response`, `placement_responded_at` and the note; audits
  it; notifies HR. **Changes no status** — the client's approval and the
  agency's decision are separate acts that still have to happen, and what a
  withdrawal means for someone's place in the pool is the agency's judgment.
- Refused (409) when no placement is awaiting an answer, and when one has
  already been given.
- A declined placement blocks `POST /deployments` until HR clears it.

### GET /portal/employment
- The employee half of the portal: current placement, deployment history,
  disciplinary record, and `separation`: `eligibility {can_request, reason}`,
  `notice_days`, the current `resignation` (status label, requested and
  confirmed last day, HR's message, clearance once accepted, next steps,
  `can_withdraw`, `can_respond`, timeline), `past_resignations`, and a
  termination `notice` once one is finalised (effective date, HR's message,
  clearance, next steps, office contact, viewed/acknowledged). The reason,
  remarks and documents on a termination are never included.
- Opening it records the first view of a termination notice.

### POST /portal/resignation
- Multipart: `requested_exit_date` (today to one year ahead), `reason`,
  `remarks` (optional), `letter` (optional file), `acknowledged` (must be
  accepted). The employee, placement and filing date come from the server.
- 403 without employment; 409 when the employment has ended or a request is
  already open.

### POST /portal/resignation/withdraw, POST /portal/resignation/respond
- Withdraw (optional `reason`) while the request is `filed` or
  `info_requested`; 409 once HR has accepted it. Respond (`message`) only while
  `info_requested`; it returns the request to review.

### GET /portal/resignation/letter, POST /portal/separation-notice/acknowledge
- A short-lived link to the employee's own letter; receipt of a finalised
  termination notice (idempotent, changes no status).

Rules:
- Applicants can never set a review or lifecycle status. There is no route, and
  the fields are not mass assignable.
- Staff are refused by the `portal` middleware, exactly as portal users are
  refused by the staff routes.

## Archive and Audit

### GET /archives
Filters:
- entity_type, date_from, date_to (archived date), search - case-insensitive
  on every engine, including MariaDB, whose JSON column is binary-collated.
  Accented names are found however the snapshot stored them: snapshots
  written before 2026-10-30 hold `ñ` as `\u00f1`, newer ones as the letter.

### GET /archives/{id}
### GET /audit-logs *(updated 2026-10-19)*
- Administrator only (`audit.view`). Read-only: no endpoint edits or deletes an
  entry.
- Filters: `actor_user_id`, `actor_role`, `client_company_id`, `action_type`,
  `module_key`, `record_type`, `record_id`, `date_from`, `date_to`.
- Each entry: `actor { id, name }`, `actor_role`, `company_name`,
  `record { type, id, label }` (for example "APP-2026-0007 · Juan Dela Cruz"),
  `detail_level` and `changes`. `detail_level` is `changes` when the entry holds
  a value before and after, `values` when it holds only what was written, and
  `activity` when it holds no values at all. Each change row is
  `{ field, label, kind, before, after }` with `kind` `changed`, `recorded` (only
  the new value is known) or `previous` (only the old one). Credentials are
  shown as `[redacted]` at any depth.

### GET /audit-logs/facets *(new 2026-10-19)*
- The action types, modules, record types, roles and acting users that occur in
  the trail, for the filters.

### GET /retention-reviews *(new 2026-10-19)*
- Needs `archives.view` and `applicants.view`. Filter `category`
  (`unhired_applicant`, `closed_record_documents`), paginated.
- `{ policy, counts, undated_closed_records, rows }`. Each row names the record,
  the relevant date and why it is listed, the review due date, any earlier
  decision whose date has passed, and the `actions` offered (`retain`; `archive`
  for unhired applicants, through `PATCH /applicants/{id}/status`). Computed from
  the retention settings; nothing is archived or deleted by listing.

### POST /retention-reviews *(new 2026-10-19)*
- Needs `archives.create`. `{ category, applicant_id, note, retain_until }` keeps
  a listed record on file until the date (after today, within ten years).
  **409** when the record is not due in that category. Audited.

## Dashboard *(updated 2026-10-30)*

### GET /dashboard/summary
- `links.ready_for_deployment` and `links.incomplete_requirements` are the
  `GET /applicants` filters each figure was counted with, so the figure and the
  list it opens are the same people. "Ready" is Folder 1 in
  `ready_for_deployment`, `client_evaluation` or `approved`; "incomplete
  requirements" is `initial_screening`, `incomplete_requirements` or
  `pending_final_requirements` (`ApplicantStatuses`).
- "Ready for deployment" also leaves out anyone placed today whatever their
  status says, and its link carries `exclude_deployed=1`.
- `attention.pending_clearance` counts resignations waiting on HR (`filed` or
  `accepted`, not yet cleared); `attention.resignations_awaiting_employee`
  counts those at `info_requested`, waiting on the employee's answer. Their
  `links` open `GET /separations` on exactly those cases
  (`status=awaiting_clearance` and `status=info_requested`).

## Searching *(2026-10-30)*

Every `search` (and the global `GET /search?q=`) matches the text as typed:
`%` and `_` are characters, not wildcards, so `50%` no longer finds "500" and a
lone `%` no longer returns everything (`App\Support\TextSearch`). Case is
ignored on every engine.

## Data repair: `php artisan empower:reconcile-placement-status` *(2026-10-30)*

Lists applicants whose status disagrees with the employment record; with
`--apply --actor=<staff email>` corrects the two shapes the database itself
proves (placed but not marked → `active`; marked employed with no employee
record → the status before the move to `deployed`, from the history), each
with a status-history row, an audit entry and a reason. Everything else is
listed for a person to decide. A dry run by default.

## Reports *(updated 2026-10-19)*

### GET /reports
- The catalogue: each report's `filters`, and `options` — the values each choice
  filter accepts for that report, from the vocabulary that owns them.
### POST /reports/preview
- Also returns `letterhead` (`agency`, `address`, from the organisation
  settings) for the printed preview *(2026-10-29)*.
- The Applicant Report's `current_status=deployed` reads the employment record,
  as `GET /applicants` does. Rows are ordered with id as the last key, so the
  100-row preview is the start of the export *(2026-10-30)*.
### POST /reports/export
- PDF and Excel are laid out like CDE's Manpower Requisition Form *(2026-10-29)*:
  letterhead (logo, agency, address, title in capitals), "Label: value" lines
  (Report, Period, Number of Records, Date generated, Prepared by), the summary,
  the table, and Prepared / Reviewed / Approved by signature lines. In Excel the
  letterhead fills rows 1–5, the headings are on row 6 (frozen and filtered),
  and rows 1–6 repeat on every printed page. CSV stays plain data.

Request:

```json
{
  "report_type": "deployments",
  "format": "xlsx",
  "filters": {
    "client_company_id": 1,
    "date_from": "2026-01-01",
    "date_to": "2026-12-31"
  }
}
```

- `format` (`pdf`, `xlsx`, `csv`) is required (422 without it). A choice filter
  with a value outside the report's own `options` is refused (422).
- The rows are counted first. Below `empower.reports.queue_threshold_rows`
  (1000) the file is built once and returned directly; at or above it the export
  is queued and built once, by the job.
- Text that could run as a spreadsheet formula (`=`, `+`, `-`, `@` and the
  control characters before them) is written as text: prefixed with `'` in CSV,
  stored as an explicit string cell in XLSX. Numbers are untouched.

Response 202 (queued):

```json
{
  "success": true,
  "message": "Large report queued for generation",
  "data": {
    "export_id": 87,
    "status": "queued"
  }
}
```

### GET /reports/exports/{id}
- Returns `queued`, `processing`, `completed` or `failed`, and a download URL
  when completed. A job whose first attempt fails goes back to `queued` for its
  retry, and to `failed` only after the last attempt.
### GET /reports/exports/{id}/download

## Coordinator Functions *(new 2026-10-28, CDE meeting 2026-10-26)*

CDE named three coordinator functions: violation report, incident report and
manpower requisition. Every `/coordinator/*` route scopes to the client in the
coordinator's active assignment, resolved on the server; no route reads a
client id from the request. Coordinators hold only `coordination.access`.

### Violation reports (existing)
- `GET /coordinator/employees?search=&status=active&per_page=20` now backs a
  type-to-search employee picker on the report form (the old dropdown loaded
  100 and stopped). The search is word by word over names and employee number
  *(2026-10-30)*.
- The three coordinator lists accept a group status besides their own:
  `pending` (violation reports, requisitions: submitted or under review) and
  `open` (incident reports). Each `options` endpoint lists it first. The
  dashboard figures open these lists on exactly what they count, with `mine=1`
  where the figure is the coordinator's own *(2026-10-30)*.

### Incident reports
- `GET /coordinator/incident-reports/options` · `GET /coordinator/incident-reports`
- `POST /coordinator/incident-reports` (multipart; `title`, `incident_date`,
  `incident_time` HH:MM, `client_department_id`, `location`, `details`,
  `evidence`, `submit`)
- `GET|POST /coordinator/incident-reports/{id}` (POST edits a draft)
- `POST /coordinator/incident-reports/{id}/submit` · `DELETE` (own draft only)
- `POST /coordinator/incident-reports/{id}/notes` (`note`) — follow-up only;
  a coordinator cannot change the status.
- `GET /coordinator/incident-reports/{id}/evidence` — short-lived signed link.
- Office (`incident_reports.view` / `.review`): `GET /incident-reports`
  (default `status=open`), `GET /incident-reports/{id}`,
  `POST /incident-reports/{id}/notes` (`note`, `status`: `under_review` "case
  pending" | `resolved` "case resolved"; a note is required except to
  acknowledge a new report), `GET /incident-reports/{id}/evidence`.
- An incident names no employee and never creates a violation.

### Manpower requisitions (CDE Manpower Requisition Form, from 2026-10-29)
- `GET /coordinator/requisitions/options` — the form definition: `provisional`
  (false), `version` (`cde-2026-10`), `title`, the five `reasons` in the form's
  order, the client's departments and positions.
- `GET|POST /coordinator/requisitions`, `GET|POST /coordinator/requisitions/{id}`,
  `POST …/{id}/submit`, `DELETE …/{id}` (own draft), `GET …/{id}/attachment`
  (temporary-form requisitions only; CDE's form takes no upload).
- Required: `date_requested` ("Date requested", ≤ today), `required_by` ("Date
  Needed", ≥ date_requested), `workers_needed` ("Total Manpower Needed", ≥ 1),
  `position_title` or `job_position_id`, `request_reason` (`new_position` |
  `replacement` | `additional_manpower` | `reliever_manpower` | `others`).
  `request_reason_details` is required for `others`, optional for
  `replacement` (whom it replaces), and cleared for the rest.
  Optional: `male_count`, `female_count` (sum ≤ `workers_needed`),
  `qualifications` ("Required Qualification(s)"), `other_remarks`,
  `client_department_id` (EMPOWER's, not on CDE's form). Any other key,
  including the temporary form's old fields, is ignored.
- A draft or returned requisition started on the temporary form is completed on
  CDE's form: saving it takes the current `form_version`, and `submit` is
  refused (422 `request_reason`) until a reason is ticked. What the temporary
  form recorded (`reason`, `gender_requirement`, `employment_type`,
  `work_location`, `work_schedule`, `compensation_notes`,
  `additional_instructions`, `client_requester_name`, the attachment) is never
  changed and is still returned.
- Responses add `request_reason_label`, `other_remarks`, and `acknowledged_by`
  (`{name, at}` of whoever approved it, read from the status history; null
  before approval).
- HR (`requisitions.view` / `.review`): `GET /requisitions` (default
  `status=pending`), `GET /requisitions/{id}`,
  `POST /requisitions/{id}/review` (`action`: `start_review` | `return` |
  `approve` | `reject` | `fulfil`; `note` required for return and reject),
  `GET /requisitions/{id}/attachment`.
- Status: draft → submitted → under_review → approved → fulfilled, with
  returned (back to the coordinator) and rejected. Submission approves
  nothing; nobody reviews a requisition they filed (403). Every change writes
  `manpower_requisition_status_history` and the audit trail.
- An approved requisition becomes a manpower request through
  `POST /job-requests` with `manpower_requisition_id`; the two are linked in one
  transaction, once only (409 otherwise).

## Document Expiry After Deployment *(new 2026-10-28)*
- `requirement_types.post_deployment_rule`: `pre_deployment_only` |
  `renew_while_employed` | null (not confirmed by CDE). Editable through
  `PATCH /settings/requirement-types/{id}`; a change recalculates folders.
- Requirement rows (staff and portal) carry `expiry_assessment`
  (`state`, `label`, `needs_action`, `days_left`, `rule`, `rule_label`) and, on
  the staff screen, `history` (versions a renewal replaced).
- Dashboard `attention.expired_documents` now counts only applicants not yet
  deployed (whether or not the daily check has marked the document);
  `attention.employee_renewals_overdue` counts deployed workers' lapsed
  renew-while-employed documents.
- Portal: a deployed worker may send a renewal over an accepted document once
  its renewal is due; the version replaced is kept.

## Departments *(updated 2026-10-28)*
- `POST /clients/{id}/departments`: `department_code` is optional; when blank
  one is derived from the name, unique within the client.
- `PUT /clients/{id}/departments/{departmentId}`: switching a department to
  `inactive` while active employees are placed in it returns 409.
- `GET /clients/{id}/departments/{departmentId}/employees` also returns
  `moves` — reassignments into and out of the department.

## Sign-in Monitoring *(new 2026-10-28, administrator only)*
- `GET /security/sessions` — live sessions: account, device, IP, signed in,
  last active, ends. No token or hash is returned.
- `GET /security/sign-ins` — successful and failed attempts, sign-outs and
  ended sessions from the audit trail (`result`, `search`, `date_from`,
  `date_to`).
- `DELETE /security/sessions/{id}` — signs that device out (not your own).
- Permissions `sessions.view` and `sessions.revoke`, held by the administrator
  only.

## Validation Matrix (Critical)
1. Lifecycle transition validation map is mandatory for applicant and employee status updates.
2. Requirement verification and folder categorization must run in same transaction.
3. Deployment requires:
- request status open or in_progress,
- remaining headcount,
- candidate approved state,
- required documents complete.
4. Resignation and termination closure must include mandatory fields before archive operation.
5. Every create, update, delete, and workflow action generates audit log entry.

## Idempotency and Concurrency
- Use request_id header for high-impact commands such as deployment and separation finalization.
- Reject duplicate deployment request_id with 409 response.
- Use optimistic locking by updated_at checks on long edit forms.

## Security Requirements
- Sanctum token revocation on logout and forced session invalidation by admin.
- Rate-limit login and file upload endpoints.
- Strict file validation and malware scan hook before final document status transition.
- Policy-level authorization checks for each endpoint action.
