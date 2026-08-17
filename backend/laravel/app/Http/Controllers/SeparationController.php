<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\Resignation;
use App\Models\Termination;
use App\Services\DocumentStorageService;
use App\Services\SeparationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SeparationController extends Controller
{
    public function __construct(
        private readonly SeparationService $separation,
        private readonly DocumentStorageService $storage,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Resignation::class);

        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['resignation', 'termination'])],
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $resignations = Resignation::with('employee.applicant')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('filing_date')->get()
            ->map(fn ($r) => [
                'type' => 'resignation',
                'id' => $r->id,
                'employee_id' => $r->employee_id,
                'employee_name' => $r->employee?->full_name,
                'employee_number' => $r->employee?->employee_number,
                'reason' => $r->reason,
                'date' => $r->filing_date?->toDateString(),
                'exit_date' => $r->exit_date?->toDateString(),
                'clearance_status' => $r->clearance_status,
                'status' => $r->status,
            ]);

        $terminations = Termination::with('employee.applicant')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('termination_date')->get()
            ->map(fn ($t) => [
                'type' => 'termination',
                'id' => $t->id,
                'employee_id' => $t->employee_id,
                'employee_name' => $t->employee?->full_name,
                'employee_number' => $t->employee?->employee_number,
                'reason' => $t->reason,
                'date' => $t->termination_date?->toDateString(),
                'exit_date' => $t->termination_date?->toDateString(),
                'clearance_status' => null,
                'status' => $t->status,
            ]);

        $records = match ($filters['type'] ?? null) {
            'resignation' => $resignations,
            'termination' => $terminations,
            default => $resignations->concat($terminations)->sortByDesc('date')->values(),
        };

        return ApiResponse::success($records);
    }

    // -------------------------------------------------------------- resignations

    public function storeResignation(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('separate', $employee);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'filing_date' => ['required', 'date'],
            // Philippine practice expects 30 days' notice; a shorter or waived
            // period is allowed but must be recorded rather than assumed.
            'rendering_days' => ['nullable', 'integer', 'between:0,90'],
            'exit_date' => ['nullable', 'date', 'after_or_equal:filing_date'],
            'remarks' => ['nullable', 'string'],
            'letter' => [
                'nullable', 'file',
                'max:'.config('empower.uploads.max_size_kb'),
                'mimes:'.implode(',', config('empower.uploads.allowed_mimes')),
            ],
        ]);

        if ($request->hasFile('letter')) {
            $data['resignation_letter_path'] = $this->storage
                ->store($request->file('letter'), 'resignations', $employee->id)['file_path'];
        }
        unset($data['letter']);

        $resignation = $this->separation->fileResignation($employee, $data, $request->user());

        return ApiResponse::created($resignation, 'Resignation filed');
    }

    public function updateResignation(Request $request, Employee $employee, Resignation $resignation): JsonResponse
    {
        $this->authorize('separate', $employee);

        abort_unless($resignation->employee_id === $employee->id, 404);

        $data = $request->validate([
            'reason' => ['sometimes', 'string', 'max:255'],
            'rendering_days' => ['nullable', 'integer', 'between:0,90'],
            'exit_date' => ['nullable', 'date'],
            'clearance_status' => ['sometimes', Rule::in(['pending', 'in_progress', 'cleared'])],
            'status' => ['sometimes', Rule::in(['filed', 'accepted', 'withdrawn'])],
            'remarks' => ['nullable', 'string'],
        ]);

        $resignation->update($data);

        return ApiResponse::success($resignation->fresh(), 'Resignation updated');
    }

    /**
     * Completing a resignation ends active employment and archives the record.
     */
    public function completeResignation(Request $request, Employee $employee, Resignation $resignation): JsonResponse
    {
        $this->authorize('approveSeparation', $employee);

        abort_unless($resignation->employee_id === $employee->id, 404);

        $resignation = $this->separation->completeResignation($resignation, $request->user());

        return ApiResponse::success(
            $resignation,
            'Resignation completed. The employee record has been archived.'
        );
    }

    // -------------------------------------------------------------- terminations

    public function storeTermination(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('separate', $employee);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'termination_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['draft', 'for_review'])],
            'documents' => [
                'nullable', 'file',
                'max:'.config('empower.uploads.max_size_kb'),
                'mimes:'.implode(',', config('empower.uploads.allowed_mimes')),
            ],
        ]);

        if ($request->hasFile('documents')) {
            $data['documents_path'] = $this->storage
                ->store($request->file('documents'), 'terminations', $employee->id)['file_path'];
        }
        unset($data['documents']);

        $termination = $this->separation->fileTermination($employee, $data, $request->user());

        return ApiResponse::created($termination->load('employee.applicant'), 'Termination filed for review');
    }

    /**
     * Finalising is separate from filing so a dismissal passes through review
     * rather than taking effect the moment it is typed in.
     */
    public function finaliseTermination(Request $request, Employee $employee, Termination $termination): JsonResponse
    {
        $this->authorize('finaliseTermination', $employee);

        abort_unless($termination->employee_id === $employee->id, 404);

        $termination = $this->separation->finaliseTermination($termination, $request->user());

        return ApiResponse::success(
            $termination,
            'Termination finalised. The employee record has been archived.'
        );
    }
}
