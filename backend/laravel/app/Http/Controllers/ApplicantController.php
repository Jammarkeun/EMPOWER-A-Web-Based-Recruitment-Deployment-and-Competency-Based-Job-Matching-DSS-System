<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApplicantRequest;
use App\Http\Resources\ApplicantResource;
use App\Http\Resources\StatusHistoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ApplicationStatusHistory;
use App\Models\JobPosition;
use App\Models\JobRequest;
use App\Models\PlacementDecision;
use App\Models\RequirementType;
use App\Models\TrainingEnrollment;
use App\Rules\PersonName;
use App\Rules\PhilippineMobileNumber;
use App\Services\ApplicantLifecycleService;
use App\Services\AuditService;
use App\Services\FolderCategoryService;
use App\Services\PlacementService;
use App\Services\ReferenceCodeService;
use App\Support\ApplicantStatuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApplicantController extends Controller
{
    public function __construct(
        private readonly ApplicantLifecycleService $lifecycle,
        private readonly FolderCategoryService $folders,
        private readonly ReferenceCodeService $codes,
        private readonly PlacementService $placements,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Applicant::class);

        // One status, or several separated by commas - the form a dashboard
        // figure uses to open the list of exactly the people it counted.
        if (is_string($request->query('current_status'))) {
            $request->merge(['current_status' => array_values(array_filter(
                array_map('trim', explode(',', $request->query('current_status')))
            ))]);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            // Each one a status of the vocabulary, reported under
            // `current_status` itself as it always was. "deployed" is read
            // from the employment record, not the column
            // (Applicant::scopeCurrentlyDeployed).
            'current_status' => ['bail', 'nullable', 'array', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                foreach ((array) $value as $status) {
                    if (! is_string($status) || ! ApplicantStatuses::isKnown($status)) {
                        $fail('The selected :attribute is invalid.');

                        return;
                    }
                }
            }],
            'folder_category' => ['nullable', Rule::in(['folder_1', 'folder_2', 'folder_3'])],
            'source_channel' => ['nullable', Rule::in(Applicant::SOURCE_CHANNELS)],
            'awaiting_identity_check' => ['nullable', 'boolean'],
            // Who applied for one position - the list a client's posting
            // opens onto from the company workspace.
            'preferred_position_id' => ['nullable', 'integer', 'exists:job_positions,id'],
            // Who applied for any of one client's positions.
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            // Status says every document was verified; the folder says not (WF-06).
            'documents_lapsed' => ['nullable', 'boolean'],
            // Leaves out anyone working under an active deployment today -
            // the dashboard's "ready for deployment" figure does.
            'exclude_deployed' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'sort_by' => ['nullable', Rule::in(['application_date', 'last_name', 'created_at', 'current_status'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $direction = $filters['sort_dir'] ?? 'desc';

        $applicants = Applicant::query()
            ->with('openEndorsement.currentDecision')
            ->withPlacement()
            ->search($filters['search'] ?? null)
            ->when($filters['current_status'] ?? null, fn ($q, $v) => $q->inStatuses($v))
            ->when($filters['folder_category'] ?? null, fn ($q, $v) => $q->where('folder_category', $v))
            ->when($filters['source_channel'] ?? null, fn ($q, $v) => $q->where('source_channel', $v))
            ->when($filters['awaiting_identity_check'] ?? false, fn ($q) => $q->awaitingIdentityCheck())
            ->when($filters['preferred_position_id'] ?? null, fn ($q, $v) => $q->where('preferred_position_id', $v))
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'preferredPosition',
                fn ($position) => $position->where('client_company_id', $v)
            ))
            ->when($filters['documents_lapsed'] ?? false, fn ($q) => $q
                ->whereIn('current_status', config('empower.past_document_gate_statuses'))
                ->where(fn ($folder) => $folder->whereNull('folder_category')->orWhere('folder_category', '!=', 'folder_1')))
            ->when($filters['exclude_deployed'] ?? false, fn ($q) => $q->notCurrentlyDeployed())
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('application_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('application_date', '<=', $v))
            ->orderBy($filters['sort_by'] ?? 'application_date', $direction)
            // Many applicants share an application date. Without a unique
            // last key MySQL and PostgreSQL may order the ties differently on
            // each page, repeating some people and skipping others.
            ->orderBy('applicants.id', $direction)
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated(
            ApplicantResource::collection($applicants),
            'Applicants retrieved'
        );
    }

    public function store(StoreApplicantRequest $request): JsonResponse
    {
        $applicant = DB::transaction(function () use ($request) {
            $applicant = Applicant::create(array_merge($this->withPositionTitle($request->validated()), [
                'applicant_code' => $this->codes->applicant(),
                'created_by' => $request->user()->id,
            ]));

            // current_status is guarded, so create() leaves it unset on the
            // in-memory model even though the column has a default. Setting it
            // explicitly makes the starting state deliberate and gives the
            // lifecycle service a value to transition away from.
            $applicant->forceFill(['current_status' => 'applied'])->save();

            // Every active requirement is created up front in "missing" state.
            // This is what turns the checklist into something HR can work
            // through, instead of having to remember what has not arrived yet.
            $this->createRequirementChecklist($applicant);

            $this->audit->record(
                action: 'create',
                module: 'applicants',
                recordType: Applicant::class,
                recordId: $applicant->id,
                newValues: $request->validated(),
            );

            // Inside the same transaction, so a walk-in is never left created
            // but stuck at "applied" if the move into screening fails.
            return $this->lifecycle->transition($applicant, 'initial_screening', $request->user(), 'Applicant registered at the office.');
        });

        return ApiResponse::created(
            new ApplicantResource($applicant->fresh()->load('requirements.requirementType')),
            'Applicant registered'
        );
    }

    /**
     * Keeps the position reference and the position wording in step.
     *
     * When a position is chosen, its title is written alongside the reference so
     * the two can never disagree. Every screen, report, and export already reads
     * the title, and a record whose id says "Warehouse Staff" while its text
     * says "Production Helper" is worse than either alone.
     */
    private function withPositionTitle(array $data): array
    {
        if (empty($data['preferred_position_id'])) {
            return $data;
        }

        $position = JobPosition::find($data['preferred_position_id']);

        if ($position) {
            $data['preferred_position'] = $position->position_title;
        }

        return $data;
    }

    public function show(Applicant $applicant): JsonResponse
    {
        $this->authorize('view', $applicant);

        $applicant->load([
            'educations',
            'experiences',
            'skills',
            'certifications',
            'requirements.requirementType',
            'requirements.verifier',
            'statusHistory.changedBy',
            'employee.currentCompany',
            'employee.currentDepartment',
            'openEndorsement.currentDecision',
        ]);

        return ApiResponse::success([
            'applicant' => new ApplicantResource($applicant),
            'folder' => $this->folders->explain($applicant),
            'placement' => $this->placements->present($applicant),
            'training' => $this->trainingSummary($applicant),
            'allowed_transitions' => $this->lifecycle->offeredNextStatuses($applicant->current_status),
            // The path the progress panel draws, in lifecycle order.
            'stages' => ApplicantStatuses::stages(),
        ]);
    }

    /**
     * The applicant-status vocabulary: every status in lifecycle order, its
     * label and stage, and the stages themselves.
     *
     * Published so the screens that list or group statuses - the applicant
     * filters, the report picker - read them from here instead of keeping their
     * own copy. It holds no personal data, but it describes the recruitment
     * pipeline, so it is for those who can see applicants: a coordinator, who
     * passes the staff middleware, has no use for it.
     */
    public function statuses(): JsonResponse
    {
        $this->authorize('viewAny', Applicant::class);

        return ApiResponse::success(ApplicantStatuses::describe());
    }

    /**
     * Where the applicant stands on training, as the endorsement gate sees it.
     *
     * `requirement_met` is the very check the lifecycle makes before an
     * endorsement, so the record and the gate cannot disagree. Each session is
     * listed with what was actually recorded: being enrolled is not attending,
     * and attending is not completing.
     */
    private function trainingSummary(Applicant $applicant): array
    {
        $enrolments = $applicant->trainingEnrollments()
            ->with(['training', 'attendanceRecorder', 'completionRecorder'])
            ->get()
            ->sortByDesc(fn (TrainingEnrollment $e) => $e->training?->training_date?->toDateString())
            ->values();

        return [
            // Training is the next step from "ready for deployment" onwards; a
            // record before that only shows sessions it actually has.
            'relevant' => $enrolments->isNotEmpty() || ApplicantStatuses::isPastDocumentGate($applicant->current_status),
            'required_before_endorsement' => (bool) config('empower.require_training_before_deployment', true),
            'requirement_met' => $this->lifecycle->hasCompletedTraining($applicant),
            'sessions' => $enrolments->map(fn (TrainingEnrollment $e) => [
                'training_id' => $e->training_id,
                'training_code' => $e->training?->training_code,
                'title' => $e->training?->training_title,
                'date' => $e->training?->training_date?->toDateString(),
                'session_status' => $e->training?->status,
                'attendance_status' => $e->attendance_status,
                'attendance_recorded_by' => $e->attendanceRecorder?->full_name,
                'completion_status' => $e->completion_status,
                'completion_recorded_by' => $e->completionRecorder?->full_name,
                'completion_recorded_at' => $e->completion_recorded_at?->toIso8601String(),
                'counts_towards_requirement' => $e->hasCompleted() && $e->training && ! $e->training->isCancelled(),
            ])->all(),
        ];
    }

    /**
     * The applicant's own details that the matching engine reads.
     */
    private const SCORED_FIELDS = [
        'sex', 'birth_date', 'height_cm', 'distance_km', 'availability_date',
        'communication_rating', 'reliability_rating',
    ];

    public function update(Request $request, Applicant $applicant): JsonResponse
    {
        $this->authorize('update', $applicant);

        PhilippineMobileNumber::normalizeRequest($request, 'contact_number');

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100', new PersonName()],
            'middle_name' => ['nullable', 'string', 'max:100', new PersonName()],
            'last_name' => ['sometimes', 'string', 'max:100', new PersonName()],
            'suffix' => ['nullable', 'string', 'max:20', new PersonName()],
            'sex' => ['nullable', Rule::in(['male', 'female'])],
            'birth_date' => ['nullable', 'date', 'before:'.now()->subYears(15)->toDateString()],
            'civil_status' => ['nullable', Rule::in(['single', 'married', 'widowed', 'separated', 'divorced'])],
            'nationality' => ['nullable', 'string', 'max:80'],
            'height_cm' => ['nullable', 'numeric', 'decimal:0,2', 'between:100,250'],
            'weight_kg' => ['nullable', 'numeric', 'decimal:0,2', 'between:30,300'],
            'contact_number' => ['nullable', 'string', 'max:40', new PhilippineMobileNumber()],
            'email' => ['nullable', 'email:rfc,filter', 'max:190'],
            'present_address' => ['sometimes', 'string', 'max:255'],
            'provincial_address' => ['nullable', 'string', 'max:255'],
            'preferred_position_id' => ['nullable', 'integer', 'exists:job_positions,id'],
            'preferred_position' => ['nullable', 'string', 'max:150'],
            'availability_date' => ['nullable', 'date'],
            'distance_km' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999'],
            'communication_rating' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,5'],
            'reliability_rating' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,5'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);

        $data = $this->withPositionTitle($data);
        $before = $applicant->only(array_keys($data));

        $applicant->fill(array_merge($data, ['updated_by' => $request->user()->id]));

        // A change to anything the matching engine scores makes earlier
        // rankings that include this applicant out of date (audit S-02).
        $scoredChanged = $applicant->isDirty(self::SCORED_FIELDS);

        DB::transaction(function () use ($applicant, $scoredChanged) {
            $applicant->save();

            if ($scoredChanged) {
                $applicant->markScoredProfileChanged();
            }
        });

        $this->audit->recordUpdate('applicants', Applicant::class, $applicant->id, $before, $data);

        return ApiResponse::success(new ApplicantResource($applicant->fresh()), 'Applicant updated');
    }

    /**
     * Move an applicant along the recruitment lifecycle.
     */
    public function changeStatus(Request $request, Applicant $applicant): JsonResponse
    {
        $this->authorize('changeStatus', $applicant);

        $data = $request->validate([
            'to_status' => ['required', 'string', Rule::in(array_keys(config('empower.applicant_transitions')))],
            'reason' => ['nullable', 'string', 'max:255'],

            /*
             * Why the application stopped, as a category rather than a sentence.
             *
             * CDE named two reasons applications do not proceed - requirements
             * never completed, and not suitable for the work. Recorded as a
             * chosen value so they can be counted, with the note carrying
             * whatever made this case particular. A free-text reason alone
             * could never answer "how many do we lose to incomplete
             * requirements", which is the question worth being able to ask.
             */
            'disposition_reason' => [
                'nullable',
                Rule::in(['incomplete_requirements', 'not_suitable', 'other']),
            ],
            'disposition_note' => ['nullable', 'string', 'max:255'],

            // Which request the applicant is being put forward for, when the
            // officer knows. Optional, and only meaningful for an endorsement:
            // the applicant's answer then belongs to that client's request.
            'job_request_id' => ['nullable', 'integer', 'exists:job_requests,id'],
        ]);

        if (! empty($data['job_request_id'])) {
            if ($data['to_status'] !== 'client_evaluation') {
                throw ValidationException::withMessages([
                    'job_request_id' => ['A request can only be named when endorsing the applicant to a client.'],
                ]);
            }

            if (! JobRequest::query()->open()->whereKey($data['job_request_id'])->exists()) {
                throw ValidationException::withMessages([
                    'job_request_id' => ['That request is not open, so nobody can be endorsed for it.'],
                ]);
            }
        }

        if ($data['to_status'] === 'archived') {
            $this->authorize('archive', $applicant);
        }

        // Employment and separation statuses belong to their workflows.
        $this->lifecycle->assertManualTransitionAllowed($data['to_status']);

        /*
         * The disposition travels with the move and is written in the same
         * transaction, after the move has been validated. It used to be saved
         * first, so a move the transition map refused still left a disposition
         * on the record - "not suitable" against somebody whose status never
         * changed. Now both land, or neither does.
         */
        $disposition = empty($data['disposition_reason']) ? [] : [
            'disposition_reason' => $data['disposition_reason'],
            'disposition_note' => $data['disposition_note'] ?? null,
            'disposition_recorded_at' => now(),
        ];

        $applicant = $this->lifecycle->transition(
            $applicant,
            $data['to_status'],
            $request->user(),
            $data['reason'] ?? null,
            $disposition,
            ['job_request_id' => $data['job_request_id'] ?? null]
        );

        return ApiResponse::success([
            'applicant' => new ApplicantResource($applicant),
            'allowed_transitions' => $this->lifecycle->offeredNextStatuses($applicant->current_status),
            'placement' => $this->placements->present($applicant),
        ], 'Status updated');
    }

    /**
     * Correct the applicant's answer to their current placement (audit R-07).
     *
     * For a change of mind the applicant reports to the office after answering
     * on the portal. The first answer stays the applicant's to give. A
     * correction needs a reason and is kept beside the answer it replaces;
     * nothing is overwritten.
     */
    public function placementDecision(Request $request, Applicant $applicant): JsonResponse
    {
        $this->authorize('changeStatus', $applicant);

        $data = $request->validate([
            'decision' => ['required', Rule::in(PlacementDecision::DECISIONS)],
            'note' => ['nullable', 'string', 'max:255'],
            'correction_reason' => ['required', 'string', 'max:255'],
        ], [
            'correction_reason.required' => 'Give the reason for correcting the answer on record.',
        ]);

        $this->placements->recordStaffDecision(
            $applicant,
            $request->user(),
            $data['decision'],
            $data['note'] ?? null,
            $data['correction_reason'],
        );

        return ApiResponse::success(
            $this->placements->present($applicant->fresh()),
            'Answer corrected. The earlier answer stays on record.'
        );
    }

    /**
     * Confirm an online registrant's identity at the counter.
     *
     * The one action that releases a self-registered applicant into screening.
     * It records that a named officer checked this person against their
     * documents, which is what the agency's in-person requirement actually
     * means — and what makes the record trustworthy from that point on.
     */
    public function verifyIdentity(Request $request, Applicant $applicant): JsonResponse
    {
        $this->authorize('changeStatus', $applicant);

        if (! $applicant->awaiting_identity_check) {
            return ApiResponse::error(
                'This applicant does not need an identity check. Records created at the office are verified as they are entered.',
                400
            );
        }

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        // One transaction: the identity mark and the move into screening land
        // together, so a failed move cannot leave a "verified" applicant who is
        // still held at "applied".
        $applicant = DB::transaction(function () use ($applicant, $request, $data) {
            $applicant->forceFill([
                'identity_verified_at' => now(),
                'identity_verified_by' => $request->user()->id,
            ])->save();

            $this->audit->record(
                action: 'update',
                module: 'applicants',
                recordType: Applicant::class,
                recordId: $applicant->id,
                newValues: [
                    'identity_verified' => true,
                    'verified_by' => $request->user()->full_name,
                    'remarks' => $data['remarks'] ?? null,
                ],
            );

            // Now that a person has been seen, the record can enter screening.
            return $this->lifecycle->transition(
                $applicant->fresh(),
                'initial_screening',
                $request->user(),
                'Identity confirmed at the office.'
            );
        });

        return ApiResponse::success(
            new ApplicantResource($applicant),
            'Identity confirmed. This applicant can now be screened.'
        );
    }

    /**
     * The consolidated applicant timeline: status changes, training, and
     * evaluations in one chronological list.
     */
    public function timeline(Applicant $applicant): JsonResponse
    {
        $this->authorize('view', $applicant);

        $events = $applicant->statusHistory()->with('changedBy')->get()
            ->map(fn ($h) => [
                'type' => 'status_change',
                'occurred_at' => $h->changed_at?->toIso8601String(),
                'title' => 'Status changed to '.ucwords(str_replace('_', ' ', $h->to_status)),
                // Rows the old deployment code wrote by itself say so (R-11).
                'detail' => $h->wasWrittenAutomaticallyAtDeployment()
                    ? trim($h->reason.' '.ApplicationStatusHistory::AUTO_WRITTEN_NOTE)
                    : $h->reason,
                'recorded_automatically' => $h->wasWrittenAutomaticallyAtDeployment(),
                'actor' => $h->changedBy?->full_name,
            ]);

        $trainings = $applicant->trainingEnrollments()->with('training')->get()
            ->map(fn (TrainingEnrollment $e) => [
                'type' => 'training',
                'occurred_at' => $e->training?->training_date?->toIso8601String(),
                'title' => $e->training?->training_title,
                'detail' => $this->describeEnrolment($e),
                'actor' => $e->training?->trainer_name,
            ]);

        $evaluations = $applicant->matches()->with('jobRequest')->get()
            ->map(fn ($m) => [
                'type' => 'evaluation',
                'occurred_at' => $m->evaluated_at?->toIso8601String(),
                'title' => 'Evaluated for '.($m->jobRequest?->position_title ?? 'a request'),
                'detail' => ($m->rank_order !== null ? "Ranked #{$m->rank_order} at " : 'Scored ')
                    ."{$m->percentage_score}% (".str_replace('_', ' ', $m->recommendation_level).')',
                'actor' => null,
            ]);

        $timeline = $events->concat($trainings)->concat($evaluations)
            ->filter(fn ($e) => ! is_null($e['occurred_at']))
            ->sortByDesc('occurred_at')
            ->values();

        return ApiResponse::success($timeline);
    }

    /** One enrolment in words, saying only what was actually recorded. */
    private function describeEnrolment(TrainingEnrollment $enrolment): string
    {
        if ($enrolment->training?->isCancelled()) {
            return 'Session cancelled; nothing recorded for it counts towards training.';
        }

        $attendance = match ($enrolment->attendance_status) {
            'present' => 'Attended',
            'absent' => 'Absent',
            default => 'Enrolled; attendance not recorded yet',
        };

        return $attendance.($enrolment->hasCompleted() ? '; completion recorded.' : '; not completed.');
    }

    /**
     * Creates the full requirement checklist for a new applicant.
     */
    private function createRequirementChecklist(Applicant $applicant): void
    {
        $rows = RequirementType::active()->get()->map(fn (RequirementType $type) => [
            'applicant_id' => $applicant->id,
            'requirement_type_id' => $type->id,
            'status' => 'missing',
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table('applicant_requirements')->insert($rows);
        }
    }
}
