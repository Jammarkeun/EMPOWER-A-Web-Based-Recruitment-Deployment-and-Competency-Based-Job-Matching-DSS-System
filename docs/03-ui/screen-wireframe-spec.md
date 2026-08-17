# EMPOWER Screen-by-Screen Wireframe Specification

## Design Direction
- Visual theme: Professional operations dashboard with warm neutral base, deep blue action color, and amber alert scale.
- Typography: Headings in Poppins, body in Source Sans 3.
- Interaction style: Workflow panels, progress rails, validation-first forms.
- Layout grid: 12-column desktop, 6-column tablet, 4-column mobile.
- Accessibility baseline: AA contrast, keyboard navigation, visible focus states, form error summaries.

## Navigation Architecture
- Primary Areas:
1. Dashboard
2. Applicants
3. Requirements
4. Job Requests
5. Matching
6. Training
7. Deployments
8. Employees
9. Violations
10. Separation
11. Reports
12. Administration
13. Audit Logs
14. Archives

- Role Visibility:
1. Admin sees all areas.
2. HR sees all except system-level user-permission configuration screens.
3. Applicant/Employee portal sees only personal and status screens.

## Global Components
1. Top Bar
- Brand, search, notifications, profile menu.

2. Left Sidebar
- Role-aware menu, collapse button, active module marker.

3. Status Badge System
- Applied, Incomplete, Ready for Deployment, Active Employee, Resigned, Terminated, Archived.

4. Evidence and Document Viewer
- Secure preview modal with metadata and verification controls.

5. Timeline Rail
- Vertical chronological event stream with actor and timestamp.

6. Smart Filter Bar
- Date range, client company, department, status, source channel.

## Screen S-01: Login
- Users: All.
- Purpose: Secure authentication entry.
- Layout:
1. Left panel with project identity and compliance notice.
2. Right panel with login form and forgot-password link.
- Fields:
1. Email (required, email format).
2. Password (required, min length).
- Actions:
1. Sign In.
- Validation:
1. Generic invalid credential message.
2. Lockout warning after repeated failed attempts.

## Screen S-02: Role-Based Dashboard
- Users: Admin, HR.
- Purpose: Operational visibility and priorities.
- Widgets:
1. KPI cards: applicants, active employees, open requests, ready for deployment.
2. Compliance card: pending requirements and expiring documents.
3. Funnel chart: lifecycle progression.
4. Monthly trends: hiring, deployments, separations.
5. Violation heatmap by type and month.
6. Work queue: items requiring action today.
- Actions:
1. Drill down into filtered module views.

## Screen S-03: Client Company Directory
- Users: Admin, HR.
- Purpose: Manage company records for request and deployment linkage.
- Panels:
1. Company list with status and active request count.
2. Company detail drawer with contacts and address.
3. Department subtable.
- Fields:
1. Company name, business type, contact details, office address, status.
- Actions:
1. Add company.
2. Edit company.
3. Add department.
4. Deactivate company.
- Validation:
1. Unique company name per active record policy.

## Screen S-04: Job Request Workbench
- Users: HR.
- Purpose: Encode, monitor, and fulfill manpower requests.
- Panels:
1. Request list with status chips and deadline indicators.
2. Request detail card with criteria summary and fulfillment progress.
3. Activity log panel.
- Fields:
1. Client, department, position, required education, required experience, certifications.
2. Gender, age range, height minimum, physical and availability requirement.
3. Workers needed, request date, deadline.
- Actions:
1. Create request.
2. Set criteria weights.
3. Open matching workspace.
4. Close or cancel request.
- Validation:
1. Deadline cannot be earlier than request date.
2. Workers needed must be positive integer.

## Screen S-05: Applicant Registry
- Users: HR.
- Purpose: Central intake list and quick triage.
- Layout:
1. Left filter rail by source, status, folder, readiness.
2. Main table with sticky columns for name, status, folder category.
3. Bulk action bar for status updates and export.
- Actions:
1. Register new applicant.
2. Open applicant 360 profile.
3. Move to screening queue.
- Validation:
1. Duplicate warning by full name plus contact check.

## Screen S-06: Applicant Intake Form
- Users: HR.
- Purpose: Structured intake from walk-in, Messenger, or email leads.
- Sections:
1. Personal information.
2. Contact and address.
3. Education.
4. Employment history.
5. Skills and certifications.
6. Source channel and application date.
- Actions:
1. Save draft.
2. Submit profile.
- Validation:
1. Required identity fields.
2. Date consistency for employment history.

## Screen S-07: Applicant 360 Profile
- Users: HR.
- Purpose: Single-screen complete applicant case view.
- Regions:
1. Header with status, folder category, readiness score.
2. Timeline rail for lifecycle events.
3. Tab set: Profile, Requirements, Matching, Training, Notes.
4. Right action column: status change, add note, schedule training.
- Actions:
1. Transition status with reason.
2. Upload or verify requirement.
3. Trigger matching for open request.

## Screen S-08: Requirements Compliance Board
- Users: HR, Applicant (limited).
- Purpose: Manage requirement completeness and verification workflow.
- Layout:
1. Matrix table: requirement type by applicant with status icons.
2. Summary cards: missing, pending verification, rejected, expiring soon.
3. Folder logic panel displaying auto-classification conditions.
- Actions:
1. Upload requirement.
2. Verify, reject, mark expired.
3. Request resubmission.
- Validation:
1. File type and file size restrictions.
2. Rejection requires reason.

