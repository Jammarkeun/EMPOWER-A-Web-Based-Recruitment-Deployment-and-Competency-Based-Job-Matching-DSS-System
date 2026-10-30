<?php

namespace App\Http\Controllers;

use App\Http\Resources\IncidentReportResource;
use App\Http\Responses\ApiResponse;
use App\Models\IncidentReport;
use App\Services\DocumentStorageService;
use App\Services\IncidentReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The office's side of coordinators' incident reports: read them, and answer
 * with notes - "case pending", "case resolved" - as CDE asked.
 *
 * Nothing here touches an employee's record.
 */
class IncidentReportController extends Controller
{
    public function __construct(
        private readonly IncidentReportService $reports,
        private readonly DocumentStorageService $storage,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', IncidentReport::class);

        $filters = $request->validate([
            // "open" is the default view: what has not been resolved.
            'status' => ['nullable', Rule::in([...IncidentReport::STATUSES, 'open', 'all'])],
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $status = $filters['status'] ?? 'open';

        $reports = IncidentReport::query()
            ->with(['company', 'department', 'reporter'])
            ->withCount('notes')
            // A coordinator's draft is not sent yet, so the office never sees it.
            ->where('status', '!=', 'draft')
            ->when($status === 'open', fn ($q) => $q->whereIn('status', IncidentReport::OPEN))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub
                ->whereContains('report_code', $v)
                ->orWhereContains('title', $v)))
            ->orderByRaw("CASE WHEN status = 'submitted' THEN 0 WHEN status = 'under_review' THEN 1 ELSE 2 END")
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(IncidentReportResource::collection($reports), 'Incident reports retrieved');
    }

    public function show(IncidentReport $report): JsonResponse
    {
        $this->authorize('view', $report);

        return ApiResponse::success(new IncidentReportResource($this->loaded($report)));
    }

    /**
     * A management note, optionally moving the case to pending or resolved.
     */
    public function addNote(Request $request, IncidentReport $report): JsonResponse
    {
        $this->authorize('review', $report);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['under_review', 'resolved'])],
        ]);

        $report = $this->reports->addNote(
            $report,
            $request->user(),
            $data['note'] ?? null,
            $data['status'] ?? null,
            asManagement: true
        );

        return ApiResponse::success(new IncidentReportResource($this->loaded($report)), match ($report->status) {
            'resolved' => 'Marked as case resolved',
            'under_review' => 'Marked as case pending',
            default => 'Note added',
        });
    }

    public function evidence(IncidentReport $report): JsonResponse
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

    private function loaded(IncidentReport $report): IncidentReport
    {
        return $report->load(['company', 'department', 'reporter', 'resolver', 'notes.author']);
    }
}
