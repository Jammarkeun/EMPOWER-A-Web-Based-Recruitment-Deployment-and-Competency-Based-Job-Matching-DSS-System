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
  "preferred_position": "Production Helper",
  "email": "ana.reyes@example.com",
  "password": "secret1234",
  "password_confirmation": "secret1234"
}
```

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

Business rules:
- Department name must be unique per client.
- Inactive client cannot receive new job request.

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
### POST /applicants/{id}/requirements/{requirementTypeId}/upload
Multipart fields:
- file, expiry_date, remarks.

### PATCH /applicants/{id}/requirements/{requirementTypeId}
Request:

```json
{
  "status": "verified",
  "remarks": "Clear and valid",
  "verified_at": "2026-08-04T10:15:00+08:00"
}
```

### GET /applicants/{id}/folder-category
- Returns folder_3, folder_2, or folder_1 with explanation.

Rules:
- Folder category is derived and read-only.
- Rejected status requires rejection_reason.

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

Response:
- Creates deployment.
- Converts applicant to employee.
- Updates request fulfillment counters.

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
