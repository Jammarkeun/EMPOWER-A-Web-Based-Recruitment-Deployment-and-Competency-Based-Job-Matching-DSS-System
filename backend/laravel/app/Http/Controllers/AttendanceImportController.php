<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AttendanceImport;
use App\Models\Employee;
use App\Services\Attendance\AttendancePresenter;
use App\Services\Attendance\DtrFileReader;
use App\Services\AttendanceService;
use App\Services\DocumentStorageService;
use App\Services\DtrImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * DTR files from a client: upload, map the columns, preview, import.
 *
 * Provisional until CDE supplies a sample of the client's export - see
 * DtrImportService. The file is kept on the private document disk, reachable
 * only through this controller by staff who may manage attendance.
 */
class AttendanceImportController extends Controller
{
    public function __construct(
        private readonly DtrImportService $imports,
        private readonly AttendancePresenter $present,
        private readonly DocumentStorageService $storage,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $filters = $request->validate([
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'status' => ['nullable', Rule::in(AttendanceImport::STATUSES)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $imports = AttendanceImport::query()
            ->with(['company', 'uploader', 'completer', 'dtrPeriod', 'dtrSubmission'])
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated(
            $imports->through(fn (AttendanceImport $i) => $this->present->import($i)),
            'DTR imports'
        );
    }

    /**
     * What the upload form offers: the clients a DTR can be imported for, and
     * the limits the upload is checked against.
     */
    public function uploadOptions(Request $request): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        return ApiResponse::success([
            'clients' => $this->imports->importableClients($request->user())->map(fn ($c) => [
                'id' => $c->id,
                'company_name' => $c->company_name,
                'status' => $c->status,
                'placed_now' => (int) $c->placed_now,
            ])->values(),
            'max_period_days' => AttendanceService::MAX_PERIOD_DAYS,
            'max_file_kb' => (int) config('empower.attendance.import_max_kb', 5120),
            'max_rows' => (int) config('empower.attendance.import_max_rows', 10000),
            'extensions' => DtrFileReader::EXTENSIONS,
        ] + $this->options());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        // Checked against the same list the form offers, never against any id
        // that happens to exist.
        $clients = $this->imports->importableClients($request->user())->pluck('id')->all();

        $data = $request->validate(
            DtrImportService::uploadRules() + ['client_company_id' => ['required', 'integer', Rule::in($clients)]],
            DtrImportService::uploadMessages() + [
                'client_company_id.required' => 'Choose whose DTR this is.',
                'client_company_id.in' => 'Choose one of the listed clients. A DTR can only be imported for a client with workers placed there.',
            ]
        );

        DtrImportService::assertPeriodLength($data['period_from'], $data['period_to']);

        $import = $this->imports->upload($request->file('file'), $data, $request->user());

        return ApiResponse::created(
            $this->present->import($import->load(['company', 'uploader', 'dtrPeriod'])) + $this->imports->inspect($import),
            'File uploaded. Choose its columns to check it.'
        );
    }

    public function show(AttendanceImport $import): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $import->load(['company', 'uploader', 'completer', 'dtrPeriod', 'dtrSubmission']);

        return ApiResponse::success(
            $this->present->import($import, withResults: true)
                + ($import->isDraft() ? $this->imports->inspect($import) : [])
                + ['options' => $this->options()]
        );
    }

    public function preview(Request $request, AttendanceImport $import): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $mapping = $request->validate([
            'has_header' => ['required', 'boolean'],
            'identifier_type' => ['required', Rule::in(array_keys(DtrImportService::IDENTIFIERS))],
            'identifier_column' => ['required', 'integer', 'min:0'],
            'date_column' => ['required', 'integer', 'min:0'],
            'date_format' => ['required', Rule::in(array_keys(DtrImportService::DATE_FORMATS))],
            'time_in_column' => ['required', 'integer', 'min:0'],
            'time_out_column' => ['required', 'integer', 'min:0'],
            'name_column' => ['nullable', 'integer', 'min:0'],
            'remarks_column' => ['nullable', 'integer', 'min:0'],
        ]);

        return ApiResponse::success($this->imports->preview($import, $mapping), 'Preview ready');
    }

    public function confirm(Request $request, AttendanceImport $import): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $data = $request->validate([
            'conflict_action' => ['required', Rule::in(['skip', 'replace'])],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:conflict_action,replace'],
        ], [
            'reason.required_if' => 'Say why the DTR on file should be replaced by this file.',
        ]);

        $import = $this->imports->confirm($import, $data['conflict_action'], $data['reason'] ?? null, $request->user());

        return ApiResponse::success(
            $this->present->import($import->load(['company', 'uploader', 'completer', 'dtrPeriod', 'dtrSubmission']), withResults: true),
            sprintf('%d day(s) imported%s', $import->imported_rows, $import->replaced_rows ? sprintf(', %d replaced', $import->replaced_rows) : '')
        );
    }

    public function discard(Request $request, AttendanceImport $import): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $this->imports->discard($import, $request->user());

        return ApiResponse::success($this->present->import($import->load(['company', 'uploader'])), 'Import discarded');
    }

    /**
     * The file as received, for checking an import against its source.
     */
    public function file(AttendanceImport $import): Response
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        abort_unless($import->file_path !== null, 404);

        return response($this->storage->contents($import->file_path), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $import->original_filename).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function options(): array
    {
        return [
            'identifier_types' => collect(DtrImportService::IDENTIFIERS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'date_formats' => collect(DtrImportService::DATE_FORMATS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ];
    }
}
