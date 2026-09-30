<?php

namespace App\Jobs;

use App\Exports\ReportExport as SpreadsheetExport;
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
        $report = $builder->build($export->report_type, $export->filter_json ?? []);
        $filename = 'report-exports/'.$export->id.'-'.now()->format('YmdHis').'.'.$export->export_format;

        if ($export->export_format === 'xlsx') {
            Excel::store(new SpreadsheetExport($report), $filename, 'local');
        } elseif ($export->export_format === 'pdf') {
            $pdf = Pdf::loadView('reports.document', array_merge($report, [
                'prepared_by' => $export->requester?->full_name ?? 'EMPOWER',
            ]))->setPaper('a4', 'landscape');
            Storage::disk('local')->put($filename, $pdf->output());
        } else {
            $stream = fopen('php://temp', 'w+');
            fputcsv($stream, $report['columns']);
            foreach ($report['rows'] as $row) fputcsv($stream, $row);
            rewind($stream);
            Storage::disk('local')->put($filename, stream_get_contents($stream));
            fclose($stream);
        }

        $export->update(['status' => 'completed', 'file_path' => $filename, 'completed_at' => now()]);
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
