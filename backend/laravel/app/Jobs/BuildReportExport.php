<?php

namespace App\Jobs;

use App\Exports\ReportExport as SpreadsheetExport;
use App\Exports\SpreadsheetSafety;
use App\Models\ReportExport;
use App\Services\Reporting\ReportBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class BuildReportExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(public readonly int $exportId)
    {
    }

    public function handle(ReportBuilder $builder): void
    {
        $export = ReportExport::find($this->exportId);
        if (! $export || $export->status !== 'queued') return;

        $export->update(['status' => 'processing']);
        $filename = 'report-exports/'.$export->id.'-'.now()->format('YmdHis').'.'.$export->export_format;

        try {
            $report = $builder->build($export->report_type, $export->filter_json ?? []);

            if ($export->export_format === 'xlsx') {
                Excel::store(new SpreadsheetExport($report), $filename, 'local');
            } elseif ($export->export_format === 'pdf') {
                $pdf = Pdf::loadView('reports.document', array_merge($report, [
                    'prepared_by' => $export->requester?->full_name ?? 'EMPOWER',
                ]))->setPaper('a4', 'landscape');
                Storage::disk('local')->put($filename, $pdf->output());
            } else {
                $this->writeCsv($report, $filename);
            }
        } catch (Throwable $e) {
            /*
             * Back to "queued" so the retry runs. It used to stay "processing",
             * which the guard above then skipped: the retry "succeeded" without
             * doing anything, failed() never ran, and the export sat in
             * "processing" for ever while the screen waited on it.
             */
            Storage::disk('local')->delete($filename);
            $export->update(['status' => 'queued']);

            throw $e;
        }

        $export->update(['status' => 'completed', 'file_path' => $filename, 'completed_at' => now()]);
    }

    /** The CSV, with the same formula neutralising as the direct download (audit R-09). */
    private function writeCsv(array $report, string $filename): void
    {
        $stream = fopen('php://temp', 'w+');

        try {
            fputcsv($stream, $report['columns']);
            foreach ($report['rows'] as $row) {
                fputcsv($stream, SpreadsheetSafety::row($row));
            }
            rewind($stream);
            Storage::disk('local')->put($filename, stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function failed(\Throwable $exception): void
    {
        ReportExport::whereKey($this->exportId)->update([
            'status' => 'failed',
            'error_message' => 'The report could not be generated.',
            'completed_at' => now(),
        ]);
    }
}
