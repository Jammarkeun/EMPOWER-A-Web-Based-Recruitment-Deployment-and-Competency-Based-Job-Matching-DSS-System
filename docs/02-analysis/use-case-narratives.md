# EMPOWER Use Case Narratives

## Scope and Notation
- Actors: Administrator, HR Staff, Applicant, Employee.
- All critical operations create audit trail entries.
- Final hiring decision always remains with HR Staff.
- Payroll and compensation handling are out of scope.

## UC-01: User Authentication and Session Control
- Primary Actor: Administrator, HR Staff, Applicant, Employee.
- Trigger: User opens login page and submits credentials.
- Preconditions: Active user account exists.
- Main Flow:
1. User enters email and password.
2. System validates credentials and account status.
3. System issues authenticated session and token.
4. System redirects user to role-specific dashboard.
5. System logs successful login activity.
- Alternate Flows:
1. Invalid credentials: System returns generic login error and logs failed attempt.
2. Inactive account: System denies access and advises contact with HR.
- Postconditions: Authenticated session established.

## UC-02: Manage Roles and Permissions
- Primary Actor: Administrator.
- Trigger: Admin opens access control module.
- Preconditions: Admin is authenticated and authorized.
- Main Flow:
1. Admin creates or updates role definitions.
2. Admin assigns module permissions per role.
3. Admin maps roles to target users.
4. System validates permission integrity and persists changes.
5. System logs role and permission changes.
- Alternate Flows:
1. Duplicate role key: System blocks save and requests unique key.
- Postconditions: Access matrix updated and enforced.

## UC-03: Register and Maintain Client Companies
- Primary Actor: Administrator, HR Staff.
- Trigger: Client company profile needs creation or update.
- Preconditions: Actor has client management permission.
- Main Flow:
1. User opens client registry and enters company profile details.
2. User saves client company record.
3. User adds one or more departments under the company.
4. System validates uniqueness and marks company as active or inactive.
5. System logs creation or updates.
- Alternate Flows:
1. Department already exists in same company: Save is blocked.
- Postconditions: Company and department records are centrally available.

## UC-04: Encode Job Request from Client Communication
- Primary Actor: HR Staff.
- Trigger: Client requests manpower.
- Preconditions: Client company and department are active.
- Main Flow:
1. HR creates a new job request entry.
2. HR fills position, quantity, criteria, and deadline fields.
3. HR sets request status to open.
4. System validates date and headcount consistency.
5. System stores request and records audit entry.
- Alternate Flows:
1. Department mismatch with company: System blocks save.
- Postconditions: Job request becomes available for matching and fulfillment tracking.

## UC-05: Register Applicant and Build Applicant Profile
- Primary Actor: HR Staff.
- Trigger: Applicant appears via walk-in, Messenger, or email intake.
- Preconditions: HR is authenticated.
- Main Flow:
1. HR searches for duplicate applicant by name and contact.
2. HR creates applicant profile and source channel metadata.
3. HR encodes personal, education, employment, skills, and certification details.
4. System sets initial status to applied.
5. System logs profile creation.
- Alternate Flows:
1. Duplicate candidate found: HR updates existing record instead of creating new one.
- Postconditions: Applicant profile is ready for requirement tracking.

## UC-06: Track and Verify Requirements with Folder Logic
- Primary Actor: HR Staff, Applicant (limited uploads).
- Trigger: Requirements are submitted or reviewed.
- Preconditions: Applicant profile exists.
- Main Flow:
1. HR or Applicant uploads requirement document.
2. System validates file type and size.
3. HR verifies document and sets status: submitted, pending, verified, rejected, expired.
4. System recalculates folder category:
5. Folder 3 if resume only or minimal requirements.
6. Folder 2 if all primary requirements are complete.
7. Folder 1 if all primary and final requirements are complete.
8. System updates applicant lifecycle status according to completeness rules.
9. System logs every verification change.
- Alternate Flows:
1. Rejected document: System requires rejection reason.
2. Expired document: System flags for replacement.
- Postconditions: Applicant document readiness is visible and searchable.

## UC-07: Advance Applicant Through Lifecycle Statuses
- Primary Actor: HR Staff.
- Trigger: Screening or process event requires status transition.
- Preconditions: Current status and gate rules are satisfied.
- Main Flow:
1. HR selects applicant and chooses target status.
2. System validates allowed transition map.
3. System checks requirement and training gates if needed.
4. System writes status history entry.
5. System updates current status in applicant profile.
6. System logs transition activity.
- Alternate Flows:
1. Gate condition not met: Transition is blocked with actionable message.
- Postconditions: Applicant status accurately reflects progress.

## UC-08: Configure Criteria and Run Competency Matching
- Primary Actor: HR Staff, Administrator.
- Trigger: Open request requires candidate ranking.
- Preconditions: Job request criteria are configured.
- Main Flow:
1. HR selects target job request.
2. System loads request-specific weighted criteria.
3. HR runs evaluation for candidate pool.
4. System computes weighted percentage and hard filter compliance.
5. System returns ranked list with per-criterion explanation.
6. HR reviews recommendations and marks shortlist.
7. System stores scoring snapshots for traceability.
- Alternate Flows:
1. Missing mandatory criteria config: Evaluation is blocked.
- Postconditions: Explainable ranked recommendations are available for HR decision.

