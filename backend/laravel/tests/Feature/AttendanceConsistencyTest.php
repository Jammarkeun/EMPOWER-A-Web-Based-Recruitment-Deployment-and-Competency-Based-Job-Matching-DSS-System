<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceReview;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\CoordinatorAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

/**
 * The employee's Attendance (DTR) tab and the central Attendance (DTR) module
 * are two views of one attendance system.
 *
 * They read the same records through the same comparison and the same review
 * status rule, so for the same employee and period they must say the same
 * thing - before and after a DTR is corrected, a review moves, a file is
 * imported, or a schedule changes - and they must refuse the same people.
 *
 * Dates are fixed so the weekdays are known: 7 September 2026 is a Monday.
 */
class AttendanceConsistencyTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const PERIOD = 'from=2026-09-01&to=2026-09-30';

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));

        $this->hr = $this->hrUser();
        Sanctum::actingAs($this->hr);
        $this->employee = $this->deployedEmployee($this->hr, deployment: ['biometric_number' => '501']);
        $this->dayShift($this->employee);
    }

    private function profile(?Employee $employee = null): array
    {
        $employee ??= $this->employee;

        return $this->getJson("/api/v1/employees/{$employee->id}/attendance?".self::PERIOD)->assertOk()->json('data');
    }

    private function profileDay(string $date): array
    {
        return collect($this->profile()['days'])->firstWhere('date', $date);
    }

    /** Every queue row for the employee, open and closed, keyed by date. */
    private function queue(string $status = 'all'): array
    {
        $statuses = $status === 'all' ? ['open', 'closed'] : [$status];
        $rows = [];

        foreach ($statuses as $s) {
            $response = $this->getJson('/api/v1/attendance/discrepancies?'.self::PERIOD."&employee_id={$this->employee->id}&status={$s}&per_page=100")->assertOk();

            foreach ($response->json('data') as $row) {
                $rows[$row['date']] = $row;
            }
        }

        return $rows;
    }

    private function review(string $date, string $status, ?string $note = null)
    {
        return $this->postJson("/api/v1/employees/{$this->employee->id}/attendance/days/{$date}/review", array_filter(['status' => $status, 'note' => $note]));
    }

    private function encodeWeek(): void
    {
        $this->encode($this->employee, [
            ['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00'],
            ['work_date' => '2026-09-08', 'time_in' => '08:20', 'time_out' => '17:00'],
            ['work_date' => '2026-09-09', 'time_in' => '08:00'],
            // 10 September: blank on a DTR declared complete.
            ['work_date' => '2026-09-11', 'time_in' => '08:00', 'time_out' => '16:30'],
        ], ['complete_from' => '2026-09-07', 'complete_to' => '2026-09-11'])->assertOk();
    }

    public function test_the_employee_tab_the_review_queue_and_the_workforce_summary_agree(): void
    {
        $this->encodeWeek();
        $this->review('2026-09-08', 'under_verification')->assertOk();
        $this->review('2026-09-11', 'closed', 'Client approved the early out')->assertOk();

        // Somebody else's late day must not leak into this employee's queue.
        $other = $this->deployedEmployee($this->hr);
        $this->dayShift($other);
        $this->encode($other, [['work_date' => '2026-09-08', 'time_in' => '09:00', 'time_out' => '17:00']])->assertOk();

        $profile = $this->profile();
        $queue = $this->queue();
        $inQueue = collect($profile['days'])->whereNotNull('review_status')->keyBy('date');

        $this->assertSame(['2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11'], $inQueue->keys()->sort()->values()->all());
        $this->assertSame($inQueue->keys()->sort()->values()->all(), collect(array_keys($queue))->sort()->values()->all());

        foreach ($queue as $date => $row) {
            $day = $inQueue[$date];
            $this->assertSame($this->employee->id, $row['employee']['id']);

            foreach (['status', 'discrepancies', 'late_minutes', 'early_departure_minutes', 'time_in', 'time_out', 'review_status', 'still_flagged', 'schedule'] as $field) {
                $this->assertSame($day[$field], $row[$field], "{$field} on {$date}");
            }
        }

        $this->assertSame('under_verification', $queue['2026-09-08']['review_status']);
        $this->assertSame('for_review', $queue['2026-09-09']['review_status']);
        $this->assertSame('closed', $queue['2026-09-11']['review_status']);

        // The counts the tab shows are the counts the queue shows.
        $meta = $this->getJson('/api/v1/attendance/discrepancies?'.self::PERIOD."&employee_id={$this->employee->id}")->json('meta');
        foreach (array_keys(AttendanceReview::LABELS) as $status) {
            $this->assertSame($meta['status_counts'][$status], $profile['review_counts'][$status], $status);
        }
        $this->assertSame(3, $profile['review_counts']['open']);
        $this->assertSame(3, $meta['total']);

        // The summary cards are the day rows, counted.
        $days = collect($profile['days']);
        $summary = $profile['summary'];
        $this->assertSame($days->where('status', 'discrepancy')->count(), $summary['discrepancy_days']);
        $this->assertSame($days->where('status', 'on_schedule')->count(), $summary['on_schedule_days']);
        $this->assertSame($days->filter(fn ($d) => in_array('late', $d['discrepancies'], true))->count(), $summary['late_days']);
        $this->assertSame($days->sum('late_minutes'), $summary['late_minutes']);
        $this->assertSame($days->sum('early_departure_minutes'), $summary['early_departure_minutes']);
        $this->assertSame($days->filter(fn ($d) => in_array('no_record', $d['discrepancies'], true))->count(), $summary['no_record_days']);
        $this->assertSame($days->where('status', 'awaiting_dtr')->count(), $summary['awaiting_dtr_days']);

        // And the workforce summary says the same for this employee.
        $row = collect($this->getJson('/api/v1/attendance?'.self::PERIOD)->json('data'))->firstWhere('employee_id', $this->employee->id);
        $this->assertSame($summary, $row['summary']);

        // The day the queue opens is the day the tab shows.
        $detail = $this->getJson("/api/v1/employees/{$this->employee->id}/attendance/days/2026-09-08")->json('data.day');
        foreach (['status', 'discrepancies', 'late_minutes', 'review_status', 'schedule'] as $field) {
            $this->assertSame($inQueue['2026-09-08'][$field], $detail[$field], $field);
        }
    }

    public function test_a_dtr_correction_reads_the_same_in_both_views(): void
    {
        $this->encodeWeek();
        $this->review('2026-09-08', 'under_verification')->assertOk();

        $this->encode($this->employee, [
            ['work_date' => '2026-09-08', 'time_in' => '08:00', 'time_out' => '17:00'],
            ['work_date' => '2026-09-09', 'time_in' => '08:00', 'time_out' => '17:00'],
        ], ['reason' => 'Client sent the signed DTR'])->assertOk();

        // The reviewed day stays in both views until somebody closes it.
        $day = $this->profileDay('2026-09-08');
        $row = $this->queue()['2026-09-08'];
        $this->assertSame('on_schedule', $day['status']);
        $this->assertFalse($day['still_flagged']);
        $this->assertSame('under_verification', $day['review_status']);
        $this->assertSame([$day['status'], $day['still_flagged'], $day['review_status']], [$row['status'], $row['still_flagged'], $row['review_status']]);

        // A corrected day nobody had reviewed simply leaves both.
        $this->assertNull($this->profileDay('2026-09-09')['review_status']);
        $this->assertArrayNotHasKey('2026-09-09', $this->queue());
        $this->assertSame(1, AttendanceRecord::where('employee_id', $this->employee->id)->whereDate('work_date', '2026-09-09')->count());
    }

    public function test_review_moves_read_the_same_everywhere(): void
    {
        $this->encodeWeek();
        $this->review('2026-09-08', 'verified', 'Supervisor confirmed')->assertOk();

        $this->assertSame('verified', $this->profileDay('2026-09-08')['review_status']);
        $this->assertArrayHasKey('2026-09-08', $this->queue('verified'));
        $this->assertArrayNotHasKey('2026-09-08', $this->queue('for_review'));

        $detail = $this->getJson("/api/v1/employees/{$this->employee->id}/attendance/days/2026-09-08")->json('data');
        $this->assertSame('verified', $detail['day']['review_status']);
        $this->assertSame(AttendanceReview::TRANSITIONS['verified'], $detail['transitions']);

        // A day that is not in the queue offers nothing to move.
        $this->assertSame([], $this->getJson("/api/v1/employees/{$this->employee->id}/attendance/days/2026-09-07")->json('data.transitions'));
        $this->assertSame(1, AttendanceReview::count());
    }

    public function test_importing_days_already_on_file_creates_no_duplicates(): void
    {
        Storage::fake('documents');
        $company = $this->employee->current_client_company_id;
        $import = function (array $lines, string $name) use ($company) {
            $id = $this->post('/api/v1/attendance/imports', [
                'file' => UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n"),
                'client_company_id' => $company,
                'period_from' => '2026-09-07',
                'period_to' => '2026-09-11',
            ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
            $preview = $this->postJson("/api/v1/attendance/imports/{$id}/preview", [
                'has_header' => true, 'identifier_type' => 'biometric_number', 'identifier_column' => 0,
                'date_column' => 1, 'date_format' => 'Y-m-d', 'time_in_column' => 2, 'time_out_column' => 3,
            ])->assertOk()->json('data.counts');
            $this->postJson("/api/v1/attendance/imports/{$id}/confirm", ['conflict_action' => 'skip'])->assertOk();

            return $preview;
        };

        $this->encode($this->employee, [['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00']])->assertOk();

        $first = $import(['Bio,Date,In,Out', '501,2026-09-07,08:00,17:00', '501,2026-09-08,08:00,17:00'], 'first.csv');
        $second = $import(['Bio,Date,In,Out', '501,2026-09-08,08:00,17:00', '501,2026-09-09,08:00,17:00'], 'second.csv');

        $this->assertSame([1, 1], [$first['unchanged'], $first['new']]);
        $this->assertSame([1, 1], [$second['unchanged'], $second['new']]);
        $this->assertSame(3, AttendanceRecord::where('employee_id', $this->employee->id)->count());

        $days = collect($this->profile()['days'])->keyBy('date');
        $this->assertSame('manual', $days['2026-09-07']['source']);
        $this->assertSame('import', $days['2026-09-08']['source']);
        $this->assertSame('on_schedule', $days['2026-09-09']['status']);
        // The files covered the 10th and 11th with no row: flagged in both views.
        $this->assertSame(['no_record'], $days['2026-09-10']['discrepancies']);
        $this->assertSame(['2026-09-10', '2026-09-11'], collect(array_keys($this->queue()))->sort()->values()->all());
    }

    public function test_a_day_is_compared_with_the_schedule_of_its_own_date(): void
    {
        $this->encode($this->employee, [['work_date' => '2026-09-08', 'time_in' => '08:30', 'time_out' => '17:00']])->assertOk();
        $this->review('2026-09-08', 'under_verification')->assertOk();

        // A new schedule from the 14th leaves the 8th compared with 08:00.
        $this->postJson("/api/v1/employees/{$this->employee->id}/schedules", [
            'effective_from' => '2026-09-14', 'work_days' => [1, 2, 3, 4, 5], 'shift_start' => '09:00', 'shift_end' => '18:00',
        ])->assertCreated();
        $this->encode($this->employee, [['work_date' => '2026-09-15', 'time_in' => '08:55', 'time_out' => '18:00']])->assertOk();

        $this->assertSame(['08:00', 30], [$this->profileDay('2026-09-08')['schedule']['start'], $this->profileDay('2026-09-08')['late_minutes']]);
        $this->assertSame(['08:00', 30], [$this->queue()['2026-09-08']['schedule']['start'], $this->queue()['2026-09-08']['late_minutes']]);
        $this->assertSame(['09:00', 'on_schedule'], [$this->profileDay('2026-09-15')['schedule']['start'], $this->profileDay('2026-09-15')['status']]);

        // Correcting the old schedule does change its days - deliberately, with
        // a reason, kept in its history - and the review keeps what was shown.
        $first = EmployeeSchedule::orderBy('effective_from')->first();
        $this->patchJson("/api/v1/employees/{$this->employee->id}/schedules/{$first->id}", [
            'work_days' => [1, 2, 3, 4, 5], 'shift_start' => '08:30', 'shift_end' => '17:00',
            'reason' => 'Client confirmed the line starts at 08:30',
        ])->assertOk();

        $profile = $this->profile();
        $this->assertSame('on_schedule', collect($profile['days'])->firstWhere('date', '2026-09-08')['status']);
        $this->assertSame('08:00', $profile['schedule_changes'][0]['before']['shift_start']);
        $this->assertSame('Client confirmed the line starts at 08:30', $profile['schedule_changes'][0]['reason']);

        $events = $this->getJson("/api/v1/employees/{$this->employee->id}/attendance/days/2026-09-08")->json('data.review_events');
        $this->assertSame(30, $events[0]['snapshot']['late_minutes']);
        $this->assertSame('08:00', $events[0]['snapshot']['schedule']['start']);
    }

    public function test_both_views_refuse_the_same_people(): void
    {
        $this->encodeWeek();
        $other = $this->deployedEmployee($this->hr);

        // The coordinator of this employee's client: the same numbers, read-only.
        $coordinator = User::factory()->create(['email' => 'coord.'.uniqid().'@cdemanpower.local', 'user_type' => 'coordinator']);
        $coordinator->assignRole('coordinator');
        app(CoordinatorAssignmentService::class)->assign($coordinator, $this->employee->currentCompany, $this->adminUser());
        $hrSummary = $this->profile()['summary'];

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($coordinator);

        $this->assertSame($hrSummary, $this->getJson("/api/v1/coordinator/employees/{$this->employee->id}")->assertOk()->json('data.attendance.summary'));
        $this->getJson("/api/v1/coordinator/employees/{$other->id}")->assertForbidden();
        foreach ([
            "/api/v1/attendance/discrepancies?employee_id={$this->employee->id}",
            "/api/v1/attendance?client_company_id={$this->employee->current_client_company_id}",
            "/api/v1/employees/{$this->employee->id}/attendance",
            "/api/v1/employees/{$this->employee->id}/attendance/days/2026-09-08",
        ] as $uri) {
            $this->getJson($uri)->assertForbidden();
        }
        $this->review('2026-09-08', 'under_verification')->assertForbidden();

        // Someone who may view attendance only: both views open, nothing changes,
        // and the employee profile stays closed, as the links on screen assume.
        $role = Role::create(['name' => 'attendance-viewer', 'guard_name' => 'web']);
        $role->givePermissionTo('attendance.view');
        $viewer = User::factory()->create(['email' => 'viewer.'.uniqid().'@cdemanpower.local', 'user_type' => 'hr']);
        $viewer->assignRole($role);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/attendance/discrepancies?'.self::PERIOD."&employee_id={$this->employee->id}")->assertOk();
        $this->getJson("/api/v1/employees/{$this->employee->id}/attendance")->assertOk();
        $this->getJson("/api/v1/employees/{$this->employee->id}")->assertForbidden();
        $this->review('2026-09-08', 'under_verification')->assertForbidden();
        $this->encode($this->employee, [['work_date' => '2026-09-10', 'time_in' => '08:00', 'time_out' => '17:00']])->assertForbidden();
        $this->getJson('/api/v1/attendance/imports')->assertForbidden();

        // A made-up employee in the filter is refused, not answered with an empty list.
        $this->getJson('/api/v1/attendance/discrepancies?employee_id=999999')->assertStatus(422);
        $this->assertSame(0, AttendanceReview::count());
    }
}
