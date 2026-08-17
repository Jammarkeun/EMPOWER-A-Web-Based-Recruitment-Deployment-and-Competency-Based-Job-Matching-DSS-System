<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\Training;
use App\Models\TrainingEnrollment;
use App\Services\ApplicantLifecycleService;
use App\Services\AuditService;
use App\Services\ReferenceCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrainingController extends Controller
{
    public function __construct(
        private readonly ReferenceCodeService $codes,
        private readonly ApplicantLifecycleService $lifecycle,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Training::class);

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $trainings = Training::query()
            ->withCount([
                'enrollments',
                'enrollments as attended_count' => fn ($q) => $q->where('attendance_status', 'present'),
                'enrollments as completed_count' => fn ($q) => $q->where('completion_status', 'completed'),
            ])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('training_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('training_date', '<=', $v))
            ->orderByDesc('training_date')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated($trainings, 'Training sessions retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Training::class);

        $data = $request->validate([
            'training_title' => ['required', 'string', 'max:190'],
            'training_date' => ['required', 'date'],
            'location' => ['required', 'string', 'max:190'],
            'trainer_name' => ['required', 'string', 'max:190'],
            'remarks' => ['nullable', 'string'],
        ]);

        $training = Training::create(array_merge($data, [
            'training_code' => $this->codes->training(),
            'status' => 'scheduled',
            'created_by' => $request->user()->id,
        ]));

        $this->audit->record('create', 'training', Training::class, $training->id, null, $data);

        return ApiResponse::created($training, 'Training scheduled');
    }

    public function show(Training $training): JsonResponse
    {
        $this->authorize('view', $training);

        $training->load('enrollments.applicant');

        return ApiResponse::success($training);
    }

    public function update(Request $request, Training $training): JsonResponse
    {
        $this->authorize('update', $training);

        $data = $request->validate([
            'training_title' => ['sometimes', 'string', 'max:190'],
            'training_date' => ['sometimes', 'date'],
            'location' => ['sometimes', 'string', 'max:190'],
            'trainer_name' => ['sometimes', 'string', 'max:190'],
            'status' => ['sometimes', Rule::in(['scheduled', 'ongoing', 'completed', 'cancelled'])],
            'remarks' => ['nullable', 'string'],
        ]);

        $before = $training->only(array_keys($data));
        $training->update($data);

        $this->audit->recordUpdate('training', Training::class, $training->id, $before, $data);

        return ApiResponse::success($training->fresh(), 'Training updated');
    }

    /**
     * Enrol applicants into a session.
     */
    public function enrol(Request $request, Training $training): JsonResponse
    {
        $this->authorize('update', $training);

        $data = $request->validate([
            'applicant_ids' => ['required', 'array', 'min:1'],
            'applicant_ids.*' => ['integer', 'exists:applicants,id'],
        ]);

        $eligible = Applicant::whereIn('id', $data['applicant_ids'])
            ->whereIn('current_status', ['ready_for_deployment', 'training_scheduled', 'approved'])
            ->get();

        $rejected = collect($data['applicant_ids'])->diff($eligible->pluck('id'));

        foreach ($eligible as $applicant) {
            TrainingEnrollment::firstOrCreate([
                'training_id' => $training->id,
                'applicant_id' => $applicant->id,
            ]);

            if ($this->lifecycle->canTransition($applicant->current_status, 'training_scheduled')) {
                $this->lifecycle->transition(
                    $applicant,
                    'training_scheduled',
                    $request->user(),
                    "Enrolled in {$training->training_title}."
                );
            }
        }

        return ApiResponse::success([
            'enrolled' => $eligible->count(),
            // Named explicitly rather than silently dropped, so HR can see who
            // was not enrolled and why.
            'skipped' => $rejected->values(),
            'skipped_reason' => $rejected->isEmpty()
                ? null
                : 'These applicants are not yet at a stage where training applies.',
        ], 'Enrolment updated');
    }

    /**
     * Record attendance and completion for one enrolled applicant.
     */
    public function updateEnrolment(Request $request, Training $training, TrainingEnrollment $enrollment): JsonResponse
    {
        $this->authorize('update', $training);

        abort_unless($enrollment->training_id === $training->id, 404);

        $data = $request->validate([
            'attendance_status' => ['sometimes', Rule::in(['pending', 'present', 'absent'])],
            'completion_status' => ['sometimes', Rule::in(['not_completed', 'completed'])],
            'remarks' => ['nullable', 'string'],
        ]);

        $enrollment->update($data);

        // Completing training moves the applicant forward automatically, so the
        // trainer's record and the recruitment status cannot drift apart.
        if (($data['completion_status'] ?? null) === 'completed') {
            $applicant = $enrollment->applicant;

            if ($this->lifecycle->canTransition($applicant->current_status, 'training_completed')) {
                $this->lifecycle->transition(
                    $applicant,
                    'training_completed',
                    $request->user(),
                    "Completed {$training->training_title}."
                );
            }
        }

        return ApiResponse::success($enrollment->fresh()->load('applicant'), 'Enrolment updated');
    }
}
