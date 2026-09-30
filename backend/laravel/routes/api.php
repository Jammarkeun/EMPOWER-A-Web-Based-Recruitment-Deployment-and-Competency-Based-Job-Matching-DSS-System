<?php

use App\Http\Controllers\ApplicantController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientCompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DocumentLibraryController;
use App\Http\Controllers\DocumentScanController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\JobPositionController;
use App\Http\Controllers\JobRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RequirementController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SeparationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\UserController;
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
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

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
    | Rate limited tightly: registration is a write from an unauthenticated
    | caller, and a handful an hour from one address is far more than a genuine
    | applicant needs.
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
    Route::post('register/check-email', [RegistrationController::class, 'checkEmail'])
        ->middleware('throttle:20,1');
    Route::post('register', [RegistrationController::class, 'register'])
        ->middleware('throttle:5,60');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {

        // ------------------------------------------------------------- account
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::patch('auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // Which notifications this account wants. Open to every signed-in user,
        // staff and portal alike - the categories and the reasoning are the same.
        Route::get('auth/notification-preferences', [AuthController::class, 'notificationPreferences']);
        Route::patch('auth/notification-preferences', [AuthController::class, 'updateNotificationPreferences']);

        // ------------------------------------------------------------ settings
        // Reading is open to anyone with settings.view (HR included); every
        // write requires settings.update, which only an administrator holds.
        Route::get('settings', [SettingsController::class, 'index']);
        Route::put('settings', [SettingsController::class, 'update']);
        Route::post('settings/reset', [SettingsController::class, 'reset']);
        Route::post('settings/requirement-types', [SettingsController::class, 'storeRequirementType']);
        Route::patch('settings/requirement-types/{requirementType}', [SettingsController::class, 'updateRequirementType']);
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
            ->middleware('throttle:20,1');
        Route::get('document-scan/status/{scan}', [DocumentScanController::class, 'result']);

        // ---------------------------------------------------- document library
        // Uploaded documents grouped by kind - "show me the medical
        // certificates" - as opposed to the Folder 1/2/3 filing, which describes
        // how complete one applicant's paperwork is. Two different questions.
        Route::get('documents', [DocumentLibraryController::class, 'index']);
        Route::get('documents/{requirementType}', [DocumentLibraryController::class, 'show']);
        Route::get('documents/file/{requirement}', [DocumentLibraryController::class, 'download']);

        // ---------------------------------------------------------- applicants
        Route::apiResource('applicants', ApplicantController::class)
            ->only(['index', 'store', 'show', 'update']);
        Route::patch('applicants/{applicant}/status', [ApplicantController::class, 'changeStatus']);
        // Releases an online registrant into screening once an officer has
        // checked them against their documents at the counter.
        Route::post('applicants/{applicant}/verify-identity', [ApplicantController::class, 'verifyIdentity']);
        Route::get('applicants/{applicant}/timeline', [ApplicantController::class, 'timeline']);

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

        // ------------------------------------------------------------ training
        Route::apiResource('trainings', TrainingController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['trainings' => 'training']);
        Route::post('trainings/{training}/enrollments', [TrainingController::class, 'enrol']);
        Route::patch('trainings/{training}/enrollments/{enrollment}', [TrainingController::class, 'updateEnrolment']);

        // ---------------------------------------------------------- deployment
        Route::get('deployments', [DeploymentController::class, 'index']);
        Route::post('deployments', [DeploymentController::class, 'store']);
        Route::get('deployments/{deployment}', [DeploymentController::class, 'show']);
        Route::post('deployments/{deployment}/reassign', [DeploymentController::class, 'reassign']);

        // ----------------------------------------------------------- employees
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::get('employees/{employee}', [EmployeeController::class, 'show']);
        Route::patch('employees/{employee}', [EmployeeController::class, 'update']);

        // ---------------------------------------------------------- violations
        Route::get('employees/{employee}/violations', [EmployeeController::class, 'violations']);
        Route::post('employees/{employee}/violations', [EmployeeController::class, 'storeViolation']);
        Route::put('employees/{employee}/violations/{violation}', [EmployeeController::class, 'updateViolation']);
        Route::get('employees/{employee}/violations/{violation}/evidence', [EmployeeController::class, 'violationEvidence']);

        // ---------------------------------------------------------- separation
        Route::get('separations', [SeparationController::class, 'index']);
        Route::post('employees/{employee}/resignations', [SeparationController::class, 'storeResignation']);
        Route::put('employees/{employee}/resignations/{resignation}', [SeparationController::class, 'updateResignation']);
        Route::post('employees/{employee}/resignations/{resignation}/complete', [SeparationController::class, 'completeResignation']);
        Route::post('employees/{employee}/terminations', [SeparationController::class, 'storeTermination']);
        Route::post('employees/{employee}/terminations/{termination}/finalise', [SeparationController::class, 'finaliseTermination']);

        // ----------------------------------------------------- archive + audit
        Route::get('archives', [ArchiveController::class, 'index']);
        Route::get('archives/{archive}', [ArchiveController::class, 'show']);
        Route::get('audit-logs', [ArchiveController::class, 'auditLogs']);

        // ------------------------------------------------------- notifications
        // Always scoped to the signed-in user; there is deliberately no route
        // that reads somebody else's notifications.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

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
    Route::middleware(['auth:sanctum', 'active', 'portal'])->prefix('portal')->group(function () {
        Route::get('/', [PortalController::class, 'overview']);
        Route::get('documents', [PortalController::class, 'documents']);
        Route::get('documents/{requirementType}/download', [PortalController::class, 'downloadDocument']);

        // Sending a scan ahead of the office visit. Lands in "submitted" only —
        // verification stays with HR against the physical original, so this
        // cannot make anybody deployable.
        Route::post('documents/{requirementType}/upload', [PortalController::class, 'uploadDocument'])
            ->middleware('throttle:30,1');

        // Reads a document without storing it, so the applicant can find out at
        // home that a photo is too blurred to use. Throttled hard: each call is
        // roughly forty seconds of CPU on the recognition service, which makes an
        // unthrottled endpoint a cheap way to wedge it.
        Route::post('documents/check-readable', [PortalController::class, 'checkReadable'])
            ->middleware('throttle:10,60');
        Route::get('profile', [PortalController::class, 'profile']);
        Route::patch('profile', [PortalController::class, 'updateProfile']);

        // The applicant's own answer to an offer of work. Records what they
        // decided; it changes no status and cannot advance anybody - the client's
        // approval and the agency's decision are still separate acts.
        Route::post('placement-response', [PortalController::class, 'respondToPlacement']);

        Route::get('employment', [PortalController::class, 'employment']);
        Route::post('resignation', [PortalController::class, 'submitResignation']);
    });
});
