<?php

namespace App\Http\Controllers;

use App\Exports\ReportExport;
use App\Exports\SpreadsheetSafety;
use App\Jobs\BuildReportExport;
use App\Models\ReportExport as ReportExportRecord;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\Deployment;
use App\Models\EmployeeViolation;
use App\Models\Resignation;
use App\Models\Termination;
use App\Models\Training;
use App\Services\AuditService;
use App\Services\Reporting\ReportBuilder;
use App\Support\ApplicantStatuses;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report generation and export.
 *
 * Reports are produced synchronously and streamed straight back to the browser
 * rather than queued to a file. The agency's data volume is in the hundreds of
 * records, where a download that arrives immediately is far better than a job to
 * poll — and it removes the need for a queue worker in production.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly AuditService $audit,
    ) {
    }

    /** The report catalogue, used to build the report picker. */
    public function index(): JsonResponse
    {
        $this->authorize('viewReports');

        return ApiResponse::success([
            'types' => collect(ReportBuilder::TYPES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'filters' => $this->filtersFor($key),
                // The values each of this report's choice filters accepts.
                'options' => $this->optionsFor($key),
            ])->values(),
        ]);
    }

    /** On-screen preview, so HR can check a report before printing it. */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('viewReports');

        $data = $this->validateRequest($request);
        $report = $this->builder->build($data['report_type'], $data['filters'] ?? []);

        return ApiResponse::success([
            'title' => $report['title'],
            'subtitle' => $report['subtitle'],
            'period' => $report['period'],
            'columns' => $report['columns'],
            // Capped for the preview only. The full set is written to the export.
            'rows' => $report['rows']->take(100)->values(),
            'total_rows' => $report['rows']->count(),
            'truncated' => $report['rows']->count() > 100,
            'summary' => $report['summary'],
            'generated_at' => $report['generated_at'],
            // The same letterhead as the PDF, for the printed preview.
            'letterhead' => [
                'agency' => config('empower.organisation.name'),
                'address' => config('empower.organisation.address'),
            ],
        ]);
    }

    public function export(Request $request): Response|BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $this->authorize('exportReports');

        $data = $this->validateRequest($request, forExport: true);
        $filters = $data['filters'] ?? [];

        /*
         * Counted before anything is built (audit PERF-04). A report big
         * enough to be queued used to be built in full here only to learn its
         * size, and then built again by the job - double the work exactly
         * when the report is largest.
         */
        if ($this->builder->count($data['report_type'], $filters) >= config('empower.reports.queue_threshold_rows', 1000)) {
            $queued = ReportExportRecord::create([
                'report_type' => $data['report_type'],
                'filter_json' => $data['filters'] ?? [],
                'export_format' => $data['format'],
                'status' => 'queued',
                'requested_by' => $request->user()->id,
                'requested_at' => now(),
            ]);

            BuildReportExport::dispatch($queued->id);

            return ApiResponse::success([
                'export_id' => $queued->id,
                'status' => 'queued',
            ], 'Large report queued for generation', 202);
        }

        $report = $this->builder->build($data['report_type'], $filters);

        $this->audit->record('export', 'reports', null, null, null, [
            'report_type' => $data['report_type'],
            'format' => $data['format'],
            'row_count' => $report['rows']->count(),
            'filters' => $data['filters'] ?? [],
        ]);

        $filename = $this->filename($report['title'], $data['format']);

        return match ($data['format']) {
            'pdf' => $this->toPdf($report, $filename, $request),
            'xlsx' => Excel::download(new ReportExport($report, $request->user()->full_name), $filename),
            'csv' => $this->toCsv($report, $filename),
        };
    }

    public function exportStatus(Request $request, ReportExportRecord $export): JsonResponse
    {
        abort_unless($export->requested_by === $request->user()->id, 404);

        $data = ['id' => $export->id, 'status' => $export->status];
        if ($export->status === 'failed') $data['message'] = $export->error_message;
        if ($export->isReady()) $data['download_url'] = url('/api/v1/reports/exports/'.$export->id.'/download');

        return ApiResponse::success($data);
    }

    public function downloadQueuedExport(Request $request, ReportExportRecord $export): Response
    {
        abort_unless($export->requested_by === $request->user()->id, 404);
        abort_unless($export->isReady(), 409, 'This report is not ready yet.');

        return response()->download(
            storage_path('app/private/'.$export->file_path),
            basename($export->file_path),
            ['Content-Type' => mime_content_type(storage_path('app/private/'.$export->file_path))]
        );
    }

    private function toCsv(array $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $output = fopen('php://output', 'wb');
            fputcsv($output, $report['columns']);

            // Text that could start a formula is neutralised (audit R-09):
            // applicant-typed names and addresses reach this file.
            foreach ($report['rows'] as $row) {
                fputcsv($output, SpreadsheetSafety::row($row));
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function toPdf(array $report, string $filename, Request $request): Response
    {
        $pdf = Pdf::loadView('reports.document', array_merge($report, [
            'prepared_by' => $request->user()->full_name,
        ]));

        // Landscape by default: these reports run to nine or ten columns, and
        // portrait squeezes them past the point of being readable.
        $pdf->setPaper('a4', 'landscape');

        $pdf->setOption('isRemoteEnabled', false);

        return $pdf->download($filename);
    }

    private function validateRequest(Request $request, bool $forExport = false): array
    {
        $type = (string) $request->input('report_type');
        $options = array_key_exists($type, ReportBuilder::TYPES) ? $this->optionsFor($type) : [];

        // A choice filter must name one of the report's own values: a typo or a
        // value from another report used to produce a silently empty report.
        $choice = fn (string $filter) => ['nullable', 'string', Rule::in($options[$filter] ?? [])];

        return $request->validate([
            'report_type' => ['required', Rule::in(array_keys(ReportBuilder::TYPES))],
            // Required for an export (audit FN-08): "required_with:format" checked
            // nothing, and a missing format reached the renderer as a 500.
            'format' => [$forExport ? 'required' : 'nullable', Rule::in(['pdf', 'xlsx', 'csv'])],
            'filters' => ['nullable', 'array'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date', 'after_or_equal:filters.date_from'],
            'filters.year' => ['nullable', 'integer', 'between:2000,2100'],
            'filters.client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'filters.client_department_id' => ['nullable', 'integer', 'exists:client_departments,id'],
            /*
             * Position is filtered by title rather than by id.
             *
             * The column that actually records where somebody works is
             * `current_position_title` on the employee and `position_title` on
             * the deployment - the client's own wording, captured when the
             * placement was made. Filtering on a reference and hoping it agrees
             * with that text would quietly drop rows whenever the two differ,
             * which is the exact failure this report is meant to rule out. The
             * picker offers the titles the system knows about, so the value is
             * still chosen rather than typed.
             */
            'filters.position_title' => ['nullable', 'string', 'max:150'],
            'filters.current_status' => $choice('current_status'),
            'filters.employment_status' => $choice('employment_status'),
            'filters.folder_category' => $choice('folder_category'),
            'filters.request_status' => $choice('request_status'),
            'filters.deployment_status' => $choice('deployment_status'),
            'filters.violation_type' => $choice('violation_type'),
            'filters.source_channel' => $choice('source_channel'),
            'filters.status' => $choice('status'),
        ]);
    }

    /**
     * The values each choice filter of a report accepts, read from the
     * vocabulary that owns them (audit CQ-02).
     *
     * The report picker used to keep its own lists, and one "status" list for
     * every report - so the training report offered "finalized" and the
     * termination report offered "scheduled", each returning nothing - and it
     * left "online" out of the ways an applicant can apply.
     *
     * @return array<string, list<string>>
     */
    private function optionsFor(string $type): array
    {
        $status = match ($type) {
            'clients' => ['active', 'inactive'],
            'violations' => EmployeeViolation::STATUSES,
            'training' => Training::STATUSES,
            'resignations' => Resignation::STATUSES,
            'terminations' => Termination::STATUSES,
            default => [],
        };

        $all = [
            'current_status' => ApplicantStatuses::all(),
            'employment_status' => array_keys(config('empower.employee_transitions', [])),
            'folder_category' => array_keys(config('empower.folders', [])),
            'request_status' => array_keys(config('empower.job_request_transitions', [])),
            'deployment_status' => Deployment::STATUSES,
            'violation_type' => EmployeeViolation::TYPES,
            'source_channel' => Applicant::SOURCE_CHANNELS,
            'status' => $status,
        ];

        return array_intersect_key($all, array_flip($this->filtersFor($type)));
    }

    /** Which filters the picker should offer for a given report. */
    private function filtersFor(string $type): array
    {
        $dateRange = ['date_from', 'date_to'];

        return match ($type) {
            'applicants' => [...$dateRange, 'current_status', 'folder_category', 'source_channel', 'position_title'],
            'employees' => [...$dateRange, 'employment_status', 'client_company_id', 'client_department_id', 'position_title'],
            'deployments' => [...$dateRange, 'client_company_id', 'client_department_id', 'position_title', 'deployment_status'],
            'violations' => [...$dateRange, 'violation_type', 'status'],
            'clients' => ['status'],
            'job_requests' => [...$dateRange, 'client_company_id', 'client_department_id', 'position_title', 'request_status'],
            'training' => [...$dateRange, 'status'],
            'resignations', 'terminations' => [...$dateRange, 'status'],
            'annual_summary' => ['year'],
            default => [],
        };
    }

    private function filename(string $title, string $format): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $title));

        return trim($slug, '-').'-'.now()->format('Y-m-d').'.'.$format;
    }
}
