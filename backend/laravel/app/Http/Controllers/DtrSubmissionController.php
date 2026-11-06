<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\DtrSubmission;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\DtrSubmissionPresenter;
use App\Services\DocumentStorageService;
use App\Services\DtrSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR's inbox of DTR files coordinators have handed over.
 *
 * Part of the Attendance (DTR) module and held to its permission,
 * attendance.manage - the same as importing a file directly. "Check and
 * import" opens the stored file in the existing importer as an ordinary
 * draft; the columns, preview and import are the importer's own screens.
 */
class DtrSubmissionController extends Controller
{
    public function __construct(
        private readonly DtrSubmissionService $submissions,
        private readonly DtrSubmissionPresenter $present,
        private readonly DocumentStorageService $storage,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $filters = $request->validate([
            // "pending" - waiting for HR - is the default view.
            'status' => ['nullable', Rule::in([...DtrSubmission::STATUSES, 'pending', 'all'])],
            'client_company_id' => ['nullable', 'integer'],
            'coordinator_id' => ['nullable', 'integer'],
            'covers_from' => ['nullable', 'date_format:Y-m-d'],
            'covers_to' => ['nullable', 'date_format:Y-m-d'],
            'submitted_from' => ['nullable', 'date_format:Y-m-d'],
            'submitted_to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $status = $filters['status'] ?? 'pending';
        $base = fn () => DtrSubmission::query()
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['coordinator_id'] ?? null, fn ($q, $v) => $q->where('submitted_by', $v))
            // Files whose period overlaps the one asked for.
            ->when($filters['covers_from'] ?? null, fn ($q, $v) => $q->whereDate('period_to', '>=', $v))
            ->when($filters['covers_to'] ?? null, fn ($q, $v) => $q->whereDate('period_from', '<=', $v))
            ->when($filters['submitted_from'] ?? null, fn ($q, $v) => $q->whereDate('submitted_at', '>=', $v))
            ->when($filters['submitted_to'] ?? null, fn ($q, $v) => $q->whereDate('submitted_at', '<=', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub
                ->whereContains('submission_code', $v)
                ->orWhereContains('original_filename', $v)));

        $submissions = $base()
            ->with(['company', 'submitter', 'reviewer', 'import'])
            ->when($status === 'pending', fn ($q) => $q->pending(), fn ($q) => $q->when($status !== 'all', fn ($q) => $q->where('status', $status)))
            // Oldest waiting first, so nothing sits at the bottom of the inbox.
            ->when($status === 'pending', fn ($q) => $q->orderBy('submitted_at')->orderBy('id'),
                fn ($q) => $q->latest('submitted_at')->orderByDesc('id'))
            ->paginate($filters['per_page'] ?? 25);

        $counts = $base()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return ApiResponse::paginated(
            $submissions->through(fn (DtrSubmission $s) => $this->present->row($s)),
            'DTR submissions retrieved',
            [
                'status_counts' => $counts->map(fn ($n) => (int) $n),
                'coordinators' => User::query()
                    ->whereIn('id', DtrSubmission::query()->select('submitted_by'))
                    ->orderBy('first_name')
                    ->get()
                    ->map(fn (User $u) => ['id' => $u->id, 'full_name' => $u->full_name])
                    ->values(),
            ]
        );
    }

    public function show(DtrSubmission $submission): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        return ApiResponse::success($this->present->detail($submission));
    }

    /** The file exactly as the coordinator sent it. */
    public function file(DtrSubmission $submission): Response
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        return response($this->storage->contents($submission->file_path), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $submission->original_filename).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Opens the file in the existing importer and returns the import draft,
     * which the import dialog continues from its column step.
     */
    public function import(Request $request, DtrSubmission $submission): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $import = $this->submissions->startImport($submission, $request->user());

        return ApiResponse::success([
            'import_id' => $import->id,
            'import_code' => $import->import_code,
        ], 'Opened in the DTR importer. Choose its columns to check it.');
    }

    public function decide(Request $request, DtrSubmission $submission): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $data = $request->validate([
            'action' => ['required', Rule::in(['return', 'reject'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $submission = $data['action'] === 'return'
            ? $this->submissions->returnToCoordinator($submission, $request->user(), $data['note'] ?? null)
            : $this->submissions->reject($submission, $request->user(), $data['note'] ?? null);

        return ApiResponse::success(
            $this->present->detail($submission),
            $data['action'] === 'return' ? 'Returned to the coordinator for correction' : 'DTR submission rejected'
        );
    }
}
