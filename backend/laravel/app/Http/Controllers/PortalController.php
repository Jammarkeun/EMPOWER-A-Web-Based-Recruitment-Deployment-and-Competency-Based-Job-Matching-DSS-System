<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApplicantRequirementResource;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\Employee;
use App\Models\RequirementType;
use App\Models\Resignation;
use App\Rules\PhilippineMobileNumber;
use App\Services\AuditService;
use App\Services\DocumentStorageService;
use App\Services\DocumentUploadPolicy;
use App\Services\FolderCategoryService;
use App\Services\OcrService;
use App\Services\PlacementService;
use App\Services\ProfilePhotoService;
use App\Services\SeparationPresenter;
use App\Services\SeparationService;
use App\Services\TrainingProgressService;
use App\Support\ApplicantStatuses;
use App\Support\DocumentCompliance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Self-service portal for applicants and employees.
 *
 * Every method resolves the record from the authenticated user's own link and
 * never from a route parameter. That is the whole security model of this
 * controller: with no ID accepted from the client, there is no identifier to
 * tamper with, and one applicant cannot reach another's birth certificate by
 * changing a number in the URL.
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly FolderCategoryService $folders,
        private readonly DocumentStorageService $storage,
        private readonly SeparationService $separation,
        private readonly PlacementService $placements,
        private readonly AuditService $audit,
        private readonly TrainingProgressService $progress,
        private readonly SeparationPresenter $separations,
    ) {
    }

    /**
     * The portal landing view: where the person stands right now.
     */
    public function overview(Request $request): JsonResponse
    {
        $applicant = $this->applicant($request);
        $employee = $this->employee($request);

        $applicant->load(['requirements.requirementType', 'statusHistory', 'preferredPosition.company']);
        $folder = $this->folders->explain($applicant);
        $endorsement = $this->placements->openEndorsement($applicant)?->load('jobRequest.company');
        $currentDecision = $this->placements->currentDecision($endorsement);

        return ApiResponse::success([
            'person' => [
                'full_name' => $applicant->full_name,
                'first_name' => $applicant->first_name,
                'applicant_code' => $applicant->applicant_code,
                'contact_number' => $applicant->contact_number,
                'email' => $applicant->email,
                'present_address' => $applicant->present_address,
                // Initials for the header avatar. Built on the server from the
                // name already being sent, so the client never has to guess how
                // to abbreviate a name it did not parse.
                'initials' => $this->initials($applicant->first_name, $applicant->last_name),
            ],
            'application' => [
                'status' => $applicant->current_status,
                'position' => $applicant->preferred_position,
                'position_company' => $applicant->preferredPosition?->company?->company_name,
                'status_label' => ApplicantStatuses::label($applicant->current_status),
                'explanation' => $this->explainStatus($applicant->current_status),
                'applied_on' => $applicant->application_date?->toDateString(),
                // Null once the record is closed: a resigned, terminated or
                // archived record is not "stage 6 of 6" (audit UX-05).
                'stage_number' => ApplicantStatuses::stageNumber($applicant->current_status),
                'total_stages' => count(ApplicantStatuses::STAGES),
                // The path itself, from the same list the staff screen draws.
                // The portal used to keep its own copy, which still named the
                // fourth stage "Ready for placement" after training moved there.
                'stages' => array_map(
                    fn (array $stage) => ['key' => $stage['key'], 'label' => $stage['label']],
                    ApplicantStatuses::stages()
                ),
                'record_closed' => ApplicantStatuses::isClosed($applicant->current_status),

                // Someone who registered online is waiting on one thing only:
                // walking into the office. Saying so plainly here is what stops
                // them waiting weeks for a call that was never going to come.
                'awaiting_identity_check' => $applicant->awaiting_identity_check,
                'office' => $applicant->awaiting_identity_check ? [
                    'name' => config('empower.organisation.name'),
                    'address' => config('empower.organisation.address'),
                    'contact' => config('empower.organisation.contact'),
                ] : null,

                /*
                 * The one decision in the process that is the applicant's own.
                 *
                 * The agency's account of its own workflow is explicit that
                 * after training the candidate decides whether they still want
                 * the job. Asking here means the answer is on the record
                 * instead of in whoever took the phone call.
                 */
                // The answer to the current endorsement only. A new endorsement
                // asks again; an earlier answer to another placement is not
                // carried over (audit R-05, R-07).
                'placement_decision_due' => $this->placements->decisionDue($applicant),
                'placement_response' => $currentDecision?->decision,
                'placement_responded_at' => $currentDecision?->decided_at?->toIso8601String(),
                // Which placement, when the office said which request it is.
                'placement_position' => $endorsement?->jobRequest?->position_title,
                'placement_company' => $endorsement?->jobRequest?->company?->company_name,
            ],
            'documents' => [
                'folder_label' => $folder['label'],
                'is_complete' => $folder['is_deployment_ready'],
                'outstanding' => array_merge($folder['missing_primary'], $folder['missing_final']),

                // Kept separate as well as merged. The medical requirements are
                // only needed once a client accepts the applicant, and asking a
                // first-time visitor to arrive with all sixteen documents makes
                // the list look impossible and turns away people who qualify.
                'bring_now' => $folder['missing_primary'],
                'needed_later' => $folder['missing_final'],

                'verified_count' => $applicant->requirements->where('status', 'verified')->count(),
                'total_count' => $applicant->requirements->count(),
            ],
            /*
             * The placement itself, and only once it genuinely exists.
             *
             * The agency's process ends with the client's completed deployment
             * details being entered by admin — that act is what creates the
             * deployment record and moves the applicant to "deployed" and then
             * "active". Nothing earlier counts: being approved by the client,
             * being ready for deployment, having every document verified, all
             * of those still leave somebody who has not been placed, and
             * congratulating them would be telling them they have a job they
             * have not got.
             *
             * So this is derived from the deployment row rather than from a
             * status alone. Both have to agree: the lifecycle says deployed,
             * and there is an active placement to point at. That also makes it
             * survive a refresh, a new session, and a different device, because
             * it is a fact on the record rather than something the browser was
             * told once.
             */
            'deployment' => $this->activePlacement($applicant, $employee),

            // The Training step and the training reminder, both read from the
            // roster. Nothing here marks a notification read: seeing the
            // reminder is not the same as opening the notice.
            'training' => $this->progress->forApplicant($applicant),

            'employment' => $employee ? [
                'employee_number' => $employee->employee_number,
                'position' => $employee->current_position_title,
                'company' => $employee->currentCompany?->company_name,
                'department' => $employee->currentDepartment?->department_name,
                'supervisor' => $employee->current_supervisor_name,
                'hire_date' => $employee->hire_date?->toDateString(),
                'status' => $employee->employment_status,
            ] : null,

            // A pending resignation, or a termination notice, in one line with
            // a way to the employment page where the detail is.
            'separation' => $this->separations->portalSummary($employee, $request->user()),

            'timeline' => $applicant->statusHistory->take(8)->map(fn ($h) => [
                'status' => ucwords(str_replace('_', ' ', $h->to_status)),
                'reason' => match (true) {
                    $h->wasWrittenAutomaticallyAtDeployment() => \App\Models\ApplicationStatusHistory::AUTO_WRITTEN_NOTE,
                    // Termination entries used to carry the office's own reason,
                    // which is not the employee's notice. Earlier rows included.
                    $h->to_status === 'terminated' => 'Your employment record was closed following a formal decision. '
                        .'The notice is under My employment.',
                    default => $h->reason,
                },
                'occurred_at' => $h->changed_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * The applicant's own training: every session they are on and where each
     * stands. Resolved from the signed-in account like everything here, so
     * nobody else's roster entry can be reached from it.
     */
    public function training(Request $request): JsonResponse
    {
        return ApiResponse::success($this->progress->forApplicant($this->applicant($request)));
    }

    /**
     * The applicant's own document checklist.
     */
    public function documents(Request $request): JsonResponse
    {
        $applicant = $this->applicant($request);
        $applicant->load(['requirements.requirementType']);
        // A deployed worker sees "renewal due", not "expired", where it applies.
        $applicant->requirements->each->setRelation('applicant', $applicant);

        return ApiResponse::success([
            'requirements' => ApplicantRequirementResource::collection($applicant->requirements),
            'folder' => $this->folders->explain($applicant),
        ]);
    }

    /**
     * Lets an applicant send a scan of a document ahead of their office visit.
     *
     * This does not replace the visit, and nothing here can make an applicant
     * deployable. An upload lands in "submitted"; only an HR officer moves a
     * requirement to "verified", and folder categorisation counts nothing else.
     * What it removes is the wasted trip: a document that is missing, blurred, or
     * the wrong one can be caught while the applicant is still at home.
     *
     * The requirement type comes from the URL, but the applicant never does — it
     * is resolved from the signed-in user like everything else in this
     * controller, so there is no identifier for a curious user to change.
     */
    public function uploadDocument(Request $request, RequirementType $requirementType): JsonResponse
    {
        $applicant = $this->applicant($request);

        // The column is active_flag, not is_active — reading the wrong name here
        // returns null, and `! null` would have refused every upload.
        abort_if(
            ! $requirementType->active_flag,
            404,
            'That document is not currently being collected.'
        );

        $existing = $applicant->requirements()
            ->where('requirement_type_id', $requirementType->id)
            ->first();

        /*
         * A verified document is closed to the applicant.
         *
         * The office has already seen the original and accepted it. Letting a
         * replacement land here would silently reset it to "submitted" and drop
         * the applicant out of their folder — losing deployment readiness they
         * had already earned, by accident, with no one told. Corrections after
         * verification go through HR, who can see what changed and why.
         */
        /*
         * The one exception: a deployed worker renewing what CDE renews while
         * they work - the annual medical, the health card - once it is due.
         * Their folder no longer decides anything, and the version on file is
         * kept in the document's history rather than lost.
         */
        $renewal = $existing && $existing->status === 'verified'
            && in_array(DocumentCompliance::assess(
                $existing,
                $requirementType->post_deployment_rule,
                DocumentCompliance::stage($applicant->current_status),
            )['state'], ['renewal_due', 'renewal_overdue'], true);

        if ($existing && $existing->status === 'verified' && ! $renewal) {
            return ApiResponse::error(
                'This document has already been accepted by the office. '
                .'Contact HR if it needs replacing.',
                409
            );
        }

        $previousWasVerified = $existing && $existing->verified_at !== null
            && in_array($existing->status, ['verified', 'expired'], true);

        $data = $request->validate([
            'file' => DocumentUploadPolicy::rules(),
            'expiry_date' => [
                // A clearance with no expiry recorded would count as valid
                // forever, so the ones that lapse must say when.
                $requirementType->has_expiry ? 'required' : 'nullable',
                'date',
                'after:today',
            ],
        ], [
            'expiry_date.required' => 'This document expires, so please enter the date shown on it.',
            'expiry_date.after' => 'That date has already passed. Please submit a current document.',
        ]);

        $requirement = ApplicantRequirement::firstOrNew([
            'applicant_id' => $applicant->id,
            'requirement_type_id' => $requirementType->id,
        ]);

        $previousKey = $requirement->file_path;
        $stored = $this->storage->store($request->file('file'), 'requirements', $applicant->id);

        DB::transaction(function () use ($requirement, $stored, $data, $requirementType, $applicant) {
            $requirement->fill(array_merge($stored, [
                // Submitted, never verified. This is the line that keeps an
                // open upload from becoming a way to self-certify.
                'status' => 'submitted',
                'submitted_at' => now(),

                // A replacement is a different document. Whatever review the
                // previous file had is not review of this one, so the record of
                // it is cleared rather than inherited - otherwise a fresh upload
                // would announce itself as already being checked.
                'first_viewed_at' => null,
                'first_viewed_by' => null,

                'verified_at' => null,
                'verified_by' => null,
                'rejection_reason' => null,
                'expiry_date' => $data['expiry_date'] ?? null,
            ]))->save();

            $this->audit->record(
                action: 'create',
                module: 'requirements',
                recordType: ApplicantRequirement::class,
                recordId: $requirement->id,
                newValues: [
                    'requirement' => $requirementType->requirement_name,
                    'file_name' => $stored['file_name'],
                    'submitted_by' => 'applicant',
                    'applicant_code' => $applicant->applicant_code,
                ],
            );
        });

        // Removed only once the replacement is safely recorded, so a failure
        // part-way through never leaves the applicant with no document at all.
        // A version the office had verified is kept: it is in the document's
        // history now, and is what the person was deployed on.
        if ($previousKey && $previousKey !== $requirement->file_path && ! $previousWasVerified) {
            $this->storage->delete($previousKey);
        }

        $applicant->unsetRelation('requirements');
        $this->folders->recalculate($applicant);

        return ApiResponse::success(
            new ApplicantRequirementResource($requirement->fresh()->load('requirementType')->setRelation('applicant', $applicant)),
            'Sent. The office will check it against your original document when you visit.'
        );
    }

    /**
     * Tells the applicant whether a photo of a document is legible.
     *
     * Deliberately stores nothing and changes nothing. The single question it
     * answers is "will the office be able to read this?", which is worth asking
     * before travelling rather than after — a blurred phone photo of a birth
     * certificate is the sort of thing discovered at the counter otherwise.
     *
     * The proposed values are shown back so the applicant can see what was
     * actually legible. They are never written to the record: recognition is a
     * guess, and an applicant is not the person who verifies their own details.
     */
    public function checkReadable(Request $request, OcrService $ocr): JsonResponse
    {
        $this->applicant($request);

        $request->validate([
            // The reader takes PDFs and photos only; a Word file can still be
            // sent, it just cannot be checked first.
            'file' => DocumentUploadPolicy::rules(types: DocumentUploadPolicy::SCANNABLE),
        ]);

        if (! $ocr->isEnabled()) {
            return ApiResponse::success(
                ['checked' => false],
                'The reading service is not available right now. You can still send your document.'
            );
        }

        $result = $ocr->scan($request->file('file'));

        if (! $result['success']) {
            return ApiResponse::success([
                'checked' => true,
                'readable' => false,
                'found' => [],
            ], 'We could not read this clearly. Try a brighter photo with the whole document in frame.');
        }

        // Only the human-meaningful labels, not the column names the extractor
        // uses — "Date of birth", not "birth_date".
        $labels = [
            'first_name' => 'First name',
            'middle_name' => 'Middle name',
            'last_name' => 'Last name',
            'birth_date' => 'Date of birth',
            'sex' => 'Sex',
            'civil_status' => 'Civil status',
            'contact_number' => 'Contact number',
            'email' => 'Email address',
            'present_address' => 'Address',
        ];

        $found = collect($result['fields'] ?? [])
            ->filter(fn ($_, $name) => isset($labels[$name]))
            ->map(fn ($field, $name) => [
                'label' => $labels[$name],
                'value' => $field['value'],
            ])
            ->values();

        return ApiResponse::success([
            'checked' => true,
            'readable' => true,
            'found' => $found,
        ], 'This document is clear enough to read.');
    }

    /**
     * Opens one of the applicant's own documents through a short-lived link.
     */
    public function downloadDocument(Request $request, int $requirementTypeId): JsonResponse
    {
        $applicant = $this->applicant($request);

        // Scoped to this applicant, so a requirement ID belonging to somebody
        // else simply does not resolve.
        $requirement = $applicant->requirements()
            ->where('requirement_type_id', $requirementTypeId)
            ->firstOrFail();

        if (blank($requirement->file_path)) {
            return ApiResponse::error('No document has been uploaded for this requirement.', 404);
        }

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrl($requirement->file_path),
            'file_name' => $requirement->file_name,
            'expires_in_minutes' => config('empower.uploads.signed_url_ttl_minutes'),
        ]);
    }

    /**
     * The applicant's own record, as they are allowed to see it.
     *
     * Assembled from the authenticated account rather than from anything the
     * client sends, like everything else here. The read-only half is shown as
     * well as the editable half on purpose: an applicant who can see the date of
     * birth on file is an applicant who can spot that it is wrong and say so at
     * the counter, which is cheaper for everyone than discovering it during a
     * client's background check.
     */
    public function profile(Request $request): JsonResponse
    {
        $applicant = $this->applicant($request);
        $applicant->load('preferredPosition.company');
        $user = $request->user();

        return ApiResponse::success([
            'identity' => [
                'full_name' => $applicant->full_name,
                'first_name' => $applicant->first_name,
                'middle_name' => $applicant->middle_name,
                'last_name' => $applicant->last_name,
                'initials' => $this->initials($applicant->first_name, $applicant->last_name),
                'applicant_code' => $applicant->applicant_code,
                'birth_date' => $applicant->birth_date?->toDateString(),
                'age' => $applicant->age,
                'sex' => $applicant->sex,
                'civil_status' => $applicant->civil_status,
                'present_address' => $applicant->present_address,
                'applied_on' => $applicant->application_date?->toDateString(),
                'position' => $applicant->preferred_position,
                'position_company' => $applicant->preferredPosition?->company?->company_name,
            ],

            // Exactly the fields updateProfile() accepts, so the form is built
            // from the same list the API enforces and the two cannot drift.
            'editable' => [
                'contact_number' => $applicant->contact_number,
                'email' => $applicant->email,
                'availability_date' => $applicant->availability_date?->toDateString(),
            ],

            'account' => [
                'email' => $user->email,
                'user_type' => $user->user_type,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],

            /*
             * Why the rest of the record cannot be edited here, said plainly.
             *
             * Without this the read-only fields look like an oversight, and the
             * applicant's next move is a phone call asking how to change their
             * name.
             */
            'locked_fields_reason' => 'Your name, date of birth, and address were checked against '
                .'your documents at the office. Ask our staff if any of them need correcting.',
        ]);
    }

    /**
     * Records the applicant's answer to an offer of work.
     *
     * The agency's process gives the candidate a say: after training, having
     * seen the site and the shift, they decide whether they still want the job.
     * Accepting changes no status - the client's approval and the agency's
     * decision are separate matters and both still have to happen. Declining
     * does not archive them either. Both are recorded as facts for HR to act on,
     * because deciding what a withdrawal means for someone's place in the pool
     * is a judgment the agency makes, not something a form should do to them.
     */
    public function respondToPlacement(Request $request): JsonResponse
    {
        $applicant = $this->applicant($request);

        $data = $request->validate([
            'response' => ['required', Rule::in(['accepted', 'declined'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $this->placements->decisionDue($applicant)) {
            return ApiResponse::error(
                in_array($applicant->current_status, PlacementService::ENDORSED_STATUSES, true)
                    ? 'You have already answered for this placement. Please contact the office if you have changed your mind.'
                    : 'There is no placement waiting for your answer at the moment.',
                409
            );
        }

        // Recorded against this endorsement only, by the applicant, with the
        // office notified once it has saved.
        $decision = $this->placements->recordApplicantDecision(
            $applicant,
            $request->user(),
            $data['response'],
            $data['note'] ?? null
        );

        return ApiResponse::success([
            'placement_response' => $decision->decision,
            'placement_responded_at' => $decision->decided_at?->toIso8601String(),
        ], $data['response'] === 'accepted'
            ? 'Thank you. We have told the office you want to go ahead.'
            : 'Thank you for letting us know. Our staff will be in touch.');
    }

    /**
     * Limited profile maintenance.
     *
     * Contact details only. Name, date of birth, and address were verified
     * against documents at the office, and letting them be edited afterwards
     * would let a verified record drift away from the paperwork supporting it.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $applicant = $this->applicant($request);

        PhilippineMobileNumber::normalizeRequest($request, 'contact_number');

        $data = $request->validate([
            'contact_number' => ['nullable', 'string', 'max:40', new PhilippineMobileNumber()],
            'email' => ['nullable', 'email:rfc,filter', 'max:190'],
            'availability_date' => ['nullable', 'date'],
        ]);

        $before = $applicant->only(array_keys($data));
        $applicant->fill($data);

        // Availability is scored when matching, so a new date makes rankings
        // scored against the old one out of date (audit S-02). The office's own
        // edit screen already said so; this one did not.
        $scoredChanged = $applicant->isDirty('availability_date');

        DB::transaction(function () use ($applicant, $scoredChanged) {
            $applicant->save();

            if ($scoredChanged) {
                $applicant->markScoredProfileChanged();
            }
        });

        $this->audit->recordUpdate('portal', Applicant::class, $applicant->id, $before, $data);

        return ApiResponse::success(null, 'Your contact details have been updated');
    }

    /**
     * Sets or replaces the applicant's own profile picture.
     *
     * Like everything here, the applicant is the signed-in user's own record and
     * never one named in the request, so there is no identifier to change in
     * order to reach somebody else's picture. Success is only reported once the
     * file is stored and the record points at it.
     */
    public function uploadPhoto(Request $request, ProfilePhotoService $photos): JsonResponse
    {
        $applicant = $this->applicant($request);

        $request->validate(['photo' => $photos->rules()], $photos->messages('photo'));

        $photos->replace($applicant, $request->file('photo'));

        return ApiResponse::success(
            ['photo_url' => $photos->url($applicant->profile_photo_path)],
            'Your profile picture has been updated.'
        );
    }

    /**
     * Lets an employee send their own resignation request.
     *
     * The one separation action a person may start for themselves. It is a
     * request for HR to process, not the separation: employment ends when the
     * agency completes it after clearance, not when the form is sent, and the
     * employee has to say they understand that before it is accepted.
     *
     * Who is resigning, and from which placement, comes from the signed-in
     * account; the date it was made is today's, on the server. Neither is
     * something the person sending it picks.
     */
    public function submitResignation(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $eligibility = $this->separations->eligibility($employee);

        if (! $eligibility['can_request']) {
            // 403 for someone with no employment at all; 409 for an employee
            // whose record is not in a state to take a request.
            return ApiResponse::error($eligibility['reason'], $employee ? 409 : 403);
        }

        $data = $request->validate([
            'requested_exit_date' => [
                'required', 'date', 'after_or_equal:today',
                // A typo guard, not a policy: nobody gives a year's notice.
                'before_or_equal:'.today()->addYear()->toDateString(),
            ],
            'reason' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'letter' => DocumentUploadPolicy::rules(required: false),
            'acknowledged' => ['accepted'],
        ], [
            'requested_exit_date.required' => 'Choose the last day you intend to work.',
            'requested_exit_date.after_or_equal' => 'Your last working day cannot be in the past.',
            'requested_exit_date.before_or_equal' => 'Choose a date within the next twelve months.',
            'acknowledged.accepted' => 'Please confirm that you understand this request does not end your employment by itself.',
        ]);

        $letter = $request->hasFile('letter')
            ? $this->storage->store($request->file('letter'), 'resignations', $employee->id)['file_path']
            : null;

        try {
            $resignation = $this->separation->fileResignation($employee, [
                'reason' => $data['reason'],
                'filing_date' => today()->toDateString(),
                'requested_exit_date' => $data['requested_exit_date'],
                'employee_remarks' => $data['remarks'] ?? null,
                'resignation_letter_path' => $letter,
            ], $request->user(), 'portal');
        } catch (Throwable $e) {
            // A letter stored for a request that was then refused is removed,
            // not left in storage with nothing pointing at it.
            $this->storage->delete($letter);

            throw $e;
        }

        return ApiResponse::created(
            $this->separations->forPortal($employee->fresh(), $request->user()),
            'Your resignation request has been sent to HR. Keep reporting for work until HR confirms your last working day.'
        );
    }

    /**
     * Takes back the employee's own resignation request, while HR has not
     * accepted it yet.
     */
    public function withdrawResignation(Request $request): JsonResponse
    {
        $resignation = $this->openResignation($request);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->separation->withdrawResignationAsEmployee($resignation, $request->user(), $data['reason'] ?? null);

        return ApiResponse::success(
            $this->separations->forPortal($this->employee($request), $request->user()),
            'Your resignation request has been withdrawn. Your employment continues.'
        );
    }

    /**
     * Answers HR's request for more information about the resignation.
     */
    public function respondToResignation(Request $request): JsonResponse
    {
        $resignation = $this->openResignation($request);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ], [
            'message.required' => 'Write your reply to HR.',
        ]);

        $this->separation->respondToInformationRequest($resignation, $request->user(), $data['message']);

        return ApiResponse::success(
            $this->separations->forPortal($this->employee($request), $request->user()),
            'Your reply has been sent to HR.'
        );
    }

    /**
     * The letter the employee attached to their latest resignation request.
     */
    public function resignationLetter(Request $request): JsonResponse
    {
        $resignation = $this->employee($request)?->resignations()->latest('id')->first();

        if (blank($resignation?->resignation_letter_path)) {
            return ApiResponse::error('No resignation letter was attached.', 404);
        }

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrl($resignation->resignation_letter_path),
            'expires_in_minutes' => config('empower.uploads.signed_url_ttl_minutes'),
        ]);
    }

    /**
     * The employee confirms they received and read their termination notice.
     *
     * Receipt only: it is not agreement with the decision, and nothing waits
     * for it.
     */
    public function acknowledgeNotice(Request $request): JsonResponse
    {
        $termination = $this->employee($request)?->terminations()
            ->where('status', 'finalized')
            ->latest('finalized_at')
            ->latest('id')
            ->first();

        if (! $termination) {
            return ApiResponse::error('There is no notice to acknowledge.', 404);
        }

        $this->separation->acknowledgeNotice($termination, $request->user());

        return ApiResponse::success(
            $this->separations->forPortal($this->employee($request), $request->user()),
            'Thank you. We have recorded that you received this notice.'
        );
    }

    public function employment(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return ApiResponse::error('You do not have an employment record yet.', 404);
        }

        // Opening this page is opening the notice, if there is one: recorded
        // once, apart from the acknowledgment, which is the employee's own act.
        $notice = $employee->terminations()->where('status', 'finalized')->latest('finalized_at')->latest('id')->first();

        if ($notice) {
            $this->separation->recordNoticeViewed($notice, $request->user());
        }

        $employee->load(['deployments.company', 'deployments.department', 'violations']);

        return ApiResponse::success([
            'employee_number' => $employee->employee_number,
            'biometric_number' => $employee->biometric_number,
            'status' => $employee->employment_status,
            'hire_date' => $employee->hire_date?->toDateString(),
            'current' => [
                'company' => $employee->currentCompany?->company_name,
                'department' => $employee->currentDepartment?->department_name,
                'position' => $employee->current_position_title,
                'supervisor' => $employee->current_supervisor_name,
            ],
            'deployments' => $employee->deployments->map(fn ($d) => [
                'code' => $d->deployment_code,
                'company' => $d->company?->company_name,
                'department' => $d->department?->department_name,
                'position' => $d->position_title,
                'from' => $d->deployment_date?->toDateString(),
                'to' => $d->end_date?->toDateString(),
                'status' => $d->deployment_status,
            ]),
            // Shown to the employee deliberately. A disciplinary record they
            // cannot see is one they cannot answer, and the agency's own process
            // requires the employee to be informed of a violation anyway.
            'violations' => $employee->violations->map(fn ($v) => [
                'date' => $v->violation_date?->toDateString(),
                'type' => ucwords(str_replace('_', ' ', $v->violation_type)),
                'description' => $v->description,
                'penalty' => $v->penalty,
                'status' => $v->status,
            ]),
            // The resignation request and its progress, whether one may be
            // sent now, and any termination notice - as the employee may see
            // them (SeparationPresenter).
            'separation' => $this->separations->forPortal($employee, $request->user()),
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private function applicant(Request $request): Applicant
    {
        $applicant = $request->user()->applicant;

        abort_if(
            ! $applicant,
            403,
            'This account is not linked to an applicant record. Please contact the HR office.'
        );

        return $applicant;
    }

    /**
     * The employment record behind this account, however it is reachable.
     *
     * A portal account created during registration is linked by `applicant_id`
     * and nothing else. Deployment creates an employee against that applicant
     * but does not rewrite the account, so reading `user->employee` alone finds
     * nothing for exactly the people who have just been placed — the account
     * says "applicant" while the person is an employee.
     *
     * The applicant's own `employee` relation is the authoritative link, since
     * it is written by the deployment itself. Falling back to it is what makes
     * a newly deployed applicant see their placement without an administrator
     * having to re-provision their login first.
     */
    private function employee(Request $request): ?Employee
    {
        return $request->user()->portalEmployee()?->load(['currentCompany', 'currentDepartment']);
    }

    /**
     * The employee's open resignation request, for the actions taken on it.
     * Resolved from the signed-in account; the request has no id to send.
     */
    private function openResignation(Request $request): Resignation
    {
        $resignation = $this->employee($request)?->resignations()
            ->whereIn('status', Resignation::OPEN_STATUSES)
            ->latest('id')
            ->first();

        abort_unless($resignation, 409, 'You have no resignation request in progress.');

        return $resignation;
    }

    /**
     * The applicant's current placement, if they actually have one.
     *
     * Returns null unless the lifecycle says deployed **and** there is an
     * active deployment row behind it. Requiring both is deliberate: a status
     * moved by hand without a placement, or a placement that has since ended,
     * should not read as "you have been deployed".
     *
     * Every value comes from the deployment the client company filled in. None
     * of it is composed here, so there is nothing to be wrong about.
     */
    private function activePlacement(Applicant $applicant, ?Employee $employee): ?array
    {
        if (! in_array($applicant->current_status, ['deployed', 'active'], true)) {
            return null;
        }

        $deployment = $employee?->activeDeployment()?->load(['company', 'department']);

        if (! $deployment) {
            return null;
        }

        return [
            'deployment_code' => $deployment->deployment_code,
            'company' => $deployment->company?->company_name,
            'department' => $deployment->department?->department_name,
            'position' => $deployment->position_title,
            'supervisor' => $deployment->supervisor_name,
            'deployment_date' => $deployment->deployment_date?->toDateString(),
            'employee_number' => $employee->employee_number,
            'biometric_number' => $employee->biometric_number,
        ];
    }

    private function initials(?string $first, ?string $last): string
    {
        $initials = mb_substr(trim((string) $first), 0, 1).mb_substr(trim((string) $last), 0, 1);

        // Falls back to a placeholder rather than an empty circle, which reads
        // as a broken image rather than as a person with an unusual name.
        return mb_strtoupper($initials) ?: '?';
    }

    /**
     * Plain-language explanation of where the applicant stands.
     *
     * Written for the applicant, not for HR: the internal status name means
     * nothing to someone waiting to hear whether they got the job.
     */
    private function explainStatus(string $status): string
    {
        return match ($status) {
            'applied' => 'Your application has been received. Please visit the office to submit your documents.',
            'initial_screening' => 'Your application is being reviewed and your documents are being checked.',
            'incomplete_requirements' => 'Some of your documents are still missing. Please check the list below.',
            'primary_requirements_complete' => 'Your primary documents are complete. Medical requirements come next.',
            'pending_final_requirements' => 'Please complete your medical examination and submit the results at the office.',
            'ready_for_deployment' => 'All your requirements are verified. You will be contacted once a placement is available.',
            'training_scheduled' => 'You have been scheduled for training. Your session details are shown below.',
            'training_completed' => 'You have completed training and are awaiting endorsement to a client company.',
            'client_evaluation' => 'Your profile has been endorsed to a client company for their evaluation.',
            'approved' => 'The client company has approved your application. Deployment details will follow.',
            'deployed', 'active' => 'You are currently deployed. Your employment details are shown below.',
            'resigned' => 'Your employment record is closed following your resignation.',
            'terminated' => 'Your employment record is closed. Please contact the HR office with any questions.',
            'archived' => 'This record has been archived. Please contact the HR office if you wish to reapply.',
            default => 'Please contact the HR office for an update on your application.',
        };
    }

}
