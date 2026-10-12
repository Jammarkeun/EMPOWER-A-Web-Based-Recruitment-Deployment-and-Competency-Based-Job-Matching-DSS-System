<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The agency works on Philippine time.
 *
 * config/app.php used to ignore APP_TIMEZONE and run on UTC, so between
 * midnight and 8 AM in Manila "today" was still yesterday: an incident that
 * happened that morning could not be dated, and every recorded moment was
 * stored 8 hours behind the wall clock.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    public function test_today_is_the_manila_date_in_the_early_morning(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));

        // 01:30 on 12 October in Manila is still 11 October in UTC.
        $this->travelTo(Carbon::parse('2026-10-11 17:30:00', 'UTC'));

        $this->assertSame('2026-10-12', today()->toDateString());
        $this->assertTrue(
            Validator::make(['incident_date' => '2026-10-12'], ['incident_date' => 'before_or_equal:today'])->passes()
        );
    }

    public function test_a_calendar_date_is_sent_as_that_date(): void
    {
        $applicant = (new Applicant)->forceFill(['birth_date' => '2003-07-23']);

        $this->assertSame('2003-07-23', $applicant->toArray()['birth_date']);
    }

    public function test_a_date_kept_in_an_audit_entry_keeps_its_day(): void
    {
        // Audit before/after values are taken with $model->only(), which hands
        // over the Carbon instance itself rather than the model's serialisation.
        $json = json_encode(['hire_date' => Carbon::parse('2026-09-23')]);

        $this->assertStringContainsString('"2026-09-23T00:00:00+08:00"', $json);
    }

    public function test_the_conversion_moves_recorded_moments_but_not_dtr_clock_times(): void
    {
        $migration = require database_path('migrations/2026_10_12_100000_store_timestamps_in_app_timezone.php');

        $this->seedReferenceData();
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);
        $employee = app(DeploymentService::class)->deploy($applicant, $request, ['deployment_date' => '2026-10-01'], $hr)->employee;

        // Rows as the application wrote them while it ran on UTC.
        $audit = DB::table('audit_logs')->insertGetId([
            'action_type' => 'login',
            'module_key' => 'auth',
            'created_at' => '2026-10-11 17:30:00',
        ]);
        $attendance = DB::table('attendance_records')->insertGetId([
            'employee_id' => $employee->id,
            'work_date' => '2026-10-12',
            'time_in' => '2026-10-12 08:00:00',
            'time_out' => '2026-10-12 17:00:00',
            'recorded_by' => $hr->id,
            'created_at' => '2026-10-12 09:05:00',
            'updated_at' => '2026-10-12 09:05:00',
        ]);

        $migration->up();

        $this->assertSame('2026-10-12 01:30:00', $this->stored('audit_logs', $audit, 'created_at'));
        $this->assertSame('2026-10-12 17:05:00', $this->stored('attendance_records', $attendance, 'created_at'));
        $this->assertSame('2026-10-12 08:00:00', $this->stored('attendance_records', $attendance, 'time_in'));
        $this->assertSame('2026-10-12 17:00:00', $this->stored('attendance_records', $attendance, 'time_out'));
        $this->assertSame('2026-10-12', $this->stored('attendance_records', $attendance, 'work_date'));

        $migration->down();

        $this->assertSame('2026-10-11 17:30:00', $this->stored('audit_logs', $audit, 'created_at'));
        $this->assertSame('2026-10-12 08:00:00', $this->stored('attendance_records', $attendance, 'time_in'));
    }

    /** The column as the database holds it, in the same shape on every driver. */
    private function stored(string $table, int $id, string $column): string
    {
        $value = (string) DB::table($table)->where('id', $id)->value($column);

        return strlen($value) === 10 ? $value : Carbon::parse($value)->format('Y-m-d H:i:s');
    }
}
