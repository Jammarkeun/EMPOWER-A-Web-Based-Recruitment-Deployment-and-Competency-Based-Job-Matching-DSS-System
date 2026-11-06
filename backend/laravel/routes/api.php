<?php

use App\Http\Controllers\ApplicantController;
use App\Http\Controllers\ApplicantQualificationController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceExceptionController;
use App\Http\Controllers\AttendanceImportController;
use App\Http\Controllers\AttendanceReviewController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BioDataController;
use App\Http\Controllers\ClientCompanyController;
use App\Http\Controllers\ClientCompanyDocumentController;
use App\Http\Controllers\CoordinatorController;
use App\Http\Controllers\CoordinatorIncidentController;
use App\Http\Controllers\CoordinatorReportController;
use App\Http\Controllers\CoordinatorRequisitionController;
use App\Http\Controllers\CoordinatorScheduleController;
use App\Http\Controllers\CoordinatorDtrSubmissionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\DocumentLibraryController;
use App\Http\Controllers\DtrPeriodController;
use App\Http\Controllers\DtrSubmissionController;
use App\Http\Controllers\DocumentScanController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\IncidentReportController;
use App\Http\Controllers\JobPositionController;
use App\Http\Controllers\JobRequestController;
use App\Http\Controllers\ManpowerRequisitionController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PortalBioDataController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RequirementController;
use App\Http\Controllers\RetentionReviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ScheduleSubmissionController;
use App\Http\Controllers\SeparationController;
use App\Http\Controllers\SessionMonitorController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ViolationReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| EMPOWER API (v1)
|--------------------------------------------------------------------------
|
| Routes follow the sequence of the agency's actual workflow: a client raises a
| request, applicants are registered and screened, documents are verified, the
| competency engine ranks candidates, HR deploys, and the employment record runs
| through to separation and archiving.
|
*/

