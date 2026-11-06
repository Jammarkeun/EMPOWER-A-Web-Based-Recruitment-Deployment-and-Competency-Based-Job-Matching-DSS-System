<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CoordinatorScoped;
use App\Http\Responses\ApiResponse;
use App\Models\DtrSubmission;
use App\Services\Attendance\DtrFileReader;
use App\Services\Attendance\DtrSubmissionPresenter;
use App\Services\AttendanceService;
use App\Services\DocumentStorageService;
use App\Services\DtrImportService;
use App\Services\DtrSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client's DTR files, handed over by the coordinator to HR.
 *
 * The coordinator sends the file as the client gave it; HR checks and imports
 * it in the existing DTR importer. Nothing here writes attendance. The client
 * is always the one in the coordinator's assignment (CoordinatorScoped); a
 * client id in the request is never read.
 */
class CoordinatorDtrSubmissionController extends Controller
{
    use CoordinatorScoped;

    public function __construct(
        private readonly DtrSubmissionService $submissions,
        private readonly DtrSubmissionPresenter $present,
        private readonly DocumentStorageService $storage,
    ) {
    }

    /** The assigned client and the importer's own limits, for the form. */
    public function options(Request $request): JsonResponse
    {
        $company = $this->assignment($request)->company;

        return ApiResponse::success([
            'clients' => [['id' => $company->id, 'company_name' => $company->company_name]],
            'max_period_days' => AttendanceService::MAX_PERIOD_DAYS,
            'max_file_kb' => (int) config('empower.attendance.import_max_kb', 5120),
            'max_rows' => (int) config('empower.attendance.import_max_rows', 10000),
            'extensions' => DtrFileReader::EXTENSIONS,
            'statuses' => collect(DtrSubmission::LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...DtrSubmission::STATUSES, 'pending'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $submissions = DtrSubmission::query()
            ->with(['company', 'submitter', 'reviewer', 'import'])
            ->where('client_company_id', $this->companyId($request))
            ->when($filters['status'] ?? null, fn ($q, $v) => $v === 'pending' ? $q->pending() : $q->where('status', $v))
            // Returned ones first: they are waiting on the coordinator.
            ->orderByRaw("CASE WHEN status = 'returned' THEN 0 ELSE 1 END")
            ->latest('submitted_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(
            $submissions->through(fn (DtrSubmission $s) => $this->present->row($s)),
            'DTR submissions retrieved'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $submission = $this->submissions->submit($this->assignment($request), $request->user(), $request->file('file'), $data);

        return ApiResponse::created($this->present->detail($submission), 'DTR file sent to HR');
    }

    /** A corrected file for one HR returned. The returned one is kept as it was. */
    public function resubmit(Request $request, DtrSubmission $submission): JsonResponse
    {
        $this->abortUnlessInScope($request, $submission->client_company_id, 'DTR submission');

        $data = $this->validated($request);
        $corrected = $this->submissions->submit($this->assignment($request), $request->user(), $request->file('file'), $data, $submission);

        return ApiResponse::created($this->present->detail($corrected), 'Corrected DTR file sent to HR');
    }

    public function show(Request $request, DtrSubmission $submission): JsonResponse
    {
        $this->abortUnlessInScope($request, $submission->client_company_id, 'DTR submission');

        return ApiResponse::success($this->present->detail($submission));
    }

    /** The file as it was sent. */
    public function file(Request $request, DtrSubmission $submission): Response
    {
        $this->abortUnlessInScope($request, $submission->client_company_id, 'DTR submission');

        return $this->download($submission);
    }

    /** The importer's own file and period rules; the client comes from the assignment. */
    private function validated(Request $request): array
    {
        $data = $request->validate(DtrImportService::uploadRules(), DtrImportService::uploadMessages());
        DtrImportService::assertPeriodLength($data['period_from'], $data['period_to']);

        return $data;
    }

    private function download(DtrSubmission $submission): Response
    {
        return response($this->storage->contents($submission->file_path), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $submission->original_filename).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