## UC-09: Schedule and Track Pre-Deployment Training
- Primary Actor: HR Staff.
- Trigger: Candidate reaches ready for deployment state.
- Preconditions: Candidate is shortlisted or approved for training.
- Main Flow:
1. HR creates training schedule.
2. HR enrolls selected applicants.
3. HR records attendance and completion remarks.
4. System updates training status and applicant progress.
5. System logs training events.
- Alternate Flows:
1. Candidate absent: Status remains pending and deployment is blocked.
- Postconditions: Training readiness is documented before client evaluation.

## UC-10: Record Client Evaluation and Deployment
- Primary Actor: HR Staff.
- Trigger: Client confirms selected candidate.
- Preconditions: Applicant passed required gates and client evaluation.
- Main Flow:
1. HR opens deployment wizard from approved candidate.
2. HR enters assignment details: company, department, position, supervisor, employee number, biometrics number, date.
3. System creates deployment record.
4. System converts applicant to employee record.
5. System updates job request fulfilled count and status.
6. System writes applicant and employee timeline entries.
7. System logs deployment action.
- Alternate Flows:
1. Job request already fulfilled: System blocks new assignment unless override role.
- Postconditions: Candidate becomes active employee with linked deployment history.

## UC-11: Manage Active Employee Profile and Assignment History
- Primary Actor: HR Staff, Employee (read-only limited fields).
- Trigger: Employee profile update or assignment change.
- Preconditions: Employee record exists.
- Main Flow:
1. HR views employee 360 profile.
2. HR updates non-sensitive profile fields and assignment data.
3. System appends deployment history entry on reassignment.
4. System updates current assignment summary.
5. System logs changes.
- Alternate Flows:
1. Invalid assignment move: System blocks due to inactive department or company.
- Postconditions: Current and historical assignment data stay consistent.

## UC-12: Record Employee Violations
- Primary Actor: HR Staff.
- Trigger: Incident requiring disciplinary entry.
- Preconditions: Employee is active.
- Main Flow:
1. HR creates violation case with type, date, description, evidence, and penalty.
2. System saves incident and status.
3. System displays violation history within employee profile.
4. System includes incident in dashboard and reports.
5. System logs disciplinary action.
- Alternate Flows:
1. Missing evidence for required policy type: System prompts completion before finalizing case.
- Postconditions: Disciplinary data becomes part of employee lifecycle records.

## UC-13: Process Resignation
- Primary Actor: Employee, HR Staff.
- Trigger: Employee submits resignation request.
- Preconditions: Employee account is active.
- Main Flow:
1. Employee uploads resignation request or HR encodes filed letter.
2. HR records rendering days, reason, and target exit date.
3. HR tracks clearance status.
4. Upon completion, system marks employee as resigned.
5. System schedules archival workflow.
6. System logs all events.
- Alternate Flows:
1. Resignation withdrawn before acceptance: Record marked withdrawn.
- Postconditions: Employee transitions to resigned status with complete separation trail.

## UC-14: Process Termination
- Primary Actor: HR Staff, Administrator (approval where needed).
- Trigger: Management decision to terminate employee.
- Preconditions: Documentation and reason are available.
- Main Flow:
1. HR creates termination record with reason and supporting documents.
2. Authorized approver confirms termination details.
3. System marks employee as terminated.
4. System updates employment timeline and case linkage.
5. System schedules archive action.
6. System logs termination event.
- Alternate Flows:
1. Approval required but missing: Record remains for review and status cannot finalize.
- Postconditions: Termination is fully documented and reportable.

## UC-15: Archive Lifecycle Records and Search History
- Primary Actor: Administrator, HR Staff.
- Trigger: Employee reaches resigned or terminated closure.
- Preconditions: Separation process is complete.
- Main Flow:
1. HR or system job initiates archival snapshot.
2. System stores immutable archive copy of linked entities.
3. System marks active profile status as archived.
4. Users with permission can search archived records.
5. System logs archive operations.
- Alternate Flows:
1. Missing mandatory closure data: Archiving is postponed with checklist warnings.
- Postconditions: Historical records remain searchable and preserved.

## UC-16: Dashboard and Report Generation
- Primary Actor: Administrator, HR Staff.
- Trigger: User opens analytics page or requests formal report.
- Preconditions: User has reporting permission.
- Main Flow:
1. User selects date range, module, and filters.
2. System computes metrics and displays dashboard widgets.
3. User requests export to PDF, Excel, or print view.
4. System generates report artifact and stores export metadata.
5. System logs report generation activity.
- Alternate Flows:
1. Large export workload: System queues job and notifies user when ready.
- Postconditions: Timely operational and management reporting is available.

## UC-17: Audit Trail Review
- Primary Actor: Administrator.
- Trigger: Compliance review or incident investigation.
- Preconditions: Admin is authenticated.
- Main Flow:
1. Admin filters logs by user, module, action, and date.
2. System returns audit entries with before and after values where applicable.
3. Admin exports or prints audit summary.
- Alternate Flows:
1. No result set: System returns empty state with filter hints.
- Postconditions: Compliance evidence is retrievable.

## Global Business Rules
1. No automatic hiring or deployment is allowed.
2. All deployment actions require explicit HR confirmation.
3. Folder category is system-derived and not manually editable.
4. Status transitions must follow configured lifecycle map.
5. Audit logging is mandatory for all high-impact transactions.
6. Separation always precedes archival for employee records.
