<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CoordinatorScoped;
use App\Http\Resources\ViolationReportResource;
use App\Http\Responses\ApiResponse;
use App\Models\EmployeeViolation;
use App\Models\ViolationReport;
use App\Services\DocumentStorageService;
use App\Services\DocumentUploadPolicy;
use App\Services\ViolationReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The coordinator's violation reports for their assigned client.
 *
 * The coordinator records what the client reported and sends it to HR. They
 * cannot record an official violation, change one, or touch anything to do
 * with termination - those stay with HR and the administrator.
 */
class CoordinatorReportController extends Controller
{
    use CoordinatorScoped;

    public function __construct(
        private readonly ViolationReportService $reports,
        private readonly DocumentStorageService $storage,
    ) {
    }

    /** The vocabulary for the report form. */
    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'violation_types' => collect(EmployeeViolation::TYPES)->map(fn ($t) => [
                'value' => $t,
                'label' => $t === 'awol' ? 'AWOL' : ucwords(str_replace('_', ' ', $t)),
            ]),
            // The group first: the dashboard figure counts it, and opens
            // the list on it.
            'statuses' => collect(['pending' => 'Awaiting HR'] + ViolationReport::LABELS)
                ->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            // "pending" is every status still waiting on the office, as on
            // HR's own list.
            'status' => ['nullable', Rule::in([...ViolationReport::STATUSES, 'pending'])],
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $reports = ViolationReport::forCompany($this->companyId($request))
            ->with(['employee.applicant', 'department', 'reporter', 'violation'])
            // Another coordinator's drafts are their own unfinished work.
            ->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhere('reported_by', $request->user()->id))
            ->when($filters['status'] ?? null, fn ($q, $v) => $v === 'pending'
                ? $q->whereIn('status', ViolationReport::PENDING_REVIEW)
                : $q->where('status', $v))
            ->when($filters['mine'] ?? false, fn ($q) => $q->where('reported_by', $request->user()->id))
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(ViolationReportResource::collection($reports), 'Reports retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(required: true));

        $report = $this->reports->create(
            $this->assignment($request),
            $request->user(),
            $data,
            $request->file('evidence')
        );

        if ($request->boolean('submit')) {
            $report = $this->reports->submit($report, $request->user());
        }

        return ApiResponse::created(
            new ViolationReportResource($this->loaded($report)),
            $report->status === 'submitted' ? 'Report submitted to HR' : 'Draft saved'
        );
    }

    public function show(Request $request, ViolationReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        return ApiResponse::success(new ViolationReportResource($this->loaded($report)));
    }

    public function update(Request $request, ViolationReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        $data = $request->validate($this->rules(required: false));

        // Changing the employee re-checks them against the assignment and
        // re-stamps the department from their placement.
        if (isset($data['employee_id'])) {
            $employee = $this->reports->employeeInScope($this->assignment($request), (int) $data['employee_id']);
            $data['client_department_id'] = $employee->current_department_id;
        }

        $report = $this->reports->update(
            $report,
            $request->user(),
            collect($data)->except(['evidence', 'submit'])->all(),
            $request->file('evidence')
        );

        if ($request->boolean('submit')) {
            $report = $this->reports->submit($report, $request->user());
        }

        return ApiResponse::success(
            new ViolationReportResource($this->loaded($report)),
            $report->status === 'submitted' ? 'Report submitted to HR' : 'Report saved'
        );
    }

    public function submit(Request $request, ViolationReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        $report = $this->reports->submit($report, $request->user());

        return ApiResponse::success(new ViolationReportResource($this->loaded($report)), 'Report submitted to HR');
    }

    public function destroy(Request $request, ViolationReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        $this->reports->discard($report, $request->user());

        return ApiResponse::success(null, 'Draft discarded');
    }

    public function evidence(Request $request, ViolationReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        if (blank($report->evidence_path)) {
            return ApiResponse::error('No evidence was attached to this report.', 404);
        }

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrl($report->evidence_path),
            'file_name' => $report->evidence_name,
        ]);
    }

    private function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'employee_id' => [$presence, 'integer'],
            'violation_type' => [$presence, Rule::in(EmployeeViolation::TYPES)],
            'incident_date' => [$presence, 'date', 'before_or_equal:today'],
            'details' => [$presence, 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'evidence' => DocumentUploadPolicy::rules(required: false),
            'submit' => ['nullable', 'boolean'],
        ];
    }

    /**
     * A report is visible to the coordinators of the client it was made for,
     * and a draft only to the coordinator writing it.
     */
    private function authorizeReport(Request $request, ViolationReport $report): void
    {
        $this->abortUnlessInScope($request, $report->client_company_id, 'report');

        abort_if(
            $report->status === 'draft' && (int) $report->reported_by !== (int) $request->user()->id,
            403,
            "This draft belongs to another coordinator."
        );
    }

    private function loaded(ViolationReport $report): ViolationReport
    {
        return $report->load(['employee.applicant', 'company', 'department', 'reporter', 'reviewer', 'violation']);
    }
}
