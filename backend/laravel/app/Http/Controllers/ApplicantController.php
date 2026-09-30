<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApplicantRequest;
use App\Http\Resources\ApplicantResource;
use App\Http\Resources\StatusHistoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\JobPosition;
use App\Models\RequirementType;
use App\Services\ApplicantLifecycleService;
use App\Services\AuditService;
use App\Services\FolderCategoryService;
use App\Services\ReferenceCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ApplicantController extends Controller
{
    public function __construct(
        private readonly ApplicantLifecycleService $lifecycle,
        private readonly FolderCategoryService $folders,
        private readonly ReferenceCodeService $codes,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Applicant::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'current_status' => ['nullable', 'string'],
            'folder_category' => ['nullable', Rule::in(['folder_1', 'folder_2', 'folder_3'])],
            'source_channel' => ['nullable', Rule::in(['walk_in', 'messenger', 'email', 'online'])],
            'awaiting_identity_check' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'sort_by' => ['nullable', Rule::in(['application_date', 'last_name', 'created_at', 'current_status'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $applicants = Applicant::query()
            ->search($filters['search'] ?? null)
            ->when($filters['current_status'] ?? null, fn ($q, $v) => $q->where('current_status', $v))
            ->when($filters['folder_category'] ?? null, fn ($q, $v) => $q->where('folder_category', $v))
            ->when($filters['source_channel'] ?? null, fn ($q, $v) => $q->where('source_channel', $v))
            ->when($filters['awaiting_identity_check'] ?? false, fn ($q) => $q->awaitingIdentityCheck())
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('application_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('application_date', '<=', $v))
            ->orderBy($filters['sort_by'] ?? 'application_date', $filters['sort_dir'] ?? 'desc')
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

            return $applicant;
        });

        $this->lifecycle->transition($applicant, 'initial_screening', $request->user(), 'Applicant registered at the office.');

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
        ]);

        return ApiResponse::success([
            'applicant' => new ApplicantResource($applicant),
            'folder' => $this->folders->explain($applicant),
            'allowed_transitions' => $this->lifecycle->allowedNextStatuses($applicant->current_status),
        ]);
    }

    public function update(Request $request, Applicant $applicant): JsonResponse
    {
        $this->authorize('update', $applicant);

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'sex' => ['nullable', Rule::in(['male', 'female'])],
            'birth_date' => ['nullable', 'date', 'before:'.now()->subYears(15)->toDateString()],
            'civil_status' => ['nullable', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'height_cm' => ['nullable', 'numeric', 'between:100,250'],
            'weight_kg' => ['nullable', 'numeric', 'between:30,300'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'present_address' => ['sometimes', 'string', 'max:255'],
            'provincial_address' => ['nullable', 'string', 'max:255'],
            'preferred_position_id' => ['nullable', 'integer', 'exists:job_positions,id'],
            'preferred_position' => ['nullable', 'string', 'max:150'],
            'availability_date' => ['nullable', 'date'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'communication_rating' => ['nullable', 'numeric', 'between:0,5'],
            'reliability_rating' => ['nullable', 'numeric', 'between:0,5'],
            'remarks' => ['nullable', 'string'],
        ]);

        $data = $this->withPositionTitle($data);
        $before = $applicant->only(array_keys($data));

        $applicant->update(array_merge($data, ['updated_by' => $request->user()->id]));

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
        ]);

        // Recorded before the transition, so it is already on the record when
        // the status history row and its notification are written.
        if (! empty($data['disposition_reason'])) {
            $applicant->forceFill([
                'disposition_reason' => $data['disposition_reason'],
                'disposition_note' => $data['disposition_note'] ?? null,
                'disposition_recorded_at' => now(),
            ])->save();
        }

        $applicant = $this->lifecycle->transition(
            $applicant,
            $data['to_status'],
            $request->user(),
            $data['reason'] ?? null
        );

        return ApiResponse::success([
            'applicant' => new ApplicantResource($applicant),
            'allowed_transitions' => $this->lifecycle->allowedNextStatuses($applicant->current_status),
        ], 'Status updated');
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
        $applicant = $this->lifecycle->transition(
            $applicant->fresh(),
            'initial_screening',
            $request->user(),
            'Identity confirmed at the office.'
        );

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
                'detail' => $h->reason,
                'actor' => $h->changedBy?->full_name,
            ]);

        $trainings = $applicant->trainingEnrollments()->with('training')->get()
            ->map(fn ($e) => [
                'type' => 'training',
                'occurred_at' => $e->training?->training_date?->toIso8601String(),
                'title' => $e->training?->training_title,
                'detail' => 'Attendance: '.$e->attendance_status.'; completion: '.$e->completion_status,
                'actor' => $e->training?->trainer_name,
            ]);

        $evaluations = $applicant->matches()->with('jobRequest')->get()
            ->map(fn ($m) => [
                'type' => 'evaluation',
                'occurred_at' => $m->evaluated_at?->toIso8601String(),
                'title' => 'Evaluated for '.($m->jobRequest?->position_title ?? 'a request'),
                'detail' => "Ranked #{$m->rank_order} at {$m->percentage_score}% ("
                    .str_replace('_', ' ', $m->recommendation_level).')',
                'actor' => null,
            ]);

        $timeline = $events->concat($trainings)->concat($evaluations)
            ->filter(fn ($e) => ! is_null($e['occurred_at']))
            ->sortByDesc('occurred_at')
            ->values();

        return ApiResponse::success($timeline);
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
