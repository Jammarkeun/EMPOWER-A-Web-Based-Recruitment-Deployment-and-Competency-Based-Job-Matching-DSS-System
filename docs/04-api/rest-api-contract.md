# EMPOWER REST API Contract

## API Standards
- Base URL: /api/v1
- Auth: Laravel Sanctum bearer token for protected endpoints.
- Format: application/json, UTF-8.
- Timezone: Asia/Manila, stored in UTC.
- Pagination: page, per_page, sort_by, sort_dir.
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

### POST /auth/logout
- Public: No.
- Response 200: Token revoked.

### GET /auth/me
- Public: No.
- Response 200: Current user profile, roles, permissions.

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

### POST /register/check-email
- Public: Yes. Throttled to 20 requests per minute.
- Request: `{ "email": "ana@example.com" }`
- Response 200: `{ "data": { "available": true } }`

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

## Access Control

### GET /roles
### POST /roles
### PUT /roles/{id}
### GET /permissions
### PUT /roles/{id}/permissions
### GET /users
### POST /users
### PUT /users/{id}
### PUT /users/{id}/status

Validation highlights:
- role_key unique.
- email unique.
- admin role assignment requires admin privilege.

## Client Company and Departments

### GET /clients
Filters:
- status, search, business_type.

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
- client_company_id, department_id, request_status, date_from, date_to, deadline_from, deadline_to.

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

### GET /applicants
Filters:
- source_channel, current_status, folder_category, readiness, search.
- `awaiting_identity_check=1` returns only applicants who registered online and
  have not yet been seen at the office. This is the queue the counter works from.

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

## Training Management

### GET /trainings
### POST /trainings
### GET /trainings/{id}
### POST /trainings/{id}/enrollments
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
### GET /deployments/{id}
### POST /deployments/{id}/reassign
### GET /employees
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

## Separation Management

### POST /employees/{id}/resignations
### PUT /employees/{id}/resignations/{resignationId}
### POST /employees/{id}/terminations
### PUT /employees/{id}/terminations/{terminationId}

Resignation payload:

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

Rules:
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
- Categories are `application`, `requirements`, `deployment`, `general`, taken
  from the notification's own payload. A category with nothing unread is absent
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

### GET /portal/employment, POST /portal/resignation
- The employee half of the portal: current placement, deployment history,
  disciplinary record, and filing a resignation for HR to process.

Rules:
- Applicants can never set a review or lifecycle status. There is no route, and
  the fields are not mass assignable.
- Staff are refused by the `portal` middleware, exactly as portal users are
  refused by the staff routes.

## Archive and Audit

### GET /archives
Filters:
- entity_type, archived_at_from, archived_at_to, search.

### GET /archives/{id}
### GET /audit-logs
Filters:
- actor_user_id, action_type, module_key, date_from, date_to.

## Reports

### GET /reports/dashboard-summary
### POST /reports/export
Request:

```json
{
  "report_type": "deployment",
  "export_format": "xlsx",
  "filters": {
    "client_company_id": 1,
    "date_from": "2026-01-01",
    "date_to": "2026-12-31"
  }
}
```

Response 202:

```json
{
  "success": true,
  "message": "Export queued",
  "data": {
    "export_id": 87,
    "status": "queued"
  }
}
```

### GET /reports/export/{id}
- Returns queued, processing, completed, failed and file URL when completed.

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
