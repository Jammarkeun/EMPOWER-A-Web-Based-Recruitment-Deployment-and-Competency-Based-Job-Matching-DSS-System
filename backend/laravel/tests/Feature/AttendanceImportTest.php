<?php

namespace Tests\Feature;

use App\Models\AttendanceImport;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordRevision;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

/**
 * A client's DTR file, brought in without guessing and without overwriting.
 *
 * No sample of the client's export has been received, so HR maps the columns
 * of each file. These tests use a layout made up for the purpose; it is not a
 * format CDE has confirmed.
 */
class AttendanceImportTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private User $hr;

    private ClientCompany $client;

    private Employee $reyes;

    private Employee $santos;

    private Employee $cruz;

    private Employee $elsewhere;

    private const MAPPING = [
        'has_header' => true,
        'identifier_type' => 'biometric_number',
        'identifier_column' => 0,
        'name_column' => 1,
        'date_column' => 2,
        'date_format' => 'm/d/Y',
        'time_in_column' => 3,
        'time_out_column' => 4,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seedReferenceData();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));

        $this->hr = $this->hrUser();
        Sanctum::actingAs($this->hr);

        $this->client = $this->clientCompany($this->hr, ['company_name' => 'Best Tiwi Food Products']);
        $this->reyes = $this->deployedEmployee($this->hr, company: $this->client,
            applicant: ['first_name' => 'Juan', 'last_name' => 'Reyes'], deployment: ['biometric_number' => '0012']);
        $this->santos = $this->deployedEmployee($this->hr, company: $this->client,
            applicant: ['first_name' => 'Ana', 'last_name' => 'Santos'], deployment: ['biometric_number' => '13']);
        $this->cruz = $this->deployedEmployee($this->hr, company: $this->client,
            applicant: ['first_name' => 'Pedro', 'last_name' => 'Cruz'], deployment: ['biometric_number' => '14']);
        $this->elsewhere = $this->deployedEmployee($this->hr,
            applicant: ['last_name' => 'Lopez'], deployment: ['biometric_number' => '99']);

        foreach ([$this->reyes, $this->santos, $this->cruz, $this->elsewhere] as $employee) {
            $this->dayShift($employee);
        }

        // Already typed in by hand before the file arrived.
        $this->encode($this->reyes, [['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00']])->assertOk();
        $this->encode($this->santos, [['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00']])->assertOk();
    }

    private function csv(array $lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('dtr-sept.csv', implode("\n", $lines)."\n");
    }

    private function upload(UploadedFile $file, array $overrides = [])
    {
        return $this->post('/api/v1/attendance/imports', $overrides + [
            'file' => $file,
            'client_company_id' => $this->client->id,
            'period_from' => '2026-09-07',
            'period_to' => '2026-09-11',
            'source_note' => 'Emailed by the client HR',
        ], ['Accept' => 'application/json']);
    }

    private function sampleFile(): UploadedFile
    {
        return $this->csv([
            'AC-No.,Name,Date,Time In,Time Out',
            '12,Juan Reyes,09/07/2026,7:58 AM,5:01 PM',      // differs from the DTR on file
            '12,Juan Reyes,09/08/2026,8:20 AM,5:00 PM',      // new
            '12,Juan Reyes,09/08/2026,8:20 AM,5:00 PM',      // repeated, same times
            '13,Ana Santos,09/07/2026,08:00,17:00',          // already on file
            '13,Ana Santos,09/09/2026,08:00,17:00',          // listed twice...
            '13,Ana Santos,09/09/2026,09:00,17:00',          // ...with different times
            '13,Maria Lopez,09/10/2026,08:00,17:00',         // number and name disagree
            '99,Someone Lopez,09/07/2026,08:00,17:00',       // placed at another client
            '555,Nobody,09/07/2026,08:00,17:00',             // no such employee
            '12,Juan Reyes,2026-09-09,08:00,17:00',          // not the chosen date format
            '12,Juan Reyes,08/30/2026,08:00,17:00',          // outside the file's period
            '12,Juan Reyes,09/10/2026,,',                    // no punches
            '12,Juan Reyes,09/11/2026,25:00,17:00',          // not a time
        ]);
    }

    public function test_a_file_is_mapped_and_checked_before_anything_is_written(): void
    {
        $upload = $this->upload($this->sampleFile())->assertCreated();
        $id = $upload->json('data.id');

        $this->assertSame('draft', $upload->json('data.status'));
        $this->assertSame(13, $upload->json('data.total_rows'));
        $this->assertSame('AC-No.', $upload->json('data.columns.0.header'));
        // A suggestion only, shown for HR to confirm.
        $this->assertTrue($upload->json('data.mapping_is_suggested'));
        $this->assertSame(0, $upload->json('data.mapping.identifier_column'));
        $this->assertSame('m/d/Y', $upload->json('data.mapping.date_format'));
        Storage::disk('documents')->assertExists(AttendanceImport::find($id)->file_path);

        $preview = $this->postJson("/api/v1/attendance/imports/{$id}/preview", self::MAPPING)->assertOk()->json('data');

        $this->assertSame([
            'new' => 1, 'conflict' => 1, 'unchanged' => 1, 'blank_times' => 1, 'duplicate_in_file' => 1,
            'missing_identifier' => 0, 'unmatched_employee' => 1, 'ambiguous_identifier' => 0, 'not_at_client' => 1,
            'name_mismatch' => 1, 'missing_date' => 0, 'invalid_date' => 1, 'invalid_time' => 1, 'outside_period' => 1,
            'future_date' => 0, 'outside_employment' => 0, 'conflicting_in_file' => 2,
        ], $preview['counts']);

        $rows = collect($preview['rows'])->keyBy('line');
        $this->assertSame('conflict', $rows[2]['status']);
        $this->assertSame(['time_in' => '08:00', 'time_out' => '17:00', 'source' => 'manual'], $rows[2]['on_file']);
        $this->assertSame('07:58', $rows[2]['time_in']);
        $this->assertSame($this->reyes->employee_number, $rows[3]['employee_number']);
        $this->assertTrue($rows[10]['is_error']);
        $this->assertSame(['Pedro Cruz'], array_column($preview['employees_without_rows'], 'full_name'));

        // Nothing written by looking.
        $this->assertDatabaseCount('attendance_records', 2);
        $this->assertDatabaseCount('attendance_coverage_periods', 0);
    }

    public function test_importing_writes_only_clean_rows_and_records_which_days_the_file_covers(): void
    {
        $id = $this->upload($this->sampleFile())->json('data.id');
        $this->postJson("/api/v1/attendance/imports/{$id}/preview", self::MAPPING)->assertOk();

        $this->postJson("/api/v1/attendance/imports/{$id}/confirm", ['conflict_action' => 'skip'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.imported_rows', 1)
            ->assertJsonPath('data.replaced_rows', 0)
            ->assertJsonPath('data.unchanged_rows', 1);

        $imported = AttendanceRecord::where('employee_id', $this->reyes->id)->whereDate('work_date', '2026-09-08')->first();
        $this->assertSame('import', $imported->source);
        $this->assertSame((int) $id, $imported->attendance_import_id);
        $this->assertSame(3, $imported->source_row);
        $this->assertSame('08:20', $imported->time_in->format('H:i'));

        // The conflicting day was left as it was.
        $this->assertSame('08:00', AttendanceRecord::where('employee_id', $this->reyes->id)
            ->whereDate('work_date', '2026-09-07')->first()->time_in->format('H:i'));
        $this->assertSame(1, AttendanceRecordRevision::where('attendance_import_id', $id)->count());

        // The file says which days it covers: a blank scheduled day in it is a
        // gap in the DTR, while somebody missing from the file is still only
        // awaiting their DTR.
        $this->assertSame(['no_record'], $this->days($this->reyes, '2026-09-09', '2026-09-09')['2026-09-09']['discrepancies']);
        $this->assertSame('awaiting_dtr', $this->days($this->cruz, '2026-09-09', '2026-09-09')['2026-09-09']['status']);
        $this->assertSame('DTR-2026-00001', $this->days($this->reyes, '2026-09-08', '2026-09-08')['2026-09-08']['import_code']);

        // The same file a second time would only repeat it.
        $this->upload($this->sampleFile())->assertStatus(409);
        $this->postJson("/api/v1/attendance/imports/{$id}/confirm", ['conflict_action' => 'skip'])->assertStatus(409);

        $history = $this->getJson('/api/v1/attendance/imports')->assertOk()->json('data');
        $this->assertSame('Emailed by the client HR', $history[0]['source_note']);
        $this->assertSame($this->hr->full_name, $history[0]['uploaded_by']);

        $this->get("/api/v1/attendance/imports/{$id}/file")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_dtr_on_file_is_replaced_only_when_asked_and_with_a_reason(): void
    {
        $id = $this->upload($this->csv([
            'AC-No.,Name,Date,Time In,Time Out',
            '12,Juan Reyes,09/07/2026,07:58,17:01',
        ]))->json('data.id');
        $this->postJson("/api/v1/attendance/imports/{$id}/preview", self::MAPPING)->assertOk()->assertJsonPath('data.counts.conflict', 1);

        $this->postJson("/api/v1/attendance/imports/{$id}/confirm", ['conflict_action' => 'replace'])->assertStatus(422);
        $this->postJson("/api/v1/attendance/imports/{$id}/confirm", [
            'conflict_action' => 'replace',
            'reason' => 'Client export supersedes the hand-copied sheet',
        ])->assertOk()->assertJsonPath('data.replaced_rows', 1);

        $record = AttendanceRecord::where('employee_id', $this->reyes->id)->whereDate('work_date', '2026-09-07')->first();
        $this->assertSame('07:58', $record->time_in->format('H:i'));
        $this->assertSame('import', $record->source);

        $revision = AttendanceRecordRevision::where('attendance_import_id', $id)->first();
        $this->assertSame('updated', $revision->action);
        $this->assertSame('08:00', $revision->old_time_in->format('H:i'));
        $this->assertSame('manual', $revision->old_source);
        $this->assertSame('Client export supersedes the hand-copied sheet', $revision->reason);
    }

    public function test_an_excel_workbook_and_a_night_shift_are_read(): void
    {
        $this->postJson("/api/v1/employees/{$this->cruz->id}/schedules", [
            'effective_from' => '2026-09-07', 'work_days' => [1, 2, 3, 4, 5], 'shift_start' => '22:00', 'shift_end' => '06:00',
        ])->assertCreated();

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['Employee No.', 'Work Date', 'IN', 'OUT'],
            [$this->cruz->employee_number, '07-Sep-2026', '22:03', '06:00'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'dtr').'.xlsx';
        (new Xlsx($book))->save($path);

        $id = $this->upload(new UploadedFile($path, 'dtr.xlsx', null, null, true))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/attendance/imports/{$id}/preview", [
            'has_header' => true,
            'identifier_type' => 'employee_number',
            'identifier_column' => 0,
            'date_column' => 1,
            'date_format' => 'd-M-Y',
            'time_in_column' => 2,
            'time_out_column' => 3,
        ])->assertOk()->assertJsonPath('data.counts.new', 1)->assertJsonPath('data.rows.0.time_out_next_day', true);

        $this->postJson("/api/v1/attendance/imports/{$id}/confirm", ['conflict_action' => 'skip'])->assertOk();

        $day = $this->days($this->cruz, '2026-09-07', '2026-09-07')['2026-09-07'];
        $this->assertSame(3, $day['late_minutes']);
        $this->assertTrue($day['time_out_next_day']);
        @unlink($path);
    }

    public function test_a_draft_can_be_discarded_without_trace_in_the_dtr(): void
    {
        $id = $this->upload($this->sampleFile())->json('data.id');
        $path = AttendanceImport::find($id)->file_path;

        // Uploading the same draft twice is refused rather than duplicated.
        $this->upload($this->sampleFile())->assertStatus(409);

        $this->postJson("/api/v1/attendance/imports/{$id}/discard")->assertOk()->assertJsonPath('data.status', 'discarded');
        Storage::disk('documents')->assertMissing($path);
        $this->postJson("/api/v1/attendance/imports/{$id}/confirm", ['conflict_action' => 'skip'])->assertStatus(409);
        $this->assertDatabaseCount('attendance_records', 2);

        // A discarded file may be uploaded again.
        $this->upload($this->sampleFile())->assertCreated();
    }

    public function test_the_upload_is_checked_before_it_is_kept(): void
    {
        $this->upload(UploadedFile::fake()->create('dtr.pdf', 10, 'application/pdf'))->assertStatus(422);
        $this->upload($this->sampleFile(), ['period_to' => '2026-10-15'])->assertStatus(422);
        $this->upload($this->sampleFile(), ['period_from' => '2026-07-01', 'period_to' => '2026-09-11'])->assertStatus(422);
        $this->upload($this->csv(['', '']))->assertStatus(422);

        $this->assertDatabaseCount('attendance_imports', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }
}
