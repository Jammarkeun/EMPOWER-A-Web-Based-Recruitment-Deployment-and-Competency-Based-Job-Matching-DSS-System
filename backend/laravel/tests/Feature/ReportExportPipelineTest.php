<?php

namespace Tests\Feature;

use App\Jobs\BuildReportExport;
use App\Models\ReportExport;
use App\Models\Training;
use App\Services\Reporting\ReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * How exports are produced (audit PERF-04, FN-08) and which filter values a
 * report accepts (audit CQ-02).
 */
class ReportExportPipelineTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Storage::fake('local');

        $hr = $this->hrUser();
        $this->applicant($hr, ['first_name' => 'Ana']);
        $this->applicant($hr, ['first_name' => 'Ben']);
        Sanctum::actingAs($hr);
    }

    /** A ReportBuilder that counts how often it is asked to build or count. */
    private function spyBuilder(): ReportBuilder
    {
        $spy = new class extends ReportBuilder {
            public int $builds = 0;

            public int $counts = 0;

            public function build(string $type, array $filters = []): array
            {
                $this->builds++;

                return parent::build($type, $filters);
            }

            public function count(string $type, array $filters = []): int
            {
                $this->counts++;

                return parent::count($type, $filters);
            }
        };

        $this->app->instance(ReportBuilder::class, $spy);

        return $spy;
    }

    public function test_an_export_must_name_its_format(): void
    {
        $this->postJson('/api/v1/reports/export', ['report_type' => 'applicants'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');

        // The preview never needed one.
        $this->postJson('/api/v1/reports/preview', ['report_type' => 'applicants'])->assertOk();
    }

    public function test_a_large_export_is_counted_rather_than_built_twice(): void
    {
        config(['empower.reports.queue_threshold_rows' => 2]);
        $spy = $this->spyBuilder();

        // QUEUE_CONNECTION is "sync" in the suite, so the job runs inside this request.
        $id = $this->post('/api/v1/reports/export', ['report_type' => 'applicants', 'format' => 'csv'])
            ->assertStatus(202)
            ->json('data.export_id');

        $this->assertSame(1, $spy->counts);
        $this->assertSame(1, $spy->builds, 'Only the queued job should build the report.');
        $this->assertSame('completed', ReportExport::findOrFail($id)->status);
    }

    public function test_a_small_export_is_built_once_and_downloaded_directly(): void
    {
        $spy = $this->spyBuilder();

        $this->post('/api/v1/reports/export', ['report_type' => 'applicants', 'format' => 'csv'])->assertOk();

        $this->assertSame(1, $spy->builds);
    }

    public function test_the_count_matches_the_rows_the_report_builds(): void
    {
        $builder = app(ReportBuilder::class);

        foreach (array_keys(ReportBuilder::TYPES) as $type) {
            $this->assertSame(
                $builder->build($type)['rows']->count(),
                $builder->count($type),
                "{$type}: count() and build() disagree"
            );
        }
    }

    public function test_a_queued_export_that_fails_once_is_retried_rather_than_left_processing(): void
    {
        $export = ReportExport::create([
            'report_type' => 'applicants',
            'filter_json' => [],
            'export_format' => 'csv',
            'status' => 'queued',
            'requested_by' => auth()->id(),
            'requested_at' => now(),
        ]);

        $flaky = new class extends ReportBuilder {
            public int $attempts = 0;

            public function build(string $type, array $filters = []): array
            {
                if (++$this->attempts === 1) {
                    throw new RuntimeException('Database went away.');
                }

                return parent::build($type, $filters);
            }
        };

        $job = new BuildReportExport($export->id);

        try {
            $job->handle($flaky);
            $this->fail('The first attempt should have thrown.');
        } catch (RuntimeException) {
            // As the queue worker sees it: the attempt failed and will be retried.
        }

        $this->assertSame('queued', $export->fresh()->status, 'A failed attempt must leave the export ready to retry.');

        $job->handle($flaky);

        $this->assertSame('completed', $export->fresh()->status);
        Storage::disk('local')->assertExists($export->fresh()->file_path);
    }

    public function test_each_report_offers_and_accepts_only_its_own_filter_values(): void
    {
        $types = collect($this->getJson('/api/v1/reports')->assertOk()->json('data.types'))->keyBy('key');

        $this->assertSame(Training::STATUSES, $types['training']['options']['status']);
        $this->assertContains('online', $types['applicants']['options']['source_channel']);
        $this->assertArrayNotHasKey('status', $types['applicants']['options']);

        // "finalized" belongs to terminations; on the training report it used
        // to produce an empty report without a word.
        $this->postJson('/api/v1/reports/preview', [
            'report_type' => 'training',
            'filters' => ['status' => 'finalized'],
        ])->assertStatus(422)->assertJsonValidationErrors('filters.status');

        $this->postJson('/api/v1/reports/preview', [
            'report_type' => 'terminations',
            'filters' => ['status' => 'finalized'],
        ])->assertOk();
    }
}
