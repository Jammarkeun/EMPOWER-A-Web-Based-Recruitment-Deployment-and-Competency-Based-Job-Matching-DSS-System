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
    | Employee status transitions, applied after an applicant becomes an employee.
    */
    'employee_transitions' => [
        'active' => ['resigned', 'terminated'],
        'resigned' => ['archived'],
        'terminated' => ['archived'],
        'archived' => [],
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
        'max_size_kb' => 10240,
        'allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'signed_url_ttl_minutes' => (int) env('SUPABASE_SIGNED_URL_TTL', 10),
    ],

    /*
    | How far ahead the daily check looks for verified documents that are about
    | to lapse. Adjustable from the settings screen.
    */
    'expiry_warning_days' => 30,

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
    ],
];
