<?php

namespace Tests\Feature;

use App\Models\AttendanceImport;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\User;
use Database\Seeders\DtrSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concurrency\ThrowawayDatabase;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The synthetic DTR sample files, imported through the real import endpoints.
 *
 * The files and their expected results live in docs/09-testing/dtr-samples/
 * and are written by generate_dtr_samples.php; the expectations there are
 * worked out by hand from the comparison rules, not from this code. Each test
 * uploads a file exactly as HR does - upload, accept the suggested columns,
 * preview, confirm - and checks what the system then shows against them.
 * Nothing is written to the attendance tables except by the importer.
 *
 * Runs on SQLite with the rest of the suite. It can also run against the
 * throwaway MariaDB database: DB_CONNECTION=mysql MYSQL_DATABASE=empower_test.
 * Any other MySQL database is refused before anything is migrated.
 */
class DtrSampleFilesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private const PERIOD = ['2026-10-19', '2026-10-25'];

    private User $hr;
    private ClientCompany $client;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            ThrowawayDatabase::assert();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-04 09:00:00'));
        Storage::fake('documents');

        $this->seedReferenceData();
        $this->adminUser();
        $this->hr = $this->hrUser();
        $this->seed(DtrSampleSeeder::class);

        $this->client = ClientCompany::where('company_name', DtrSampleSeeder::CLIENT)->sole();
        Sanctum::actingAs($this->hr);
    }

    // ------------------------------------------------------------- helpers

    private function samples(string $file): string
    {
        return base_path('../../docs/09-testing/dtr-samples/'.$file);
    }

    /** @return list<array<string, string>> */
    private function expected(string $file): array
    {
        $handle = fopen($this->samples($file), 'r');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $row);
        }

        fclose($handle);

        return $rows;
    }

    private function upload(string $file, array $period = self::PERIOD)
    {
        // A copy, so the sample in docs/ is never moved or changed.
        $copy = tempnam(sys_get_temp_dir(), 'dtr');
        copy($this->samples($file), $copy);

        return $this->post('/api/v1/attendance/imports', [
            'file' => new UploadedFile($copy, $file, null, null, true),
            'client_company_id' => $this->client->id,
            'period_from' => $period[0],
            'period_to' => $period[1],
            'source_note' => 'Synthetic sample file '.$file,
        ], ['Accept' => 'application/json']);
    }

    /** Upload, accept the suggested columns, and preview - as HR does. */
    private function preview(string $file, array $period = self::PERIOD): array
    {
        $upload = $this->upload($file, $period)->assertCreated();
        $preview = $this->postJson("/api/v1/attendance/imports/{$upload->json('data.id')}/preview", $upload->json('data.mapping'))
            ->assertOk();

        return [$upload->json('data.id'), $upload->json('data.mapping'), $preview->json('data')];
    }

    private function confirm(int $id)
    {
        return $this->postJson("/api/v1/attendance/imports/{$id}/confirm", ['conflict_action' => 'skip']);
    }

    /** HR confirms the client's DTR period the import was filed under complete. */
    private function confirmPeriod(int $importId): array
    {
        $period = $this->getJson("/api/v1/attendance/imports/{$importId}")->assertOk()->json('data.dtr_period');

        return $this->postJson("/api/v1/attendance/dtr-periods/{$period['id']}/status", ['action' => 'complete', 'acknowledge_gaps' => true])
            ->assertOk()->assertJsonPath('data.status', 'complete')->json('data');
    }

    /** Straight after the import: every scheduled day with no row only awaits the DTR. */
    private function assertWeekBeforeConfirmation(): void
    {
        foreach (collect($this->expected('EMPOWER_DTR_Expected_Day_Results.csv'))->groupBy('biometric_no') as $bio => $rows) {
            $employee = $this->employee((string) $bio);
            $days = collect($this->getJson("/api/v1/employees/{$employee->id}/attendance?from=".self::PERIOD[0].'&to='.self::PERIOD[1])
                ->assertOk()->json('data.days'))->keyBy('date');

            foreach ($rows as $row) {
                $label = "{$row['employee']} {$row['date']} before the period is confirmed";
                $this->assertSame($row['status_before_period_confirmed'], $days[$row['date']]['status'], $label);

                if ($row['status_before_period_confirmed'] === 'awaiting_dtr') {
                    $this->assertSame([], $days[$row['date']]['discrepancies'], $label);
                    $this->assertNull($days[$row['date']]['review_status'], $label);
                }
            }
        }
    }

    private function employee(string $biometric): Employee
    {
        return Employee::where('biometric_number', $biometric)->sole();
    }

    private function assertRowOutcomes(string $file, array $preview): void
    {
        $expected = collect($this->expected('EMPOWER_DTR_Expected_Row_Outcomes.csv'))->where('file', $file);
        $actual = collect($preview['rows'])->keyBy('line');

        $this->assertCount($expected->count(), $preview['rows'], "{$file}: every row is reported");

        foreach ($expected as $row) {
            $this->assertSame($row['expected_row_status'], $actual[(int) $row['line']]['status'] ?? null,
                "{$file} line {$row['line']}: {$row['why']}");
        }
    }

    /** Each day of the test week on the employee's own DTR tab, against the expected table. */
    private function assertWeekAsExpected(): void
    {
        foreach (collect($this->expected('EMPOWER_DTR_Expected_Day_Results.csv'))->groupBy('biometric_no') as $bio => $rows) {
            $employee = $this->employee((string) $bio);
            $days = collect($this->getJson("/api/v1/employees/{$employee->id}/attendance?from=".self::PERIOD[0].'&to='.self::PERIOD[1])
                ->assertOk()->json('data.days'))->keyBy('date');

            foreach ($rows as $row) {
                $day = $days[$row['date']];
                $label = "{$row['employee']} {$row['date']} ({$row['scenario']})";

                $this->assertSame($row['expected_status'], $day['status'], $label);
                $this->assertSame(array_filter(explode(';', $row['expected_discrepancies'])), $day['discrepancies'], $label);
                $this->assertSame((int) $row['late_minutes'], $day['late_minutes'], $label);
                $this->assertSame((int) $row['early_departure_minutes'], $day['early_departure_minutes'], $label);
                $this->assertSame($row['expected_review_status'] ?: null, $day['review_status'], $label);
                $this->assertSame($row['time_in'] ?: null, $day['time_in'], $label);
                $this->assertSame($row['time_out'] ?: null, $day['time_out'], $label);
                $this->assertSame($row['row_in_file'] === 'yes', $day['has_record'], $label);
            }
        }
    }

    // ---------------------------------------------------------------- tests

    public function test_the_synthetic_workers_have_approved_schedules_covering_the_test_week(): void
    {
        foreach (DtrSampleSeeder::EMPLOYEES as $bio => $person) {
            $employee = $this->employee((string) $bio);
            $schedule = EmployeeSchedule::where('employee_id', $employee->id)->inForce()->sole();

            $this->assertSame('active', $employee->employment_status);
            $this->assertSame(DtrSampleSeeder::DEPLOYED_ON, $schedule->effective_from->toDateString());
            $this->assertNull($schedule->effective_to);
            $this->assertSame(DtrSampleSeeder::SCHEDULES[$person['schedule']]['shift_start'], $schedule->time('shift_start'));

            // Workers at the sample client got theirs through a coordinator's
            // submission that HR approved.
            $this->assertSame($person['client'] === 'main' ? 'approved' : null, $schedule->submission?->status);
        }

        // Nothing has been imported yet.
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_the_valid_csv_is_previewed_imported_and_compared_with_the_schedules(): void
    {
        [$id, $mapping, $preview] = $this->preview('EMPOWER_DTR_Valid_Test_Data.csv');

        // The suggested columns are right for the file, so HR only confirms them.
        $this->assertSame([
            'has_header' => true, 'identifier_type' => 'biometric_number', 'identifier_column' => 0, 'date_column' => 2,
            'date_format' => 'Y-m-d', 'time_in_column' => 3, 'time_out_column' => 4, 'name_column' => 1, 'remarks_column' => 5,
        ], $mapping);

        $this->assertSame(15, $preview['ready']);
        $this->assertSame(0, $preview['errors']);
        $this->assertSame(3, $preview['employees_in_file']);
        $this->assertSame(['total_rows' => 15, 'valid_rows' => 15, 'ready_rows' => 15, 'invalid_rows' => 0, 'skipped_rows' => 0,
            'unmatched_employees' => 0, 'duplicates' => 0, 'outside_period' => 0], array_slice($preview['summary'], 0, 8));
        $this->assertSame(['from' => '2026-10-19', 'to' => '2026-10-24'], $preview['summary']['dates_in_file']);
        $this->assertFalse($preview['summary']['punch_log_suspected']);
        $this->assertSame(['Dario Villanueva'], array_column($preview['employees_without_rows'], 'full_name'));
        $this->assertRowOutcomes('EMPOWER_DTR_Valid_Test_Data.csv', $preview);
        $this->assertSame(0, AttendanceRecord::count(), 'A preview writes nothing.');

        $this->confirm($id)->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.imported_rows', 15)
            ->assertJsonPath('data.error_rows', 0);

        $this->assertSame(15, AttendanceRecord::where('attendance_import_id', $id)->where('source', 'import')->count());

        // Imported, but nobody has said the week's DTR is complete yet.
        $this->assertWeekBeforeConfirmation();
        $this->assertSame(0, $this->getJson('/api/v1/attendance/discrepancies?from='.self::PERIOD[0].'&to='.self::PERIOD[1]
            ."&client_company_id={$this->client->id}&discrepancy=no_record")->assertOk()->json('meta.total'));

        $period = $this->confirmPeriod($id);
        $this->assertSame(4, $period['reconciliation']['totals']['employees_placed']);
        $this->assertSame(0, $period['reconciliation']['totals']['days_awaiting_dtr']);
        $this->assertWeekAsExpected();

        // The weekly figures: the employee's tab and the workforce summary agree with each other and with the table.
        $workforce = collect($this->getJson('/api/v1/attendance?from='.self::PERIOD[0].'&to='.self::PERIOD[1]."&client_company_id={$this->client->id}")
            ->assertOk()->json('data'))->keyBy('employee_id');
        $this->assertCount(4, $workforce);

        foreach ($this->expected('EMPOWER_DTR_Expected_Summary.csv') as $row) {
            $employee = $this->employee($row['biometric_no']);
            $tab = $this->getJson("/api/v1/employees/{$employee->id}/attendance?from=".self::PERIOD[0].'&to='.self::PERIOD[1])->json('data.summary');

            foreach (array_slice($row, 2, null, true) as $key => $value) {
                $want = $key === 'data_status' ? $value : (int) $value;
                $this->assertSame($want, $tab[$key], "{$row['employee']} {$key}");
                $this->assertSame($want, $workforce[$employee->id]['summary'][$key], "{$row['employee']} {$key} (workforce)");
            }
        }

        // The review queue holds exactly the flagged days, each awaiting review.
        $queue = $this->getJson('/api/v1/attendance/discrepancies?from='.self::PERIOD[0].'&to='.self::PERIOD[1]
            ."&client_company_id={$this->client->id}&per_page=100")->assertOk();
        $flagged = collect($this->expected('EMPOWER_DTR_Expected_Day_Results.csv'))->where('expected_review_status', 'for_review');
        $this->assertSame($flagged->count(), $queue->json('meta.total'));
        $this->assertEqualsCanonicalizing(
            $flagged->map(fn ($r) => $r['biometric_no'].'|'.$r['date'])->values()->all(),
            collect($queue->json('data'))->map(fn ($d) => Employee::find($d['employee']['id'])->biometric_number.'|'.$d['date'])->all()
        );
        $this->assertSame(['for_review'], collect($queue->json('data'))->pluck('review_status')->unique()->values()->all());

        // The night shift's time-out is the next morning.
        $benjie = $this->employee('90002');
        $monday = collect($this->getJson("/api/v1/employees/{$benjie->id}/attendance?from=2026-10-19&to=2026-10-19")->json('data.days'))->first();
        $this->assertTrue($monday['time_out_next_day']);

        // History and audit.
        $listed = $this->getJson('/api/v1/attendance/imports')->assertOk()->json('data.0');
        $this->assertSame(['completed', 15, 0], [$listed['status'], $listed['imported_rows'], $listed['error_rows']]);
        $this->assertEqualsCanonicalizing(['upload', 'import'], AuditLog::where('record_type', 'AttendanceImport')->where('record_id', $id)->pluck('action_type')->all());

        // The same file again is refused, and nothing is added.
        $this->upload('EMPOWER_DTR_Valid_Test_Data.csv')->assertStatus(409);
        $this->assertSame(15, AttendanceRecord::count());
    }

    public function test_the_xlsx_version_reads_exactly_like_the_csv(): void
    {
        [$id, $mapping, $preview] = $this->preview('EMPOWER_DTR_Valid_Test_Data.xlsx');

        $this->assertSame('Y-m-d', $mapping['date_format']);
        $this->assertSame(15, $preview['ready']);
        $this->assertSame(0, $preview['errors']);

        $this->confirm($id)->assertOk()->assertJsonPath('data.imported_rows', 15);
        $this->assertWeekBeforeConfirmation();

        // The CSV of the same DTR, afterwards, finds every day already on file.
        [$csvId, , $again] = $this->preview('EMPOWER_DTR_Valid_Test_Data.csv');
        $this->assertSame(15, $again['counts']['unchanged']);
        $this->assertSame(0, $again['ready']);
        $this->postJson("/api/v1/attendance/imports/{$csvId}/discard")->assertOk();
        $this->assertSame(15, AttendanceRecord::count());

        $this->confirmPeriod($id);
        $this->assertWeekAsExpected();

        // Once the week is confirmed complete, another file for it waits until HR reopens the period.
        $this->upload('EMPOWER_DTR_Valid_Test_Data.csv')->assertStatus(409);
    }

    public function test_the_invalid_file_reports_every_bad_row_and_imports_nothing(): void
    {
        [$id, , $preview] = $this->preview('EMPOWER_DTR_Invalid_Test_Data.csv');

        $this->assertRowOutcomes('EMPOWER_DTR_Invalid_Test_Data.csv', $preview);
        $this->assertSame(0, $preview['ready']);
        $this->assertSame(11, $preview['errors']);
        $this->assertSame(1, $preview['counts']['blank_times']);

        // Nothing importable: the confirmation is refused rather than "succeeding" with nothing.
        $this->confirm($id)->assertStatus(422);
        $this->assertSame(0, AttendanceRecord::count());
        $this->assertSame('draft', AttendanceImport::find($id)->status);

        $this->postJson("/api/v1/attendance/imports/{$id}/discard")->assertOk()->assertJsonPath('data.status', 'discarded');
        $this->assertTrue(AuditLog::where('record_type', 'AttendanceImport')->where('record_id', $id)->where('action_type', 'discard')->exists());

        // A day with no DTR at all is still only awaiting it.
        $dario = $this->employee('90004');
        $day = collect($this->getJson("/api/v1/employees/{$dario->id}/attendance?from=2026-10-21&to=2026-10-21")->json('data.days'))->first();
        $this->assertSame('awaiting_dtr', $day['status']);
    }

    public function test_the_duplicate_file_keeps_one_of_each_day_and_never_overwrites_the_dtr_on_file(): void
    {
        [$validId] = $this->preview('EMPOWER_DTR_Valid_Test_Data.csv');
        $this->confirm($validId)->assertOk();

        [$id, , $preview] = $this->preview('EMPOWER_DTR_Duplicate_Test_Data.csv', ['2026-10-19', '2026-11-01']);
        $this->assertRowOutcomes('EMPOWER_DTR_Duplicate_Test_Data.csv', $preview);

        $this->confirm($id)->assertOk()
            ->assertJsonPath('data.imported_rows', 2)
            ->assertJsonPath('data.unchanged_rows', 1)
            ->assertJsonPath('data.skipped_rows', 2)
            ->assertJsonPath('data.error_rows', 2)
            ->assertJsonPath('data.replaced_rows', 0);

        $alma = $this->employee('90001');
        $carla = $this->employee('90003');

        $this->assertSame(17, AttendanceRecord::count());
        $this->assertSame('08:00', AttendanceRecord::where('employee_id', $alma->id)->whereDate('work_date', '2026-10-20')->sole()->time_in->format('H:i'),
            'The day that differed keeps the DTR already on file.');
        $this->assertSame(1, AttendanceRecord::where('employee_id', $carla->id)->whereDate('work_date', '2026-10-26')->count());
        $this->assertSame(0, AttendanceRecord::where('employee_id', $carla->id)->whereDate('work_date', '2026-10-27')->count());

        // 27 Oct was listed twice with different times, so neither was taken.
        // It awaits the DTR until HR confirms the period, then is a gap to
        // review - never an absence.
        $day = fn () => collect($this->getJson("/api/v1/employees/{$carla->id}/attendance?from=2026-10-27&to=2026-10-27")->json('data.days'))->first();
        $this->assertSame('awaiting_dtr', $day()['status']);
        $this->assertSame(3, $preview['summary']['duplicates'], 'One exact repeat and two rows that disagree.');

        // A file reaching past the first week's period is a period of its own.
        $period = $this->confirmPeriod($id);
        $this->assertSame(['2026-10-19', '2026-11-01'], [$period['period_from'], $period['period_to']]);
        $this->assertSame(['no_record'], $day()['discrepancies']);
    }
}
