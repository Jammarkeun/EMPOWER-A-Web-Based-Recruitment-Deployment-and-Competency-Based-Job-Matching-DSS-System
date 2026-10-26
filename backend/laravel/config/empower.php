<?php

/*
|--------------------------------------------------------------------------
| EMPOWER Domain Configuration
|--------------------------------------------------------------------------
|
| The business rules of CDE Manpower Services that are expected to change
| without a code change: the lifecycle map, the folder thresholds, and the
| recommendation bands. Keeping them here means HR-facing policy is reviewable
| in one file rather than scattered across controllers.
|
*/

return [

    /*
    | The applicant lifecycle, expressed as an allow-list of transitions.
    |
    | A status may only move to a status listed against it. Anything else is
    | rejected with HTTP 409. This is what stops an applicant jumping from
    | "Applied" straight to "Deployed" and bypassing requirement checks, which
    | is precisely the failure mode the paper folder system allowed.
    */
    'applicant_transitions' => [
        'applied' => ['initial_screening', 'archived'],
        'initial_screening' => ['incomplete_requirements', 'primary_requirements_complete', 'archived'],
        'incomplete_requirements' => ['primary_requirements_complete', 'archived'],
        'primary_requirements_complete' => ['pending_final_requirements', 'incomplete_requirements', 'archived'],
        'pending_final_requirements' => ['ready_for_deployment', 'incomplete_requirements', 'archived'],
        'ready_for_deployment' => ['training_scheduled', 'client_evaluation', 'archived'],
        'training_scheduled' => ['training_completed', 'ready_for_deployment', 'archived'],
        'training_completed' => ['client_evaluation', 'archived'],
        'client_evaluation' => ['approved', 'ready_for_deployment', 'archived'],
        'approved' => ['deployed', 'client_evaluation', 'archived'],
        'deployed' => ['active'],
        'active' => ['resigned', 'terminated'],
        'resigned' => ['archived'],
        'terminated' => ['archived'],
        'archived' => [],
    ],

    /*
    | Statuses that only a workflow may set, never the generic status endpoint.
    |
    | Each of these stands for something that has to exist alongside it: an
    | employee record and a deployment for "deployed" and "active", a completed
    | resignation or a finalised termination for "resigned" and "terminated".
    | They stay in the transition map above, because that map is also the order
    | the workflow walks — but moving an applicant into one of them by hand would
    | record an employment that was never created, or end one with no
    | separation record, no review, and no confirmation. The value names the
    | workflow that owns the status so the refusal can say where to go instead.
    */
    'workflow_owned_statuses' => [
        'deployed' => 'deployment',
        'active' => 'deployment',
        'resigned' => 'separation',
        'terminated' => 'separation',
        // Set by enrolling the applicant in a session and recording their
        // completion (audit R-06). Typed by hand they claimed training that no
        // record showed had happened.
        'training_scheduled' => 'training',
        'training_completed' => 'training',
    ],

    /*
    | Employee status transitions, applied after an applicant becomes an employee.
    */
    'employee_transitions' => [
        'active' => ['resigned', 'terminated'],
        'resigned' => ['archived'],
        'terminated' => ['archived'],
        'archived' => [],
    ],

    /*
    | Statuses reached only through "ready for deployment", which itself needs
    | Folder 1 (see the transitions above).
    |
    | Derived from the map rather than a policy of its own: anybody in one of
    | these had every required document verified at some point. If their folder
    | is no longer Folder 1, a document has lapsed or been rejected since. That
    | is shown as a warning beside the status and is never acted on by itself -
    | whether the status should fall back is still an open question for CDE
    | (audit Q4). Deployment checks the folder live either way.
    */
    'past_document_gate_statuses' => [
        'ready_for_deployment',
        'training_scheduled',
        'training_completed',
        'client_evaluation',
        'approved',
    ],

    /*
    | Manpower request statuses (audit R-16).
    |
    | Enforced by JobRequestLifecycleService for every change, by hand or by
    | the system. "partially_fulfilled" and "fulfilled" describe the headcount,
    | so they are only accepted when the numbers agree; deployments and
    | headcount edits move between them automatically.
    |
    | Closing, cancelling and reopening each need a written reason. Moving out
    | of "closed" or "cancelled" is reopening: the request lands on "open", or
    | "partially_fulfilled" if workers are already deployed against it.
    |
    | Whether a CANCELLED request may be reopened at all has not been settled
    | with CDE (audit Q6). It stays possible here because the system has always
    | allowed it; removing 'open' from the cancelled row is the whole change if
    | CDE decides otherwise.
    */
    'job_request_transitions' => [
        'open' => ['in_progress', 'partially_fulfilled', 'fulfilled', 'closed', 'cancelled'],
        'in_progress' => ['open', 'partially_fulfilled', 'fulfilled', 'closed', 'cancelled'],
        'partially_fulfilled' => ['fulfilled', 'closed', 'cancelled'],
        'fulfilled' => ['partially_fulfilled', 'closed'],
        'closed' => ['open'],
        'cancelled' => ['open'],
    ],

    /*
    | Statuses that are terminal for recruitment purposes. An applicant in one of
    | these is excluded from the candidate pool during competency evaluation.
    */
    'inactive_applicant_statuses' => ['resigned', 'terminated', 'archived'],

    /*
    | Statuses eligible to be evaluated against a manpower request. Evaluating
    | someone whose documents are still incomplete wastes HR's time, so the pool
    | starts at "primary requirements complete".
    */
    'evaluable_applicant_statuses' => [
        'primary_requirements_complete',
        'pending_final_requirements',
        'ready_for_deployment',
        'training_scheduled',
        'training_completed',
        'client_evaluation',
        'approved',
    ],

    /*
    | Digital equivalent of the agency's three physical folders. Category is
    | derived from verified documents on every requirement change, never set by
    | hand, which removes the manual re-filing that made the paper system
    | error-prone.
    */
    'folders' => [
        'folder_3' => [
            'label' => 'Folder 3 - Resume Only',
            'description' => 'Initial submission. Primary requirements still outstanding.',
        ],
        'folder_2' => [
            'label' => 'Folder 2 - Primary Requirements Complete',
            'description' => 'All required primary documents verified. Awaiting medical results.',
        ],
        'folder_1' => [
            'label' => 'Folder 1 - Ready for Deployment',
            'description' => 'Primary and final requirements verified. Deployable.',
        ],
    ],

    /*
    | Score bands that turn a percentage into a recommendation. Tunable by the
    | administrator; the engine itself has no opinion about where the cut-offs
    | should sit.
    */
    'recommendation_bands' => [
        'highly_recommended' => 85,
        'recommended' => 70,
        'reserve_pool' => 50,
    ],

    /*
    | Document upload policy. Applied in addition to the MIME sniffing that
    | Laravel's file validation performs, so an executable renamed to .pdf is
    | still rejected.
    */
    'uploads' => [
        /*
         | The disk applicant documents are stored on. Overridden to a fake in
         | the test suite so tests never reach the live bucket.
         */
        'disk' => env('DOCUMENT_DISK', 'supabase'),

        'max_size_kb' => 10240,
        'allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'max_width' => 7000,
        'max_height' => 7000,
        'storage_retries' => 3,
        'signed_url_ttl_minutes' => (int) env('SUPABASE_SIGNED_URL_TTL', 10),

        /*
         | Applicant profile pictures. Smaller than the document limit because
         | a face on screen never needs a 10 MB scan, and images only: a profile
         | picture is never a PDF. The link lives longer than a document link
         | because it sits in the header for a whole working session; the
         | screen asks for a fresh one if it expires anyway.
         */
        'profile_photo_max_kb' => 5120,
        'profile_photo_min_px' => 64,
        'profile_photo_url_ttl_minutes' => (int) env('PROFILE_PHOTO_URL_TTL', 60),
    ],

    'reports' => [
        'queue_threshold_rows' => 1000,
    ],

    /*
    | How far ahead the daily check looks for verified documents that are about
    | to lapse. Adjustable from the settings screen.
    */
    'expiry_warning_days' => 30,

    /*
    | Disciplinary policy.
    |
    | The agency confirmed that a worker's record "goes back to zero" after a
    | year and that the current threshold is the fourth offence. Both are held
    | here rather than written into the code because they are the client's
    | policy, not the system's, and a client that changes its handbook should
    | not need a developer.
    |
    | The threshold is a point at which the system *raises the question*. It
    | never terminates anybody: reaching it flags the employee for review, and
    | an administrator decides. That separation is deliberate — an automatic
    | dismissal is exactly the kind of irreversible decision a decision-support
    | system must not make on its own.
    */
    'violations' => [
        // Months an offence stays on the active record. After this it remains
        // in the history, and in reports, but stops counting towards the
        // threshold.
        'active_window_months' => 12,

        // Offences within that window at which the employee is flagged for an
        // administrator to review. Currently the fourth, per CDE.
        'termination_threshold' => 4,
    ],

    /*
    | Attendance: the daily time record compared with the schedule.
    |
    | CDE has not stated a grace period, so the default is none - a time-in
    | after the scheduled start is reported as late, to the minute. It is a
    | setting because a client's handbook commonly allows a few minutes, and
    | when this one says so the agency should be able to match it without a
    | developer. This only changes what is *reported* as late; it computes no
    | pay and records no violation on its own.
    */
    'attendance' => [
        'late_grace_minutes' => 0,
    ],

    /*
    | Training before deployment.
    |
    | The agency is explicit that training always happens before a worker is
    | placed, so the lifecycle enforces it. Kept as a setting rather than a
    | hard-coded rule so the agency can suspend it deliberately — for a
    | redeployment of somebody already trained, say — instead of a developer
    | having to lift the gate in an emergency. Default is on, which is the
    | client's stated process.
    */
    'require_training_before_deployment' => true,

    /*
    | Client approval before deployment (audit R-11, question Q3).
    |
    | Whether CDE requires the client's approval to be recorded in EMPOWER
    | before a worker is deployed has not been confirmed. Off keeps the
    | behaviour the system has always had - a deployment can be recorded from
    | "ready for deployment" - but the history now says so plainly instead of
    | inventing an approval. On means a deployment needs the applicant to have
    | been moved to "approved" by an officer first. Change it once CDE answers.
    */
    'require_client_approval_before_deployment' => false,

    /*
    | Record retention.
    |
    | Every value here is an operational policy the agency gave us, not a legal
    | constant, and nothing in the system deletes on its own: these drive when a
    | record is *offered* for archiving and when a document is shown as past its
    | retention. Archiving stays a deliberate act by a person, because a
    | scheduled job that quietly destroys employment records is not something
    | anybody should be able to switch on by accident.
    */
    'retention' => [
        // Unhired applicants. CDE estimated three months "depending on the
        // situation", so this is a prompt to review rather than a deadline.
        'unhired_applicant_months' => 3,

        // Legal and employment documents, which CDE keeps for five to ten
        // years. The lower bound is the default because it is the point at
        // which a decision becomes due, not the point at which it is too late.
        'legal_document_years' => 5,
    ],

    /*
    | Password recovery (audit FN-03, decided 2026-10-19).
    |
    | Office reset with a forced change works without any of this. The email
    | reset link is a second route back in, and stays off unless it is switched
    | on here AND the mailer is a real one (MAIL_MAILER other than "log" or
    | "array"): a "sent" link that only reached the log file would put a live
    | reset token in the log.
    */
    'password_reset' => [
        'email_enabled' => (bool) env('PASSWORD_RESET_EMAIL', false),
    ],

    // Where the emailed reset link points: the address people open EMPOWER at.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
    | Request limits on the endpoints anyone can reach without signing in, per
    | network address. Each has its own counter (App\Providers\AppServiceProvider):
    | they used to share one, so opening a few documents or testing the sign-up
    | form locked everyone on the same address out of signing in for an hour.
    |
    | Everyone in one office usually shares an address, which is why these are
    | not tighter. Wrong passwords have their own, stricter limit per account in
    | AuthController (5 per 5 minutes), which these do not replace.
    */
    'rate_limits' => [
        'login_per_minute' => (int) env('RATE_LIMIT_LOGIN_PER_MINUTE', 30),

        // Only a sign-up that creates an account counts here, so correcting a
        // mistake in the form costs nothing...
        'registrations_per_hour' => (int) env('RATE_LIMIT_REGISTRATIONS_PER_HOUR', 10),
        // ...while every submission counts here, so the form cannot be used to
        // test thousands of email addresses (security audit V-03).
        'registration_attempts_per_hour' => (int) env('RATE_LIMIT_REGISTRATION_ATTEMPTS_PER_HOUR', 30),

        'reset_link_requests_per_hour' => (int) env('RATE_LIMIT_RESET_LINKS_PER_HOUR', 5),
        'password_resets_per_hour' => (int) env('RATE_LIMIT_PASSWORD_RESETS_PER_HOUR', 10),

        // Signed document and profile-photo links. Every avatar on a page is
        // one request, so this is generous; the signature is the real control.
        'document_files_per_minute' => (int) env('RATE_LIMIT_DOCUMENT_FILES_PER_MINUTE', 300),
    ],

    /*
    | Printed in the header of every exported report and shown to applicants in
    | the portal. Held here rather than hard-coded so the agency can correct its
    | own details without a code change.
    */
    'organisation' => [
        'name' => 'CDE Manpower Services',
        'address' => 'Sta. Cruz, Laguna',
        'contact' => null,
    ],

    /*
    | Code prefixes for the human-readable identifiers HR uses when speaking to
    | applicants and client companies over the phone.
    */
    'code_prefixes' => [
        'applicant' => 'APP',
        'employee' => 'EMP',
        'job_request' => 'JR',
        'deployment' => 'DEP',
        'training' => 'TRN',
        'client' => 'CLI',
        'position' => 'POS',
        'violation_report' => 'VR',
    ],
];