## Screen S-09: Competency Matching Workspace
- Users: HR.
- Purpose: Decision support for candidate recommendation.
- Layout:
1. Left panel: request criteria and weight controls.
2. Center: ranked candidate table with score and recommendation level.
3. Right panel: criterion-by-criterion scoring explanation per selected candidate.
4. Footer: shortlist action bar.
- Actions:
1. Run evaluation.
2. Compare candidates.
3. Save shortlist.
- Validation:
1. Total weight must be greater than zero.
2. Mandatory criteria must be defined before evaluation.

## Screen S-10: Training Scheduler and Attendance
- Users: HR.
- Purpose: Pre-deployment training management.
- Panels:
1. Calendar and list view for training sessions.
2. Session detail with enrolled applicants.
3. Attendance sheet with completion toggles.
- Actions:
1. Create session.
2. Enroll applicants.
3. Mark attendance and completion.
- Validation:
1. Training date cannot be in the past for new schedule.

## Screen S-11: Deployment Wizard
- Users: HR.
- Purpose: Controlled conversion from approved applicant to active employee.
- Steps:
1. Candidate and request confirmation.
2. Assignment details and identifiers.
3. Final validation and confirmation.
- Required Fields:
1. Client company.
2. Department.
3. Position.
4. Supervisor.
5. Employee number.
6. Deployment date.
- Actions:
1. Confirm deployment.
- Validation:
1. Candidate must pass readiness gates.
2. Job request must have remaining headcount.

## Screen S-12: Active Employee 360 Profile
- Users: HR, Employee (limited view).
- Purpose: Unified employment lifecycle record.
- Regions:
1. Identity and current assignment banner.
2. Timeline including deployment, violations, and separation events.
3. Tabs: Assignment History, Violations, Requirements, Separation, Notes.
- Actions:
1. Reassign employee.
2. Log violation.
3. Initiate resignation or termination process.

## Screen S-13: Violation Case Console
- Users: HR.
- Purpose: Structured disciplinary management.
- Layout:
1. Case list with severity and status.
2. Case form with evidence upload.
3. Employee history side panel.
- Required Fields:
1. Date, type, description, issued by, status.
- Validation:
1. Required fields and evidence constraints by policy.

## Screen S-14: Resignation Workflow
- Users: Employee, HR.
- Purpose: Controlled voluntary separation process.
- Panels:
1. Resignation request form and uploaded letter.
2. Rendering tracker and clearance checklist.
3. Exit approval summary.
- Actions:
1. Submit request.
2. Accept and schedule exit.
3. Mark clearance complete.
- Validation:
1. Exit date cannot be earlier than filing date.

## Screen S-15: Termination Workflow
- Users: HR, Admin approver.
- Purpose: Controlled involuntary separation with documentation.
- Panels:
1. Termination details and reason.
2. Linked violation history preview.
3. Approval and finalization block.
- Actions:
1. Draft record.
2. Submit for approval.
3. Finalize termination.
- Validation:
1. Reason and termination date required.

## Screen S-16: Archives Search and Retrieval
- Users: Admin, HR with archive permission.
- Purpose: Searchable historical records after separation.
- Layout:
1. Filter panel by entity type, date, client, status.
2. Result list with snapshot metadata.
3. Read-only archive viewer.
- Actions:
1. View archive snapshot.
2. Export record packet.

## Screen S-17: Reports Center
- Users: Admin, HR.
- Purpose: Generate and export operational reports.
- Report Types:
1. Applicant.
2. Employee.
3. Deployment.
4. Violation.
5. Client company.
6. Job request.
7. Training.
8. Resignation.
9. Termination.
10. Annual summary.
- Actions:
1. Configure filters.
2. Preview report.
3. Export PDF or Excel.
4. Print.

## Screen S-18: Audit Logs
- Users: Admin.
- Purpose: Compliance and accountability review.
- Layout:
1. Search filters by actor, action, module, date.
2. Log table with before and after payload access.
3. Export panel.
- Actions:
1. Drill into activity details.
2. Export filtered audit logs.

## Screen S-19: User and Permission Administration
- Users: Admin.
- Purpose: Manage users, roles, and permission assignments.
- Panels:
1. User list with role badges and active state.
2. Role templates and permission matrix.
3. Assignment history.
- Actions:
1. Create user.
2. Activate or deactivate user.
3. Assign role and permissions.
- Validation:
1. Unique email required.
2. At least one role required for non-portal users.

## Screen S-20: Applicant and Employee Portal Home
- Users: Applicant, Employee.
- Purpose: Self-service status visibility and document submission.
- Applicant Features:
1. Application timeline.
2. Missing requirements list.
3. Upload requested documents.
4. Notification center.
- Employee Features:
1. Employment details.
2. Deployment history.
3. Disciplinary records.
4. Submit resignation request.

## Key Validation Rules by Domain
1. Lifecycle transition rules must enforce allowed next statuses.
2. Folder category must be read-only and system-computed.
3. Deployment cannot proceed if requirements or approvals are incomplete.
4. File uploads require type, size, and malware scan hooks.
5. Separation records require mandatory closure fields before archival.

## Responsive Behavior
1. Desktop: Multi-panel workspaces for matching and profile screens.
2. Tablet: Two-column adaptive with collapsible side panels.
3. Mobile: Single-column flow, sticky action footer, timeline condensed cards.

## Empty, Error, and Loading States
1. Empty states must show actionable next step buttons.
2. Validation errors must anchor to the first invalid section.
3. Long-running exports show queue status and completion notification.
4. Unauthorized access shows permission explanation and return action.

## UI Handoff Notes for React and Tailwind
1. Build each screen as route-level container with reusable panel components.
2. Centralize status color tokens and badge variants.
3. Use form schemas for consistent validation messages.
4. Apply optimistic UI only to low-risk updates; require confirmation for deployment and separation actions.
