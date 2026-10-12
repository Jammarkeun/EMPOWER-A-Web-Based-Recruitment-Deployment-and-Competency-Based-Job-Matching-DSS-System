<?php

namespace App\Http\Controllers;

use App\Http\Resources\ViolationReportResource;
use App\Http\Responses\ApiResponse;
use App\Models\EmployeeViolation;
use App\Models\ViolationReport;
use App\Services\DocumentStorageService;
use App\Services\ViolationReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HR's side of coordinators' reports: read them, and decide.
 *
 * Validating a report records the official violation through the same service
 * HR uses when recording one directly, so the threshold check and its
 * notifications apply. Nothing here terminates anybody.
 */
class ViolationReportController extends Controller
{
    public function __construct(
        private readonly ViolationReportService $reports,
        private readonly DocumentStorageService $storage,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ViolationReport::class);

        $filters = $request->validate([
            // "pending" is the default view: what is waiting on HR.
            'status' => ['nullable', Rule::in([...ViolationReport::STATUSES, 'pending', 'all'])],
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $status = $filters['status'] ?? 'pending';

        $reports = ViolationReport::query()
            ->with(['employee.applicant', 'company', 'department', 'reporter', 'violation'])
            // A coordinator's draft is not sent yet, so HR never sees it.
            ->where('status', '!=', 'draft')
            ->when($status === 'pending', fn ($q) => $q->whereIn('status', ViolationReport::PENDING_REVIEW))
            ->when(! in_array($status, ['pending', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $operator = $q->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $q->where(fn ($sub) => $sub->where('report_code', $operator, "%{$v}%")
                    ->orWhereHas('employee', fn ($e) => $e->where('employee_number', $operator, "%{$v}%")
                        ->orWhereHas('applicant', fn ($a) => $a->search($v))));
            })
            ->orderByRaw("CASE WHEN status IN ('submitted', 'under_review') THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(ViolationReportResource::collection($reports), 'Reports retrieved');
    }

    public function show(ViolationReport $report): JsonResponse
    {
        $this->authorize('view', $report);

        return ApiResponse::success(new ViolationReportResource($this->loaded($report)));
    }

    public function review(Request $request, ViolationReport $report): JsonResponse
    {
        $this->authorize('review', $report);

        $data = $request->validate([
            'action' => ['required', Rule::in(['start_review', 'return', 'validate', 'dismiss'])],
            // Returning or dismissing a report has to say why, so the
            // coordinator - and later readers - know what was wrong with it.
            'note' => ['nullable', 'required_if:action,return,dismiss', 'string', 'max:500'],
            'violation_type' => ['nullable', Rule::in(EmployeeViolation::TYPES)],
            'penalty' => ['nullable', 'string', 'max:190'],
        ], [
            'note.required_if' => 'Say why, so the coordinator knows what to do next.',
        ]);

        $report = $this->reports->review($report, $request->user(), $data['action'], $data);

        return ApiResponse::success(new ViolationReportResource($this->loaded($report)), match ($report->status) {
            'under_review' => 'Marked as under review',
            'returned' => 'Returned to the coordinator for correction',
            'validated' => 'Validated. The violation is now on the employee\'s record.',
            'dismissed' => 'Report dismissed. No violation was recorded.',
            default => 'Report updated',
        });
    }

    public function evidence(ViolationReport $report): JsonResponse
    {
        $this->authorize('view', $report);

        if (blank($report->evidence_path)) {
            return ApiResponse::error('No evidence was attached to this report.', 404);
        }

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrl($report->evidence_path),
            'file_name' => $report->evidence_name,
        ]);
    }

    private function loaded(ViolationReport $report): ViolationReport
    {
        return $report->load(['employee.applicant', 'company', 'department', 'reporter', 'reviewer', 'violation']);
    }
}
