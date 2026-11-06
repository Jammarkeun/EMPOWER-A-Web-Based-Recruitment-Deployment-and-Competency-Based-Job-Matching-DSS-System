<?php

namespace Tests\Feature;

use App\Models\AttendanceDtrPeriod;
use App\Models\AttendanceDtrPeriodEvent;
use App\Models\AttendanceImport;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\User;
use App\Services\CoordinatorAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

/**
 * The DTR import's upload checks, its preview totals, and DTR periods: a file
 * imported is not a DTR complete, and only HR says when it is.
 *
 * The file layout here is made up for the purpose; CDE has not supplied a
 * sample of the client's export.
 */
class DtrPeriodTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private User $hr;

    private ClientCompany $client;

    private Employee $reyes;

    private Employee $cruz;

    private const MAPPING = [
        'has_header' => true,
        'identifier_type' => 'biometric_number',
        'identifier_column' => 0,
        'date_column' => 1,
        'date_format' => 'Y-m-d',
        'time_in_column' => 2,
        'time_out_column' => 3,
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
            applicant: ['last_name' => 'Reyes'], deployment: ['biometric_number' => '12']);
        $this->cruz = $this->deployedEmployee($this->hr, company: $this->client,
            applicant: ['last_name' => 'Cruz'], deployment: ['biometric_number' => '14']);

        $this->dayShift($this->reyes);
        $this->dayShift($this->cruz);
    }

    private function csv(array $lines, string $name = 'dtr.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n");
    }

    private function upload(UploadedFile $file, array $overrides = [])
    {
        return $this->post('/api/v1/attendance/imports', $overrides + [
            'file' => $file,
            'client_company_id' => $this->client->id,
            'period_from' => '2026-09-07',
            'period_to' => '2026-09-11',
            'source_note' => 'Received from client HR through the coordinator on Sep 12, 2026',
        ], ['Accept' => 'application/json']);
    }

    /** Upload, preview with the test mapping, confirm. Returns the import's id. */
    private function import(array $lines, string $name, array $overrides = []): int
    {
        $id = $this->upload($this->csv($lines, $name), $overrides)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/attendance/imports/{$id}/preview", self::MAPPING)->assertOk();
        $this->postJson("/api/v1/attendance/imports/{$id}/confirm", ['conflict_action' => 'skip'])->assertOk();

        return $id;
    }

    private function periodOf(int $importId): array
    {
        return $this->getJson("/api/v1/attendance/imports/{$importId}")->assertOk()->json('data.dtr_period');
    }

    private function decide(int $periodId, array $body)
    {
        return $this->postJson("/api/v1/attendance/dtr-periods/{$periodId}/status", $body);
    }

    private function workbook(string $writer): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([
            ['Bio', 'Date', 'In', 'Out'],
            ['12', '2026-09-07', '08:00', '17:00'],
            ['14', '2026-09-07', '08:10', '17:00'],
        ], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'dtr');
        ($writer === 'xls' ? new Xls($book) : new Xlsx($book))->save($path);

        return new UploadedFile($path, "dtr.{$writer}", null, null, true);
    }

    // ------------------------------------------------------- the upload form

    public function test_the_form_offers_only_clients_with_placements_and_the_upload_is_held_to_them(): void
    {
        $empty = $this->clientCompany($this->hr, ['company_name' => 'Nobody Placed Yet']);

        $options = $this->getJson('/api/v1/attendance/imports/options')->assertOk()->json('data');
        $this->assertSame([$this->client->id], array_column($options['clients'], 'id'));
        $this->assertSame(2, $options['clients'][0]['placed_now']);
        $this->assertSame(62, $options['max_period_days']);
        $this->assertSame(['csv', 'txt', 'xlsx', 'xls'], $options['extensions']);

        $line = ['Bio,Date,In,Out', '12,2026-09-07,08:00,17:00'];
        $this->upload($this->csv($line), ['client_company_id' => $empty->id])->assertStatus(422)->assertJsonValidationErrors('client_company_id');
        $this->upload($this->csv($line), ['client_company_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('client_company_id');
        $this->upload($this->csv($line), ['client_company_id' => ''])->assertStatus(422)->assertJsonValidationErrors('client_company_id');

        $this->assertDatabaseCount('attendance_imports', 0);
        $this->assertDatabaseCount('attendance_dtr_periods', 0);
    }

    public function test_the_declared_coverage_is_checked_on_the_server(): void
    {
        $file = fn () => $this->csv(['Bio,Date,In,Out', '12,2026-09-07,08:00,17:00']);

        $this->upload($file(), ['period_from' => '', 'period_to' => ''])->assertStatus(422)->assertJsonValidationErrors(['period_from', 'period_to']);
        $this->upload($file(), ['period_from' => '2026-09-11', 'period_to' => '2026-09-07'])->assertStatus(422)->assertJsonValidationErrors('period_to');
        $this->upload($file(), ['period_from' => '09/07/2026'])->assertStatus(422)->assertJsonValidationErrors('period_from');
        $this->upload($file(), ['period_to' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors('period_to');
        $this->upload($file(), ['period_from' => '2026-07-01', 'period_to' => '2026-09-01'])->assertStatus(422)
            ->assertJsonPath('errors.period_to.0', 'A DTR file can cover at most 62 days; this one says 63. Split a longer DTR into parts.');

        // 62 days exactly is allowed.
        $this->upload($file(), ['period_from' => '2026-07-02', 'period_to' => '2026-09-01'])->assertCreated();
    }

    public function test_the_contents_have_to_match_the_name(): void
    {
        // A workbook renamed .csv, text renamed .xlsx, and a binary blob.
        $xlsx = $this->workbook('xlsx');
        $this->upload(UploadedFile::fake()->createWithContent('dtr.csv', file_get_contents($xlsx->getRealPath())))->assertStatus(422);
        $this->upload(UploadedFile::fake()->createWithContent('dtr.xlsx', "Bio,Date,In,Out\n12,2026-09-07,08:00,17:00\n"))->assertStatus(422);
        $this->upload(UploadedFile::fake()->createWithContent('dtr.csv', "Bio\0Date\0\x01\x02"))->assertStatus(422);

        $this->assertDatabaseCount('attendance_imports', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_xlsx_and_xls_workbooks_are_read(): void
    {
        foreach (['xlsx' => ['2026-09-07', '2026-09-07'], 'xls' => ['2026-09-08', '2026-09-08']] as $type => [$from, $to]) {
            $upload = $this->upload($this->workbook($type), ['period_from' => $from, 'period_to' => $to])->assertCreated();
            $this->assertSame(2, $upload->json('data.total_rows'), $type);
            $this->assertSame('Bio', $upload->json('data.columns.0.header'), $type);
        }
    }

    // ---------------------------------------------------------------- preview

    public function test_the_preview_totals_say_why_rows_will_not_be_imported(): void
    {
        $id = $this->upload($this->csv([
            'Bio,Date,In,Out',
            '12,2026-09-07,08:00,17:00',   // ready
            '12,2026-09-07,08:00,17:00',   // repeated
            '14,2026-09-07,08:00,17:00',   // ready
            '555,2026-09-08,08:00,17:00',  // no such employee
            '12,2026-09-20,08:00,17:00',   // outside the declared days
            '14,2026-09-08,8:75,17:00',    // not a time
            '14,07/09/2026,08:00,17:00',   // not the chosen date format
        ]))->json('data.id');

        $preview = $this->postJson("/api/v1/attendance/imports/{$id}/preview", self::MAPPING)->assertOk()->json('data');
        $summary = $preview['summary'];

        $this->assertSame(7, $summary['total_rows']);
        $this->assertSame(2, $summary['valid_rows']);
        $this->assertSame(4, $summary['invalid_rows']);
        $this->assertSame(1, $summary['unmatched_employees']);
        $this->assertSame(1, $summary['duplicates']);
        $this->assertSame(1, $summary['outside_period']);
        $this->assertSame(['from' => '2026-09-07', 'to' => '2026-09-20'], $summary['dates_in_file']);
        $this->assertEqualsCanonicalizing(
            ['unmatched_employee', 'invalid_date', 'invalid_time', 'outside_period'],
            array_column($summary['reasons'], 'status')
        );

        // Each problem row is listed with its line in the file.
        $rows = collect($preview['rows'])->keyBy('line');
        $this->assertSame('Outside the period of this file', $rows[6]['label']);
        $this->assertTrue($rows[5]['is_error']);
    }

    public function test_a_raw_punch_log_is_refused_rather_than_turned_into_days(): void
    {
        $id = $this->upload($this->csv([
            'Bio,Date,Punch,Type',
            '12,2026-09-07,08:00,IN',
            '12,2026-09-07,17:00,OUT',
            '14,2026-09-07,08:02,IN',
            '14,2026-09-07,17:01,OUT',
        ]))->json('data.id');

        // One time column cannot be both time-in and time-out.
        $this->postJson("/api/v1/attendance/imports/{$id}/preview", ['time_out_column' => 2] + self::MAPPING)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'raw punch log'));

        // Mapped as if the type column were a time-out, every day is refused
        // and the preview says what the file looks like.
        $preview = $this->postJson("/api/v1/attendance/imports/{$id}/preview", self::MAPPING)->assertOk()->json('data');
        $this->assertSame(0, $preview['ready']);

        $id2 = $this->upload($this->csv([
            'Bio,Date,In,Out',
            '12,2026-09-07,08:00,',
            '12,2026-09-07,,17:00',
            '14,2026-09-07,08:02,',
            '14,2026-09-07,,17:01',
        ], 'punches.csv'))->json('data.id');
        $preview = $this->postJson("/api/v1/attendance/imports/{$id2}/preview", self::MAPPING)->assertOk()->json('data');
        $this->assertSame(0, $preview['ready']);
        $this->assertSame(4, $preview['counts']['conflicting_in_file']);
        $this->assertTrue($preview['summary']['punch_log_suspected']);
    }

    // ------------------------------------------------------------ DTR periods

    public function test_an_import_files_the_dtr_under_a_period_but_does_not_complete_it(): void
    {
        $upload = $this->upload($this->csv(['Bio,Date,In,Out', '12,2026-09-07,08:00,17:00']))->assertCreated();
        $this->assertSame('received', $upload->json('data.dtr_period.status'));
        $this->assertSame('Received', $upload->json('data.dtr_period.status_label'));

        $id = $upload->json('data.id');
        $this->postJson("/api/v1/attendance/imports/{$id}/preview", self::MAPPING)->assertOk();
        $this->postJson("/api/v1/attendance/imports/{$id}/confirm", ['conflict_action' => 'skip'])->assertOk();

        $period = $this->periodOf($id);
        $this->assertSame('processing', $period['status']);

        // Neither the day without a row nor the employee without rows is a gap yet.
        $this->assertSame('awaiting_dtr', $this->days($this->reyes, '2026-09-08', '2026-09-08')['2026-09-08']['status']);
        $this->assertSame('awaiting_dtr', $this->days($this->cruz, '2026-09-07', '2026-09-07')['2026-09-07']['status']);

        // A second file for the same days goes under the same period, and
        // completes no more than the first did.
        $second = $this->import(['Bio,Date,In,Out', '14,2026-09-07,08:00,17:00'], 'second.csv');
        $this->assertSame($period['id'], $this->periodOf($second)['id']);
        $this->assertDatabaseCount('attendance_dtr_periods', 1);
        $this->assertSame('awaiting_dtr', $this->days($this->cruz, '2026-09-08', '2026-09-08')['2026-09-08']['status']);

        $detail = $this->getJson("/api/v1/attendance/dtr-periods/{$period['id']}")->assertOk()->json('data');
        $this->assertSame(['imported' => 2, 'drafts' => 0, 'discarded' => 0], $detail['files']);
        $this->assertSame(2, $detail['reconciliation']['totals']['employees_placed']);
        $this->assertSame(2, $detail['reconciliation']['totals']['records']);
        // Five weekdays each, two on file.
        $this->assertSame(8, $detail['reconciliation']['totals']['days_awaiting_dtr']);
    }

    public function test_hr_confirms_a_period_complete_with_the_gaps_acknowledged(): void
    {
        $id = $this->import(['Bio,Date,In,Out', '12,2026-09-07,08:00,17:00'], 'week.csv');
        $period = $this->periodOf($id)['id'];

        // The gaps have to be acknowledged, since each becomes a day to verify.
        $this->decide($period, ['action' => 'complete'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '9 scheduled day(s)'));
        $this->assertSame('processing', AttendanceDtrPeriod::find($period)->status);

        $done = $this->decide($period, ['action' => 'complete', 'acknowledge_gaps' => true, 'note' => 'Client HR confirmed by email: no other pages.'])
            ->assertOk()->json('data');
        $this->assertSame('complete', $done['status']);
        $this->assertSame('Complete for Period', $done['status_label']);
        $this->assertSame($this->hr->full_name, $done['status_by']);
        $this->assertSame(0, $done['reconciliation']['totals']['days_awaiting_dtr']);
        $this->assertSame(9, $done['reconciliation']['totals']['days_no_record']);

        // Now - and only now - a scheduled day with nothing on file is a gap to
        // verify, for someone in the file and someone not. Never an absence.
        $reyes = $this->days($this->reyes, '2026-09-08', '2026-09-08')['2026-09-08'];
        $this->assertSame(['no_record'], $reyes['discrepancies']);
        $this->assertSame('for_review', $reyes['review_status']);
        $this->assertSame(['no_record'], $this->days($this->cruz, '2026-09-07', '2026-09-07')['2026-09-07']['discrepancies']);
        $this->assertSame(9, $this->getJson('/api/v1/attendance/discrepancies?from=2026-09-07&to=2026-09-11&discrepancy=no_record')->json('meta.total'));

        // What HR was shown is kept with the decision, and audited.
        $event = AttendanceDtrPeriodEvent::where('attendance_dtr_period_id', $period)->where('to_status', 'complete')->sole();
        $this->assertSame(9, $event->details['days_awaiting_dtr']);
        $this->assertSame([$this->cruz->employee_number.' '.$this->cruz->full_name], $event->details['employees_without_records']);
        $this->assertTrue(AuditLog::where('record_type', 'AttendanceDtrPeriod')->where('record_id', $period)->where('action_type', 'confirm')->exists());

        // Confirming twice does nothing new.
        $this->decide($period, ['action' => 'complete', 'acknowledge_gaps' => true])->assertStatus(409);

        // Days outside the period are untouched.
        $this->assertSame('awaiting_dtr', $this->days($this->reyes, '2026-09-14', '2026-09-14')['2026-09-14']['status']);
    }

    public function test_a_period_cannot_be_confirmed_with_nothing_imported_or_a_file_still_being_checked(): void
    {
        $draft = $this->upload($this->csv(['Bio,Date,In,Out', '12,2026-09-07,08:00,17:00']))->json('data.id');
        $period = $this->periodOf($draft)['id'];

        $this->decide($period, ['action' => 'complete', 'acknowledge_gaps' => true])->assertStatus(409);

        $this->import(['Bio,Date,In,Out', '14,2026-09-07,08:00,17:00'], 'other.csv');
        $this->decide($period, ['action' => 'complete', 'acknowledge_gaps' => true])->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'still being checked'));

        $this->postJson("/api/v1/attendance/imports/{$draft}/discard")->assertOk();
        $this->decide($period, ['action' => 'complete', 'acknowledge_gaps' => true])->assertOk();
    }

    public function test_an_incomplete_submission_says_what_is_missing_and_more_files_reopen_the_check(): void
    {
        $id = $this->import(['Bio,Date,In,Out', '12,2026-09-07,08:00,17:00'], 'part-1.csv');
        $period = $this->periodOf($id)['id'];

        $this->decide($period, ['action' => 'incomplete'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->decide($period, ['action' => 'incomplete', 'note' => 'Cruz missing; client to send page 2.'])->assertOk()
            ->assertJsonPath('data.status', 'incomplete')
            ->assertJsonPath('data.status_label', 'Incomplete Submission')
            ->assertJsonPath('data.status_note', 'Cruz missing; client to send page 2.');

        // Still only awaiting the DTR.
        $this->assertSame('awaiting_dtr', $this->days($this->cruz, '2026-09-07', '2026-09-07')['2026-09-07']['status']);

        // The missing page arrives: back to processing, to be checked again.
        $this->import(['Bio,Date,In,Out', '14,2026-09-07,08:00,17:00'], 'part-2.csv');
        $this->assertSame('processing', AttendanceDtrPeriod::find($period)->status);
        $this->assertSame(['received', 'processing', 'incomplete'],
            AttendanceDtrPeriodEvent::where('attendance_dtr_period_id', $period)->orderBy('id')->pluck('from_status')->all());
    }

    public function test_a_confirmed_period_is_reopened_only_with_a_reason_before_another_file_goes_in(): void
    {
        $id = $this->import(['Bio,Date,In,Out', '12,2026-09-07,08:00,17:00'], 'week.csv');
        $period = $this->periodOf($id)['id'];
        $this->decide($period, ['action' => 'complete', 'acknowledge_gaps' => true])->assertOk();

        // A file inside the confirmed days waits for a reopen.
        $late = ['Bio,Date,In,Out', '14,2026-09-08,08:00,17:00'];
        $this->upload($this->csv($late, 'late.csv'), ['period_from' => '2026-09-08', 'period_to' => '2026-09-08'])->assertStatus(409);
        $this->assertSame([], array_diff(Storage::disk('documents')->allFiles(), [AttendanceImport::find($id)->file_path]),
            'The refused file is not kept.');

        $this->decide($period, ['action' => 'reopen'])->assertStatus(422);
        $this->decide($period, ['action' => 'reopen', 'note' => 'Client sent a late page for Cruz.'])->assertOk()
            ->assertJsonPath('data.status', 'processing');
        $this->assertSame('awaiting_dtr', $this->days($this->cruz, '2026-09-08', '2026-09-08')['2026-09-08']['status']);

        $this->upload($this->csv($late, 'late.csv'), ['period_from' => '2026-09-08', 'period_to' => '2026-09-08'])->assertCreated()
            ->assertJsonPath('data.dtr_period.id', $period);
    }

    public function test_a_period_with_a_gap_under_review_is_not_reopened(): void
    {
        $id = $this->import(['Bio,Date,In,Out', '12,2026-09-07,08:00,17:00'], 'week.csv');
        $period = $this->periodOf($id)['id'];
        $this->decide($period, ['action' => 'complete', 'acknowledge_gaps' => true])->assertOk();

        $this->postJson("/api/v1/employees/{$this->cruz->id}/attendance/days/2026-09-07/review", ['status' => 'under_verification'])->assertOk();

        $this->decide($period, ['action' => 'reopen', 'note' => 'Wrong client'])->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'being reviewed'));

        $this->postJson("/api/v1/employees/{$this->cruz->id}/attendance/days/2026-09-07/review", ['status' => 'closed', 'note' => 'Client confirmed he was on approved leave.'])->assertOk();
        $this->decide($period, ['action' => 'reopen', 'note' => 'Wrong client'])->assertOk();
    }

    public function test_the_same_days_are_one_period_and_a_wider_file_is_a_period_of_its_own(): void
    {
        $first = $this->import(['Bio,Date,In,Out', '12,2026-09-07,08:00,17:00'], 'a.csv');
        $inside = $this->import(['Bio,Date,In,Out', '12,2026-09-08,08:00,17:00'], 'b.csv', ['period_from' => '2026-09-08', 'period_to' => '2026-09-09']);
        $wider = $this->import(['Bio,Date,In,Out', '12,2026-09-14,08:00,17:00'], 'c.csv', ['period_from' => '2026-09-07', 'period_to' => '2026-09-18']);

        $this->assertSame($this->periodOf($first)['id'], $this->periodOf($inside)['id']);
        $this->assertNotSame($this->periodOf($first)['id'], $this->periodOf($wider)['id']);
        $this->assertSame(1, AttendanceDtrPeriod::whereDate('period_from', '2026-09-07')->whereDate('period_to', '2026-09-11')->count());

        $list = $this->getJson('/api/v1/attendance/dtr-periods')->assertOk()->json('data');
        $this->assertSame(['2026-09-18', '2026-09-11'], array_column($list, 'period_to'));
        $this->assertSame(['processing'], array_values(array_unique(array_column($list, 'status'))));
    }

    // ---------------------------------------------------------- who may do it

    public function test_only_staff_who_manage_attendance_handle_imports_and_periods(): void
    {
        $id = $this->import(['Bio,Date,In,Out', '12,2026-09-07,08:00,17:00'], 'week.csv');
        $period = $this->periodOf($id)['id'];

        $coordinator = User::factory()->create(['email' => 'coord@cdemanpower.local', 'user_type' => 'coordinator']);
        $coordinator->assignRole('coordinator');
        app(CoordinatorAssignmentService::class)->assign($coordinator, $this->client, $this->adminUser());

        $viewer = User::factory()->create(['email' => 'viewer@cdemanpower.local']);
        $viewer->assignRole(Role::create(['name' => 'attendance_viewer', 'guard_name' => 'web'])->givePermissionTo('attendance.view', 'clients.view'));

        $portal = User::factory()->create([
            'email' => 'employee@example.test', 'user_type' => 'employee',
            'applicant_id' => $this->reyes->applicant_id, 'employee_id' => $this->reyes->id,
        ]);
        $portal->assignRole('portal');

        foreach ([$coordinator, $viewer, $portal] as $user) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($user);

            $this->getJson('/api/v1/attendance/imports/options')->assertForbidden();
            $this->upload($this->csv(['Bio,Date,In,Out', '14,2026-09-08,08:00,17:00'], 'x.csv'))->assertForbidden();
            $this->getJson('/api/v1/attendance/dtr-periods')->assertForbidden();
            $this->getJson("/api/v1/attendance/dtr-periods/{$period}")->assertForbidden();
            $this->decide($period, ['action' => 'complete', 'acknowledge_gaps' => true])->assertForbidden();
        }

        $this->assertSame('processing', AttendanceDtrPeriod::find($period)->status);
        $this->assertDatabaseCount('attendance_imports', 1);
    }
}
