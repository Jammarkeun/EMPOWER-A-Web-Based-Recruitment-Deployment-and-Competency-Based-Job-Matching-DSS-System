<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CoordinatorScoped;
use App\Http\Resources\IncidentReportResource;
use App\Http\Responses\ApiResponse;
use App\Models\ClientDepartment;
use App\Models\IncidentReport;
use App\Services\DocumentStorageService;
use App\Services\DocumentUploadPolicy;
use App\Services\IncidentReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The coordinator's incident reports for their assigned client.
 *
 * An incident is something that happened on site - a stoppage, an accident,
 * workers sent home - for the office to read. It names no employee and never
 * becomes a violation; that is what a violation report is for.
 */
class CoordinatorIncidentController extends Controller
{
    use CoordinatorScoped;

    public function __construct(
        private readonly IncidentReportService $reports,
        private readonly DocumentStorageService $storage,
    ) {
    }

    /** The vocabulary for the form: the client's departments and the statuses. */
    public function options(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'departments' => ClientDepartment::query()
                ->where('client_company_id', $this->companyId($request))
                ->where('status', 'active')
                ->orderBy('department_name')
                ->get(['id', 'department_name', 'department_code']),
            // The group first: the dashboard figure counts it, and opens
            // the list on it.
            'statuses' => collect(['open' => 'Open (submitted or under review)'] + IncidentReport::LABELS)
                ->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            // "open" is every status still waiting on the office, as on
            // HR's own list.
            'status' => ['nullable', Rule::in([...IncidentReport::STATUSES, 'open'])],
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $reports = IncidentReport::forCompany($this->companyId($request))
            ->with(['department', 'reporter'])
            ->withCount('notes')
            // Another coordinator's drafts are their own unfinished work.
            ->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhere('reported_by', $request->user()->id))
            ->when($filters['status'] ?? null, fn ($q, $v) => $v === 'open'
                ? $q->whereIn('status', IncidentReport::OPEN)
                : $q->where('status', $v))
            ->when($filters['mine'] ?? false, fn ($q) => $q->where('reported_by', $request->user()->id))
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(IncidentReportResource::collection($reports), 'Incident reports retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(required: true));

        $report = $this->reports->create(
            $this->assignment($request),
            $request->user(),
            collect($data)->except(['evidence', 'submit'])->all(),
            $request->file('evidence')
        );

        if ($request->boolean('submit')) {
            $report = $this->reports->submit($report, $request->user());
        }

        return ApiResponse::created(
            new IncidentReportResource($this->loaded($report)),
            $report->status === 'submitted' ? 'Incident report sent to the office' : 'Draft saved'
        );
    }

    public function show(Request $request, IncidentReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        return ApiResponse::success(new IncidentReportResource($this->loaded($report)));
    }

    public function update(Request $request, IncidentReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        $data = $request->validate($this->rules(required: false));

        $report = $this->reports->update(
            $report,
            $this->assignment($request),
            $request->user(),
            collect($data)->except(['evidence', 'submit'])->all(),
            $request->file('evidence')
        );

        if ($request->boolean('submit')) {
            $report = $this->reports->submit($report, $request->user());
        }

        return ApiResponse::success(
            new IncidentReportResource($this->loaded($report)),
            $report->status === 'submitted' ? 'Incident report sent to the office' : 'Report saved'
        );
    }

    public function submit(Request $request, IncidentReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        $report = $this->reports->submit($report, $request->user());

        return ApiResponse::success(new IncidentReportResource($this->loaded($report)), 'Incident report sent to the office');
    }

    public function destroy(Request $request, IncidentReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        $this->reports->discard($report, $request->user());

        return ApiResponse::success(null, 'Draft discarded');
    }

    /** A follow-up note from the coordinator. Only the office moves the status. */
    public function addNote(Request $request, IncidentReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $report = $this->reports->addNote($report, $request->user(), $data['note'], null, asManagement: false);

        return ApiResponse::success(new IncidentReportResource($this->loaded($report)), 'Note added');
    }

    public function evidence(Request $request, IncidentReport $report): JsonResponse
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
            'title' => [$presence, 'string', 'max:150'],
            'incident_date' => [$presence, 'date', 'before_or_equal:today'],
            'incident_time' => ['nullable', 'date_format:H:i'],
            'client_department_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:150'],
            'details' => [$presence, 'string', 'max:5000'],
            'evidence' => DocumentUploadPolicy::rules(required: false),
            'submit' => ['nullable', 'boolean'],
        ];
    }

    /**
     * A report is visible to the coordinators of the client it was made for,
     * and a draft only to the coordinator writing it.
     */
    private function authorizeReport(Request $request, IncidentReport $report): void
    {
        $this->abortUnlessInScope($request, $report->client_company_id, 'report');

        abort_if(
            $report->status === 'draft' && (int) $report->reported_by !== (int) $request->user()->id,
            403,
            'This draft belongs to another coordinator.'
        );
    }

    private function loaded(IncidentReport $report): IncidentReport
    {
        return $report->load(['company', 'department', 'reporter', 'resolver', 'notes.author']);
    }
}