Route::prefix('v1')->group(function () {

    // Login is throttled again inside the controller, keyed by email and IP
    // together, so this only caps raw request volume.
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    /*
     * Getting back into an account (audit FN-03, UX-09). Public, and answering
     * the same way whether or not an account exists. The email route is off
     * until PASSWORD_RESET_EMAIL is set and a real mailer is configured; until
     * then the screen sends people to the office, which issues a temporary
     * password that has to be replaced at the first sign-in.
     */
    Route::get('auth/password-recovery', [PasswordResetController::class, 'options']);
    Route::post('auth/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:reset-link');
    Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

    /*
    |------------------------------------------------------------------
    | Public applicant registration
    |------------------------------------------------------------------
    |
    | Lets someone create an account and see what documents to bring before
    | visiting the office. A record created here is held before screening until
    | an HR officer confirms the person's identity in person, so an open
    | endpoint cannot inject anyone into the recruitment pipeline.
    |
    | Rate limited (the `registration` limit in AppServiceProvider): ten new
    | accounts an hour from one address, and thirty submissions in all, so
    | correcting a mistake in the form costs nothing but the form still cannot
    | be hammered. Both are set in config/empower.php.
    */
    Route::get('register/requirements', [RegistrationController::class, 'requirements']);
    /*
     * The positions the application form offers.
     *
     * Public for the same reason the checklist beside it is: the people who need
     * it do not have accounts yet. It reveals nothing a job posting would not -
     * a title and the company hiring - and it is read-only.
     */
    Route::get('register/positions', [JobPositionController::class, 'open']);

    // There is deliberately no "is this email taken?" lookup (security audit
    // V-03). The sign-up form never used it, and twenty answers a minute about
    // any address - staff accounts included - made it a directory of who has an
    // account. A taken address is still reported when the form is submitted,
    // within the thirty-submissions-an-hour limit below.
    Route::post('register', [RegistrationController::class, 'register'])
        ->middleware('throttle:registration');

    /*
     * One document from the private local disk, by signed link only.
     *
     * No sign-in is needed because the link itself is the permission: it is
     * minted only inside a response the viewer was already authorised to
     * receive, and it expires within minutes. Altering any part of it breaks
     * the signature. On Supabase the bucket's own pre-signed links are used
     * instead and this route is never linked to.
     */
    Route::get('document-files/{path}', DocumentFileController::class)
        ->where('path', '.*')
        ->middleware(['signed:relative', 'throttle:document-files'])
        ->name('documents.file');

    Route::middleware(['auth:sanctum', 'active', 'password.current'])->group(function () {

        // ------------------------------------------------------------- account
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('auth/session', [AuthController::class, 'session']);
        Route::patch('auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // A staff account's own picture - administrator, HR, or coordinator.
        // Portal accounts set theirs under /portal, on their applicant record.
        Route::post('auth/profile/photo', [AuthController::class, 'uploadPhoto'])
            ->middleware('throttle:profile-photo');

        // Which notifications this account wants. Open to every signed-in user,
        // staff and portal alike - the categories and the reasoning are the same.
        Route::get('auth/notification-preferences', [AuthController::class, 'notificationPreferences']);
        Route::patch('auth/notification-preferences', [AuthController::class, 'updateNotificationPreferences']);

        // ------------------------------------------------------- notifications
        // Always scoped to the signed-in user; there is deliberately no route
        // that reads somebody else's notifications.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        // What a sidebar item counted, once its page has been opened.
        Route::post('notifications/read-destinations', [NotificationController::class, 'markDestinationsRead']);

        // Whether anything has changed, so open screens can refresh on their own.
        Route::get('live', [LiveController::class, 'version']);
    });

    /*
    |------------------------------------------------------------------
    | Internal (staff) API
    |------------------------------------------------------------------
    |
    | Everything below takes record ids and returns the agency's full view of
    | a record, so applicant and employee accounts are refused outright by the
    | `staff` middleware - even for their own record, which they read through
    | /portal instead. Each action still checks its own permission.
    */
    Route::middleware(['auth:sanctum', 'active', 'password.current', 'staff'])->group(function () {

        // ------------------------------------------------------------ settings
        // Reading is open to anyone with settings.view (HR included); every
        // write requires settings.update, which only an administrator holds.
        Route::get('settings', [SettingsController::class, 'index']);
        Route::put('settings', [SettingsController::class, 'update']);
        Route::post('settings/reset', [SettingsController::class, 'reset']);
        Route::post('settings/requirement-types', [SettingsController::class, 'storeRequirementType']);
        Route::patch('settings/requirement-types/{requirementType}', [SettingsController::class, 'updateRequirementType']);
        Route::post('settings/folder-recalculations', [SettingsController::class, 'recalculateFolders']);
        Route::post('settings/criteria', [SettingsController::class, 'storeCriterion']);
        Route::patch('settings/criteria/{criterion}', [SettingsController::class, 'updateCriterion']);

        // ----------------------------------------------------------- dashboard
        Route::get('dashboard/summary', [DashboardController::class, 'summary']);

        // -------------------------------------------------------- global search
        // One box across applicants, employees, requests, and clients. Results
        // are filtered by what the user's role already permits.
        Route::get('search', SearchController::class);

        // ------------------------------------------------------------- clients
        Route::apiResource('clients', ClientCompanyController::class)
            ->only(['index', 'store', 'show', 'update']);
        // The company workspace: pick a client, then work inside their records.
        Route::get('clients/{client}/overview', [ClientCompanyController::class, 'overview']);
        // "Adjust Criteria" - the requirements this client asks for as standard.
        Route::get('clients/{client}/criteria', [ClientCompanyController::class, 'criteria']);
        Route::put('clients/{client}/criteria', [ClientCompanyController::class, 'setCriteria']);
        // The roles this client hires for. Adding one here is what puts it in
        // front of applicants, with no change to the front end.
        Route::get('positions', [JobPositionController::class, 'index']);
        Route::get('clients/{client}/positions', [JobPositionController::class, 'forCompany']);
        Route::post('clients/{client}/positions', [JobPositionController::class, 'store']);
        Route::put('clients/{client}/positions/{position}', [JobPositionController::class, 'update']);

        Route::get('clients/{client}/departments', [ClientCompanyController::class, 'departments']);
        Route::post('clients/{client}/departments', [ClientCompanyController::class, 'storeDepartment']);
        Route::put('clients/{client}/departments/{department}', [ClientCompanyController::class, 'updateDepartment']);
        /*
         * Who is placed in one department.
         *
         * Nested under the client rather than exposed as /departments/{id}: the
         * company is part of the address, so a department belonging to another
         * client does not resolve at all instead of resolving and then being
         * refused. Company scoping is a property of the route, not a condition
         * somebody has to remember to write.
         */
        Route::get(
            'clients/{client}/departments/{department}/employees',
            [ClientCompanyController::class, 'departmentEmployees']
        );

        /*
         * The company's own documents - its BIR registration, permits,
         * licences - each titled by whoever uploads it. Nested under the
         * company like departments, so another company's document does not
         * resolve. A replacement file is a POST so it can travel as multipart.
         */
        Route::get('clients/{client}/documents', [ClientCompanyDocumentController::class, 'index']);
        Route::post('clients/{client}/documents', [ClientCompanyDocumentController::class, 'store'])
            ->middleware('throttle:company-document-upload');
        Route::patch('clients/{client}/documents/{document}', [ClientCompanyDocumentController::class, 'update']);
        Route::post('clients/{client}/documents/{document}/file', [ClientCompanyDocumentController::class, 'replace'])
            ->middleware('throttle:company-document-upload');
        Route::delete('clients/{client}/documents/{document}', [ClientCompanyDocumentController::class, 'destroy']);
        Route::get('clients/{client}/documents/{document}/link', [ClientCompanyDocumentController::class, 'link']);
        Route::get('clients/{client}/documents/{document}/download', [ClientCompanyDocumentController::class, 'download']);

        // -------------------------------------------------------- job requests
        Route::apiResource('job-requests', JobRequestController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['job-requests' => 'jobRequest']);
        Route::patch('job-requests/{jobRequest}/status', [JobRequestController::class, 'changeStatus']);
        Route::get('job-requests/{jobRequest}/criteria', [JobRequestController::class, 'criteria']);
        Route::post('job-requests/{jobRequest}/criteria', [JobRequestController::class, 'setCriteria']);

        // ------------------------------------------------------ competency DSS
        // Evaluation is advisory: it ranks and explains, but never deploys.
        Route::post('job-requests/{jobRequest}/evaluate', [JobRequestController::class, 'evaluate']);
        Route::get('job-requests/{jobRequest}/rankings', [JobRequestController::class, 'rankings']);
        Route::post('job-requests/{jobRequest}/shortlist', [JobRequestController::class, 'shortlist']);

        // -------------------------------------------------- document reading
        // Returns proposed applicant details for HR to review. Deliberately
        // creates nothing: recognition is never certain, so a person confirms
        // the values through the normal applicant endpoints.
        Route::get('document-scan/status', [DocumentScanController::class, 'status']);
        Route::post('document-scan', [DocumentScanController::class, 'scan'])
            ->middleware('throttle:document-scan');
        Route::get('document-scan/status/{scan}', [DocumentScanController::class, 'result']);

        // ---------------------------------------------------- document library
        // By applicant first: a name opens every document that person has on
        // file (read through applicants/{applicant}/requirements). By kind of
        // document second - "show me the medical certificates" - as opposed to
        // the Folder 1/2/3 filing, which describes how complete one applicant's
        // paperwork is. Registered before documents/{requirementType} so the
        // literal segment is not read as a requirement type id.
        Route::get('documents/applicants', [DocumentLibraryController::class, 'applicants']);
        Route::get('documents', [DocumentLibraryController::class, 'index']);
        Route::get('documents/{requirementType}', [DocumentLibraryController::class, 'show']);
        Route::get('documents/file/{requirement}', [DocumentLibraryController::class, 'download']);

        // ---------------------------------------------------------- applicants
        // The status vocabulary in lifecycle order, for filters and pickers.
        Route::get('applicant-statuses', [ApplicantController::class, 'statuses']);
        Route::apiResource('applicants', ApplicantController::class)
            ->only(['index', 'store', 'show', 'update']);
        Route::patch('applicants/{applicant}/status', [ApplicantController::class, 'changeStatus']);
        // Releases an online registrant into screening once an officer has
        // checked them against their documents at the counter.
        Route::post('applicants/{applicant}/verify-identity', [ApplicantController::class, 'verifyIdentity']);
        Route::get('applicants/{applicant}/timeline', [ApplicantController::class, 'timeline']);
        // The applicant's answer to their current placement, recorded by HR or
        // corrected with a reason. Earlier answers are kept.
        Route::post('applicants/{applicant}/placement-decisions', [ApplicantController::class, 'placementDecision']);
        // Education, work history, skills, and certificates - what the
        // matching engine compares with a request's criteria. Staff only.
        Route::put('applicants/{applicant}/qualifications', [ApplicantQualificationController::class, 'update']);
        Route::get('qualification-vocabulary', [ApplicantQualificationController::class, 'vocabulary']);

        // -------------------------------------------------------- requirements
        Route::get('requirement-types', [RequirementController::class, 'types']);
        Route::get('applicants/{applicant}/requirements', [RequirementController::class, 'index']);
        /*
         * There is deliberately no staff upload route.
         *
         * Applicants upload their own documents through the portal, and for a
         * walk-in the officer verifies the original across the counter without
         * a file ever being stored. Staff uploading on an applicant's behalf
         * blurred who actually submitted what, and the audit trail could no
         * longer answer it.
         */
        Route::post(
            'applicants/{applicant}/requirements/verify-batch',
            [RequirementController::class, 'verifyBatch']
        );
        Route::patch(
            'applicants/{applicant}/requirements/{requirementType}',
            [RequirementController::class, 'updateStatus']
        );
        Route::get(
            'applicants/{applicant}/requirements/{requirementType}/download',
            [RequirementController::class, 'download']
        );

        // The digital Bio-Data behind the applicant's bio_data requirement:
        // reading it (which starts its review), deciding on it, and printing
        // a submitted version.
        Route::get('applicants/{applicant}/bio-data', [BioDataController::class, 'show']);
        Route::post('applicants/{applicant}/bio-data/review', [BioDataController::class, 'review']);
        Route::get('applicants/{applicant}/bio-data/pdf', [BioDataController::class, 'pdf'])
            ->middleware('throttle:30,1');

        // ------------------------------------------------------------ training
        // Before the resource routes, so "candidates" is not read as a session id.
        Route::get('trainings/candidates', [TrainingController::class, 'candidates']);
        Route::apiResource('trainings', TrainingController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['trainings' => 'training']);
        Route::post('trainings/{training}/enrollments', [TrainingController::class, 'enrol']);
        Route::patch('trainings/{training}/enrollments/{enrollment}', [TrainingController::class, 'updateEnrolment']);
        // Only while nothing has been recorded for the enrolment.
        Route::delete('trainings/{training}/enrollments/{enrollment}', [TrainingController::class, 'removeEnrolment']);

        // ---------------------------------------------------------- deployment
        Route::get('deployments', [DeploymentController::class, 'index']);
        Route::post('deployments', [DeploymentController::class, 'store']);
        Route::get('deployments/{deployment}', [DeploymentController::class, 'show']);
        Route::post('deployments/{deployment}/reassign', [DeploymentController::class, 'reassign']);

        // ----------------------------------------------------------- employees
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::get('employees/{employee}', [EmployeeController::class, 'show']);
        Route::patch('employees/{employee}', [EmployeeController::class, 'update']);

        // ---------------------------------------------------------- attendance
        // The one attendance workflow: schedules, the DTR (typed in or
        // imported from the client's file), the comparison, and HR's review
        // of what it flags. Nothing here computes pay or records a violation.
        Route::get('attendance', [AttendanceController::class, 'summary']);
        Route::get('attendance/discrepancies', [AttendanceReviewController::class, 'index']);
        Route::get('attendance/imports', [AttendanceImportController::class, 'index']);
        Route::get('attendance/imports/options', [AttendanceImportController::class, 'uploadOptions']);
        Route::post('attendance/imports', [AttendanceImportController::class, 'store']);
        Route::get('attendance/imports/{import}', [AttendanceImportController::class, 'show']);
        Route::post('attendance/imports/{import}/preview', [AttendanceImportController::class, 'preview']);
        Route::post('attendance/imports/{import}/confirm', [AttendanceImportController::class, 'confirm']);
        Route::post('attendance/imports/{import}/discard', [AttendanceImportController::class, 'discard']);
        Route::get('attendance/imports/{import}/file', [AttendanceImportController::class, 'file']);
        // DTR files coordinators hand over. "import" opens the stored file in
        // the importer above as an ordinary draft; nothing here writes attendance.
        Route::get('attendance/dtr-submissions', [DtrSubmissionController::class, 'index']);
        Route::get('attendance/dtr-submissions/{submission}', [DtrSubmissionController::class, 'show']);
        Route::get('attendance/dtr-submissions/{submission}/file', [DtrSubmissionController::class, 'file']);
        Route::post('attendance/dtr-submissions/{submission}/import', [DtrSubmissionController::class, 'import']);
        Route::post('attendance/dtr-submissions/{submission}/decision', [DtrSubmissionController::class, 'decide']);
        Route::get('attendance/dtr-periods', [DtrPeriodController::class, 'index']);
        Route::get('attendance/dtr-periods/{period}', [DtrPeriodController::class, 'show']);
        Route::post('attendance/dtr-periods/{period}/status', [DtrPeriodController::class, 'decide']);
        Route::get('attendance/exceptions', [AttendanceExceptionController::class, 'index']);
        Route::post('attendance/exceptions', [AttendanceExceptionController::class, 'store']);
        Route::post('attendance/exceptions/{exception}/void', [AttendanceExceptionController::class, 'void']);
        Route::get('employees/{employee}/attendance', [AttendanceController::class, 'show']);
        Route::post('employees/{employee}/attendance', [AttendanceController::class, 'storeRecords']);
        Route::get('employees/{employee}/attendance/days/{date}', [AttendanceController::class, 'day']);
        Route::post('employees/{employee}/attendance/days/{date}/review', [AttendanceReviewController::class, 'store']);
        Route::post('employees/{employee}/schedules', [AttendanceController::class, 'storeSchedule']);
        Route::patch('employees/{employee}/schedules/{schedule}', [AttendanceController::class, 'updateSchedule']);
        Route::post('employees/{employee}/schedules/{schedule}/void', [AttendanceController::class, 'voidSchedule']);
        // Schedules coordinators send in. Only an approved one is compared
        // with the DTR; approving puts it in force through the same path as
        // HR's own entry above.
        Route::get('attendance/schedule-submissions', [ScheduleSubmissionController::class, 'index']);
        Route::get('attendance/schedule-submissions/{submission}', [ScheduleSubmissionController::class, 'show']);
        Route::post('attendance/schedule-submissions/{submission}/review', [ScheduleSubmissionController::class, 'review']);

        // ---------------------------------------------------------- violations
        Route::get('employees/{employee}/violations', [EmployeeController::class, 'violations']);
        Route::post('employees/{employee}/violations', [EmployeeController::class, 'storeViolation']);
        Route::put('employees/{employee}/violations/{violation}', [EmployeeController::class, 'updateViolation']);
        Route::get('employees/{employee}/violations/{violation}/evidence', [EmployeeController::class, 'violationEvidence']);

        // --------------------------------------------- coordinator reports (HR)
        // Reports coordinators have sent in. Validating one records the
        // official violation; nothing here terminates anybody.
        Route::get('violation-reports', [ViolationReportController::class, 'index']);
        Route::get('violation-reports/{report}', [ViolationReportController::class, 'show']);
        Route::post('violation-reports/{report}/review', [ViolationReportController::class, 'review']);
        Route::get('violation-reports/{report}/evidence', [ViolationReportController::class, 'evidence']);

        // Incidents coordinators report from client sites, for the office to
        // read and answer with notes. Never about one worker's record.
        Route::get('incident-reports', [IncidentReportController::class, 'index']);
        Route::get('incident-reports/{report}', [IncidentReportController::class, 'show']);
        Route::post('incident-reports/{report}/notes', [IncidentReportController::class, 'addNote']);
        Route::get('incident-reports/{report}/evidence', [IncidentReportController::class, 'evidence']);

        // Coordinators' manpower requisitions. Approving one creates its
        // manpower request; `convert` does the same for one approved before that.
        Route::get('requisitions', [ManpowerRequisitionController::class, 'index']);
        Route::get('requisitions/{requisition}', [ManpowerRequisitionController::class, 'show']);
        Route::post('requisitions/{requisition}/review', [ManpowerRequisitionController::class, 'review']);
        Route::post('requisitions/{requisition}/convert', [ManpowerRequisitionController::class, 'convert']);
        Route::get('requisitions/{requisition}/attachment', [ManpowerRequisitionController::class, 'attachment']);

        // ---------------------------------------------------------- separation
        Route::get('separations', [SeparationController::class, 'index']);
        Route::post('employees/{employee}/resignations', [SeparationController::class, 'storeResignation']);
        Route::put('employees/{employee}/resignations/{resignation}', [SeparationController::class, 'updateResignation']);
        Route::post('employees/{employee}/resignations/{resignation}/complete', [SeparationController::class, 'completeResignation']);
        Route::get('employees/{employee}/resignations/{resignation}/letter', [SeparationController::class, 'resignationLetter']);
        // A termination is two stages: HR requests it and an administrator
        // decides the request; HR then completes the clearance and finalises
        // it. Approval ends nobody's employment. Nothing here deletes a case.
        Route::get('terminations/{termination}', [SeparationController::class, 'showTermination']);
        Route::post('employees/{employee}/terminations', [SeparationController::class, 'storeTermination']);
        Route::post('employees/{employee}/terminations/{termination}', [SeparationController::class, 'updateTermination']);
        Route::post('employees/{employee}/terminations/{termination}/submit', [SeparationController::class, 'submitTermination']);
        Route::post('employees/{employee}/terminations/{termination}/review', [SeparationController::class, 'reviewTermination']);
        Route::put('employees/{employee}/terminations/{termination}/clearance-items/{item}', [SeparationController::class, 'updateClearanceItem']);
        // Finalising requires an explicit confirmation in the request body;
        // withdrawing requires a reason.
        Route::post('employees/{employee}/terminations/{termination}/finalise', [SeparationController::class, 'finaliseTermination']);
        Route::post('employees/{employee}/terminations/{termination}/withdraw', [SeparationController::class, 'withdrawTermination']);
        // Clearance of a termination finalised under the earlier one-step process.
        Route::put('employees/{employee}/terminations/{termination}/clearance', [SeparationController::class, 'updateTerminationClearance']);
        Route::get('employees/{employee}/terminations/{termination}/document', [SeparationController::class, 'terminationDocument']);

        // ----------------------------------------------------- archive + audit
        // Records past the retention settings, for a person to review. Lists
        // and records decisions only; nothing here archives or deletes.
        Route::get('retention-reviews', [RetentionReviewController::class, 'index']);
        Route::post('retention-reviews', [RetentionReviewController::class, 'store']);

        Route::get('archives', [ArchiveController::class, 'index']);
        Route::get('archives/{archive}', [ArchiveController::class, 'show']);
        Route::get('audit-logs', [ArchiveController::class, 'auditLogs']);
        Route::get('audit-logs/facets', [ArchiveController::class, 'auditLogFacets']);

        // ------------------------------------------- sign-in monitoring (admin)
        // Every device signed in, and every sign-in attempt. Shows no token or
        // password; a session is addressed by its row id only.
        Route::get('security/sessions', [SessionMonitorController::class, 'sessions']);
        Route::get('security/sign-ins', [SessionMonitorController::class, 'signIns']);
        Route::delete('security/sessions/{token}', [SessionMonitorController::class, 'revoke']);

        // ------------------------------------------------------------- reports
        Route::get('reports', [ReportController::class, 'index']);
        Route::post('reports/preview', [ReportController::class, 'preview']);
        Route::post('reports/export', [ReportController::class, 'export']);
        Route::get('reports/exports/{export}', [ReportController::class, 'exportStatus']);
        Route::get('reports/exports/{export}/download', [ReportController::class, 'downloadQueuedExport']);

        // ----------------------------------------------- user administration
        // Administrator only, enforced by the manageUsers gate in each action.
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::get('users/roles', [UserController::class, 'roles']);
        Route::post('users/portal-access', [UserController::class, 'provisionPortalAccess']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::patch('users/{user}', [UserController::class, 'update']);
        Route::patch('users/{user}/status', [UserController::class, 'setStatus']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        // Which client a coordinator covers. Reassigning ends the current
        // assignment and starts a new one; the history is kept.
        Route::get('users/{user}/coordinator-assignments', [UserController::class, 'coordinatorAssignments']);
        Route::post('users/{user}/coordinator-assignment', [UserController::class, 'assignCoordinator']);
    });

    /*
    |------------------------------------------------------------------
    | Coordinator workspace
    |------------------------------------------------------------------
    |
    | A CDE coordinator assigned to one client company. The `coordinator`
    | middleware resolves that company from the active assignment on the
    | server, and every endpoint here scopes to it. No route accepts a client
    | company id, so there is nothing in the request for a coordinator to
    | change to reach another client.
    */
    Route::middleware(['auth:sanctum', 'active', 'password.current', 'coordinator'])->prefix('coordinator')->group(function () {
        Route::get('dashboard', [CoordinatorController::class, 'dashboard']);
        Route::get('company', [CoordinatorController::class, 'company']);
        Route::get('employees', [CoordinatorController::class, 'employees']);
        Route::get('employees/{employee}', [CoordinatorController::class, 'employee']);
        Route::get('deployments', [CoordinatorController::class, 'deployments']);
        Route::get('manpower-requests', [CoordinatorController::class, 'manpowerRequests']);

        Route::get('violation-reports/options', [CoordinatorReportController::class, 'options']);
        Route::get('violation-reports', [CoordinatorReportController::class, 'index']);
        Route::post('violation-reports', [CoordinatorReportController::class, 'store'])->middleware('throttle:violation-report');
        Route::get('violation-reports/{report}', [CoordinatorReportController::class, 'show']);
        // POST rather than PUT so an evidence file can travel with the edit.
        Route::post('violation-reports/{report}', [CoordinatorReportController::class, 'update']);
        Route::post('violation-reports/{report}/submit', [CoordinatorReportController::class, 'submit']);
        Route::delete('violation-reports/{report}', [CoordinatorReportController::class, 'destroy']);
        Route::get('violation-reports/{report}/evidence', [CoordinatorReportController::class, 'evidence']);

        Route::get('incident-reports/options', [CoordinatorIncidentController::class, 'options']);
        Route::get('incident-reports', [CoordinatorIncidentController::class, 'index']);
        Route::post('incident-reports', [CoordinatorIncidentController::class, 'store'])->middleware('throttle:violation-report');
        Route::get('incident-reports/{report}', [CoordinatorIncidentController::class, 'show']);
        Route::post('incident-reports/{report}', [CoordinatorIncidentController::class, 'update']);
        Route::post('incident-reports/{report}/submit', [CoordinatorIncidentController::class, 'submit']);
        Route::post('incident-reports/{report}/notes', [CoordinatorIncidentController::class, 'addNote'])->middleware('throttle:violation-report');
        Route::delete('incident-reports/{report}', [CoordinatorIncidentController::class, 'destroy']);
        Route::get('incident-reports/{report}/evidence', [CoordinatorIncidentController::class, 'evidence']);

        // Written on the provisional default form until CDE's own arrives.
        Route::get('requisitions/options', [CoordinatorRequisitionController::class, 'options']);
        Route::get('requisitions', [CoordinatorRequisitionController::class, 'index']);
        Route::post('requisitions', [CoordinatorRequisitionController::class, 'store'])->middleware('throttle:violation-report');
        Route::get('requisitions/{requisition}', [CoordinatorRequisitionController::class, 'show']);
        Route::post('requisitions/{requisition}', [CoordinatorRequisitionController::class, 'update']);
        Route::post('requisitions/{requisition}/submit', [CoordinatorRequisitionController::class, 'submit']);
        Route::delete('requisitions/{requisition}', [CoordinatorRequisitionController::class, 'destroy']);
        Route::get('requisitions/{requisition}/attachment', [CoordinatorRequisitionController::class, 'attachment']);

        // Work schedules for the client's workers, as the client gave them.
        // Sent to HR for approval; nothing here is in force until approved.
        Route::get('schedules', [CoordinatorScheduleController::class, 'index']);
        Route::post('employees/{employee}/schedules', [CoordinatorScheduleController::class, 'store'])->middleware('throttle:violation-report');
        Route::get('schedules/{submission}', [CoordinatorScheduleController::class, 'show']);
        Route::put('schedules/{submission}', [CoordinatorScheduleController::class, 'update']);
        Route::post('schedules/{submission}/submit', [CoordinatorScheduleController::class, 'submit']);
        Route::post('schedules/{submission}/withdraw', [CoordinatorScheduleController::class, 'withdraw']);

        // The client's DTR files, handed over to HR. HR imports them; the
        // coordinator never does.
        Route::get('dtr-submissions/options', [CoordinatorDtrSubmissionController::class, 'options']);
        Route::get('dtr-submissions', [CoordinatorDtrSubmissionController::class, 'index']);
        Route::post('dtr-submissions', [CoordinatorDtrSubmissionController::class, 'store'])->middleware('throttle:violation-report');
        Route::get('dtr-submissions/{submission}', [CoordinatorDtrSubmissionController::class, 'show']);
        Route::get('dtr-submissions/{submission}/file', [CoordinatorDtrSubmissionController::class, 'file']);
        Route::post('dtr-submissions/{submission}/resubmit', [CoordinatorDtrSubmissionController::class, 'resubmit'])->middleware('throttle:violation-report');
    });

    /*
    |------------------------------------------------------------------
    | Applicant and employee self-service portal
    |------------------------------------------------------------------
    |
    | Separated from the staff routes above and gated by the `portal`
    | middleware. No endpoint here accepts a record identifier: each resolves
    | the applicant or employee from the signed-in user's own link, so there is
    | nothing in the URL for a curious user to change.
    */
    Route::middleware(['auth:sanctum', 'active', 'password.current', 'portal'])->prefix('portal')->group(function () {
        Route::get('/', [PortalController::class, 'overview']);
        Route::get('documents', [PortalController::class, 'documents']);
        Route::get('documents/{requirementType}/download', [PortalController::class, 'downloadDocument']);

        // Sending a scan ahead of the office visit. Lands in "submitted" only —
        // verification stays with HR against the physical original, so this
        // cannot make anybody deployable.
        Route::post('documents/{requirementType}/upload', [PortalController::class, 'uploadDocument'])
            ->middleware('throttle:portal-upload');

        // Reads a document without storing it, so the applicant can find out at
        // home that a photo is too blurred to use. Throttled hard: each call is
        // roughly forty seconds of CPU on the recognition service, which makes an
        // unthrottled endpoint a cheap way to wedge it.
        Route::post('documents/check-readable', [PortalController::class, 'checkReadable'])
            ->middleware('throttle:readability-check');
        Route::get('profile', [PortalController::class, 'profile']);
        Route::patch('profile', [PortalController::class, 'updateProfile']);

        // The applicant's own digital Bio-Data. A draft is theirs alone; a
        // submission lands as "submitted" and only HR can verify it.
        Route::get('bio-data', [PortalBioDataController::class, 'show']);
        Route::put('bio-data/draft', [PortalBioDataController::class, 'saveDraft']);
        Route::post('bio-data/submit', [PortalBioDataController::class, 'submit']);
        Route::get('bio-data/pdf', [PortalBioDataController::class, 'pdf'])
            ->middleware('throttle:30,1');

        // The applicant's own picture. No identifier in the URL: it is always
        // the signed-in applicant's, like everything else under /portal.
        Route::post('profile/photo', [PortalController::class, 'uploadPhoto'])
            ->middleware('throttle:profile-photo');

        // The applicant's own answer to an offer of work. Records what they
        // decided; it changes no status and cannot advance anybody - the client's
        // approval and the agency's decision are still separate acts.
        Route::post('placement-response', [PortalController::class, 'respondToPlacement']);

        // The applicant's own training sessions. Attendance and completion are
        // recorded by HR on the staff Training screen; the applicant can only
        // tell the office they will attend, and only for their own enrolment.
        Route::get('training', [PortalController::class, 'training']);
        Route::post('training/{enrollment}/confirm', [PortalController::class, 'confirmTraining'])
            ->whereNumber('enrollment')
            ->middleware('throttle:30,1');

        Route::get('employment', [PortalController::class, 'employment']);

        // The employee's own resignation request: sending it, withdrawing it
        // before HR accepts it, answering HR's question about it, and opening
        // the letter attached to it. A request, never the separation itself -
        // only HR completes a resignation. No id in any of these: the request
        // is always the signed-in employee's own.
        Route::post('resignation', [PortalController::class, 'submitResignation'])
            ->middleware('throttle:portal-upload');
        Route::post('resignation/withdraw', [PortalController::class, 'withdrawResignation']);
        Route::post('resignation/respond', [PortalController::class, 'respondToResignation']);
        Route::get('resignation/letter', [PortalController::class, 'resignationLetter']);

        // Confirms receipt of a termination notice. Not agreement with it, and
        // nothing about the termination waits for it.
        Route::post('separation-notice/acknowledge', [PortalController::class, 'acknowledgeNotice']);
    });
});
