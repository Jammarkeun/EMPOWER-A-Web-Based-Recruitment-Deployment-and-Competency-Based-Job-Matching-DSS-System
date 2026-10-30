<?php

namespace Tests\Feature;

use App\Models\AttendanceRecordRevision;
use App\Models\EmployeeSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

/**
 * "DTR to compare with schedule."
 *
 * The review panel's recommendation. HR keeps the schedule a worker was given;
 * the DTR the client's timekeeping produced is typed in or imported; the system
 * sets one beside the other and points at where they differ, for a person to
 * review. It never prices a minute, and it never turns missing data into an
 * absence: the DTR stays in the client's biometrics (CDE, 26 October 2026), so
 * nothing on file usually means only that the DTR has not been brought in.
 *
 * Dates are fixed so the weekdays are known. 7 September 2026 is a Monday.
 */
class AttendanceTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    }

    public function test_the_dtr_is_compared_with_the_schedule_day_by_day(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);
        $this->dayShift($employee);

        $this->encode($employee, [
            ['work_date' => '2026-09-07', 'time_in' => '07:55', 'time_out' => '17:00'],   // on time
            ['work_date' => '2026-09-08', 'time_in' => '08:25', 'time_out' => '17:00'],   // late 25
            ['work_date' => '2026-09-09', 'time_in' => '08:00', 'time_out' => '16:30'],   // early departure 30
            ['work_date' => '2026-09-10', 'time_in' => '08:00', 'time_out' => '18:15'],   // 75 after the end
            // 11 September: scheduled, blank on a DTR declared complete
            ['work_date' => '2026-09-12', 'time_in' => '08:00', 'time_out' => '12:00'],   // Saturday
            ['work_date' => '2026-09-14', 'time_in' => '08:00'],                          // no time-out
        ], ['complete_from' => '2026-09-07', 'complete_to' => '2026-09-14'])
            ->assertOk()->assertJsonPath('data.saved', 6);

        $days = $this->days($employee, '2026-09-07', '2026-09-14');

        $this->assertSame('on_schedule', $days['2026-09-07']['status']);
        $this->assertSame([], $days['2026-09-07']['discrepancies']);

        $this->assertSame(['late'], $days['2026-09-08']['discrepancies']);
        $this->assertSame(25, $days['2026-09-08']['late_minutes']);

        $this->assertSame(['early_departure'], $days['2026-09-09']['discrepancies']);
        $this->assertSame(30, $days['2026-09-09']['early_departure_minutes']);

        // Time after the end is shown, never treated as overtime or a shortfall.
        $this->assertSame('on_schedule', $days['2026-09-10']['status']);
        $this->assertSame(75, $days['2026-09-10']['minutes_after_end']);

        // Blank on a DTR known to be complete: a gap to look into, not an absence.
        $this->assertSame(['no_record'], $days['2026-09-11']['discrepancies']);
        $this->assertSame(['outside_schedule'], $days['2026-09-12']['discrepancies']);
        $this->assertSame('rest_day', $days['2026-09-13']['status']);
        $this->assertSame(['missing_time_out'], $days['2026-09-14']['discrepancies']);

        $summary = $this->getJson("/api/v1/employees/{$employee->id}/attendance?from=2026-09-07&to=2026-09-14")
            ->json('data.summary');

        $this->assertSame(6, $summary['scheduled_days']);
        $this->assertSame(6, $summary['compared_days']);
        $this->assertSame(2, $summary['on_schedule_days']);
        $this->assertSame(1, $summary['late_days']);
        $this->assertSame(25, $summary['late_minutes']);
        $this->assertSame(30, $summary['early_departure_minutes']);
        $this->assertSame(75, $summary['minutes_after_end']);
        $this->assertSame(1, $summary['missing_punch_days']);
        $this->assertSame(1, $summary['no_record_days']);
        $this->assertSame(1, $summary['outside_schedule_days']);
        $this->assertSame('complete', $summary['data_status']);
        $this->assertArrayNotHasKey('days_absent', $summary);
    }

    public function test_nothing_on_file_is_awaiting_the_dtr_never_an_absence(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);
        $this->dayShift($employee);

        $response = $this->getJson("/api/v1/employees/{$employee->id}/attendance?from=2026-09-07&to=2026-09-11")->assertOk();

        foreach ($response->json('data.days') as $day) {
            $this->assertSame('awaiting_dtr', $day['status']);
            $this->assertSame([], $day['discrepancies']);
        }

        $this->assertSame('no_dtr', $response->json('data.summary.data_status'));
        $this->assertSame(5, $response->json('data.summary.awaiting_dtr_days'));
        $this->assertSame(0, $response->json('data.summary.discrepancy_days'));
        $this->assertStringNotContainsString('absent', json_encode($response->json('data')));

        // Nothing for the review queue either.
        $this->getJson('/api/v1/attendance/discrepancies?from=2026-09-07&to=2026-09-11')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.insufficient_data.no_dtr', 1);

        // A single day on file does not make the rest of the month complete.
        $this->encode($employee, [['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00']])->assertOk();
        $days = $this->days($employee, '2026-09-07', '2026-09-11');
        $this->assertSame('on_schedule', $days['2026-09-07']['status']);
        $this->assertSame('awaiting_dtr', $days['2026-09-08']['status']);
    }

    public function test_a_night_shift_runs_past_midnight(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);

        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => '2026-09-01',
            'work_days' => [1, 2, 3, 4, 5],
            'shift_start' => '22:00',
            'shift_end' => '06:00',
        ])->assertCreated()->assertJsonPath('data.crosses_midnight', true);

        $this->encode($employee, [
            ['work_date' => '2026-09-07', 'time_in' => '22:05', 'time_out' => '06:00'],
            ['work_date' => '2026-09-08', 'time_in' => '21:58', 'time_out' => '05:30'],
            ['work_date' => '2026-09-09', 'time_out' => '05:59'],
        ])->assertOk();

        $days = $this->days($employee, '2026-09-07', '2026-09-09');

        $this->assertSame(5, $days['2026-09-07']['late_minutes']);
        $this->assertTrue($days['2026-09-07']['time_out_next_day']);
        $this->assertSame(['late'], $days['2026-09-07']['discrepancies']);

        $this->assertSame(['early_departure'], $days['2026-09-08']['discrepancies']);
        $this->assertSame(30, $days['2026-09-08']['early_departure_minutes']);

        // A lone time-out before the shift's start is the morning after.
        $this->assertTrue($days['2026-09-09']['time_out_next_day']);
        $this->assertSame(['missing_time_in', 'early_departure'], $days['2026-09-09']['discrepancies']);
    }

    public function test_times_that_never_meet_the_shift_are_not_measured_against_it(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);
        $this->dayShift($employee);

        // An evening shift keyed against a day-shift schedule.
        $this->encode($employee, [['work_date' => '2026-09-07', 'time_in' => '17:30', 'time_out' => '23:00']])->assertOk();

        $day = $this->days($employee, '2026-09-07', '2026-09-07')['2026-09-07'];

        $this->assertSame(['shift_mismatch'], $day['discrepancies']);
        $this->assertSame(0, $day['late_minutes']);
        $this->assertSame('Does not match the scheduled shift', $day['discrepancy_labels'][0]);
    }

    public function test_the_grace_period_is_a_setting_and_defaults_to_none(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);
        $this->dayShift($employee);

        $this->encode($employee, [['work_date' => '2026-09-07', 'time_in' => '08:08', 'time_out' => '17:00']]);

        $this->assertSame(8, $this->days($employee, '2026-09-07', '2026-09-07')['2026-09-07']['late_minutes']);

        config(['empower.attendance.late_grace_minutes' => 10]);

        $day = $this->days($employee, '2026-09-07', '2026-09-07')['2026-09-07'];
        $this->assertSame(0, $day['late_minutes']);
        $this->assertSame('on_schedule', $day['status']);
    }

    public function test_a_schedule_belongs_to_the_placement_and_a_change_keeps_the_old_one(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);

        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => '2026-09-01',
            'work_days' => [1, 2, 3, 4, 5],
            'shift_start' => '08:00',
            'shift_end' => '17:00',
            'break_start' => '12:00',
            'break_minutes' => 60,
        ])->assertCreated()->assertJsonPath('data.break_minutes', 60);

        $schedule = EmployeeSchedule::first();
        $this->assertSame($employee->activeDeployment()->id, $schedule->deployment_id);
        $this->assertSame($employee->current_client_company_id, $schedule->client_company_id);
        $this->assertSame($employee->current_department_id, $schedule->client_department_id);

        // From the 14th, a Tuesday-to-Saturday afternoon shift.
        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => '2026-09-14',
            'work_days' => [2, 3, 4, 5, 6],
            'shift_start' => '13:00',
            'shift_end' => '22:00',
        ])->assertCreated();

        $schedules = $this->getJson("/api/v1/employees/{$employee->id}/attendance")->json('data.schedules');
        $this->assertCount(2, $schedules);
        $this->assertSame('2026-09-13', $schedules[1]['effective_to']);

        $days = $this->days($employee, '2026-09-07', '2026-09-15');
        $this->assertSame('08:00', $days['2026-09-07']['schedule']['start']);
        $this->assertSame('rest_day', $days['2026-09-14']['status']);
        $this->assertSame('13:00', $days['2026-09-15']['schedule']['start']);

        // A schedule cannot be slipped in before one that already exists, nor
        // start before the worker was placed.
        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => '2026-09-10', 'work_days' => [1], 'shift_start' => '08:00', 'shift_end' => '17:00',
        ])->assertStatus(400);
        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => '2026-08-01', 'work_days' => [1], 'shift_start' => '08:00', 'shift_end' => '17:00',
        ])->assertStatus(400);

        // A break as long as the shift is a typing mistake.
        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => '2026-09-21', 'work_days' => [1], 'shift_start' => '08:00', 'shift_end' => '09:00',
            'break_start' => '08:00', 'break_minutes' => 60,
        ])->assertStatus(422);
    }

    public function test_a_wrong_schedule_is_corrected_or_voided_with_its_history_kept(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);
        $this->dayShift($employee);

        $first = EmployeeSchedule::first();

        // A typo in the shift: corrected in place, with a reason.
        $this->patchJson("/api/v1/employees/{$employee->id}/schedules/{$first->id}", [
            'work_days' => [1, 2, 3, 4, 5], 'shift_start' => '07:00', 'shift_end' => '16:00',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->patchJson("/api/v1/employees/{$employee->id}/schedules/{$first->id}", [
            'work_days' => [1, 2, 3, 4, 5], 'shift_start' => '07:00', 'shift_end' => '16:00',
            'reason' => 'Client sheet says the line starts at 7',
        ])->assertOk()->assertJsonPath('data.shift_start', '07:00');

        $changes = $this->getJson("/api/v1/employees/{$employee->id}/attendance")->json('data.schedule_changes');
        $this->assertSame('08:00', $changes[0]['before']['shift_start']);
        $this->assertSame('07:00', $changes[0]['after']['shift_start']);
        $this->assertSame('Client sheet says the line starts at 7', $changes[0]['reason']);

        // A second schedule entered by mistake: voided, and the first runs on.
        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => '2026-09-14', 'work_days' => [6, 7], 'shift_start' => '08:00', 'shift_end' => '17:00',
        ])->assertCreated();
        $mistake = EmployeeSchedule::latest('id')->first();

        $this->postJson("/api/v1/employees/{$employee->id}/schedules/{$first->id}/void", ['reason' => 'Not the latest'])
            ->assertStatus(400);
        $this->postJson("/api/v1/employees/{$employee->id}/schedules/{$mistake->id}/void", ['reason' => 'Entered for the wrong worker'])
            ->assertOk()->assertJsonPath('data.period_status', 'voided');

        $this->assertNull($first->fresh()->effective_to);
        $this->assertSame('07:00', $this->days($employee, '2026-09-15', '2026-09-15')['2026-09-15']['schedule']['start']);
        $this->assertDatabaseCount('employee_schedules', 2);

        // A schedule from another employee's record is not reachable here.
        $other = $this->deployedEmployee($hr);
        $this->patchJson("/api/v1/employees/{$other->id}/schedules/{$first->id}", [
            'work_days' => [1], 'shift_start' => '08:00', 'shift_end' => '17:00', 'reason' => 'Should not work',
        ])->assertNotFound();
    }

    public function test_changing_the_dtr_needs_a_reason_and_keeps_every_revision(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);
        $this->dayShift($employee);

        $this->encode($employee, [['work_date' => '2026-09-07', 'time_in' => '08:30', 'time_out' => '17:00']])->assertOk();

        // The same values again are not a change, and need no reason.
        $this->encode($employee, [['work_date' => '2026-09-07', 'time_in' => '08:30', 'time_out' => '17:00']])
            ->assertOk()->assertJsonPath('data.unchanged', 1);

        // A different time on a day already on file needs a reason.
        $this->encode($employee, [['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00']])
            ->assertStatus(422);
        $this->encode($employee, [['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00']], [
            'reason' => 'Mistyped from the client sheet',
        ])->assertOk();

        // Clearing the day keeps what it held.
        $this->encode($employee, [['work_date' => '2026-09-07', 'time_in' => null, 'time_out' => null]], [
            'reason' => 'Entered against the wrong employee',
        ])->assertOk()->assertJsonPath('data.cleared', 1);

        $this->assertDatabaseCount('attendance_records', 0);
        $revisions = AttendanceRecordRevision::orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'cleared'], $revisions->pluck('action')->all());
        $this->assertSame('08:30', $revisions[1]->old_time_in->format('H:i'));
        $this->assertSame('08:00', $revisions[1]->new_time_in->format('H:i'));
        $this->assertSame('08:00', $revisions[2]->old_time_in->format('H:i'));
        $this->assertNull($revisions[2]->new_time_in);
        $this->assertSame('Entered against the wrong employee', $revisions[2]->reason);

        $day = $this->getJson("/api/v1/employees/{$employee->id}/attendance/days/2026-09-07")->assertOk()->json('data');
        $this->assertCount(3, $day['revisions']);
        $this->assertSame('cleared', $day['revisions'][0]['action']);
    }

    public function test_only_days_actually_worked_within_employment_can_be_recorded(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr, '2026-09-10');

        $this->encode($employee, [['work_date' => '2026-10-02', 'time_in' => '08:00']])->assertStatus(400);
        $this->encode($employee, [['work_date' => '2026-09-01', 'time_in' => '08:00']])->assertStatus(400);
        $this->encode($employee, [['work_date' => '2026-09-10', 'time_in' => '8:00']])->assertStatus(422);

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_days_after_a_separation_are_outside_employment(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);
        $this->dayShift($employee);

        $id = $this->postJson("/api/v1/employees/{$employee->id}/resignations", [
            'reason' => 'Personal',
            'filing_date' => '2026-09-01',
            'exit_date' => '2026-09-15',
        ])->json('data.id');
        $this->putJson("/api/v1/employees/{$employee->id}/resignations/{$id}", ['clearance_status' => 'cleared']);
        $this->postJson("/api/v1/employees/{$employee->id}/resignations/{$id}/complete", ['confirm' => true])->assertOk();

        $days = $this->days($employee->fresh(), '2026-09-14', '2026-09-17');

        $this->assertSame('awaiting_dtr', $days['2026-09-15']['status']);
        $this->assertSame('outside_employment', $days['2026-09-16']['status']);

        // A late-arriving DTR for the last days can still be encoded.
        $this->encode($employee, [['work_date' => '2026-09-15', 'time_in' => '08:00', 'time_out' => '17:00']])->assertOk();
        $this->encode($employee, [['work_date' => '2026-09-16', 'time_in' => '08:00', 'time_out' => '17:00']])->assertStatus(400);
    }

    public function test_known_leave_and_holidays_are_not_gaps_in_the_dtr(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);
        $colleague = $this->deployedEmployee($hr, company: $employee->currentCompany);
        $elsewhere = $this->deployedEmployee($hr);
        foreach ([$employee, $colleague, $elsewhere] as $e) {
            $this->dayShift($e);
            $this->encode($e, [['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00']], [
                'complete_from' => '2026-09-07', 'complete_to' => '2026-09-11',
            ])->assertOk();
        }

        $leave = $this->postJson('/api/v1/attendance/exceptions', [
            'scope' => 'employee', 'employee_id' => $employee->id, 'exception_type' => 'approved_leave',
            'date_from' => '2026-09-08', 'date_to' => '2026-09-09', 'remarks' => 'Leave form on file',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/attendance/exceptions', [
            'scope' => 'client', 'client_company_id' => $employee->current_client_company_id,
            'exception_type' => 'holiday', 'date_from' => '2026-09-10', 'date_to' => '2026-09-10',
        ])->assertCreated();

        // "Other" needs saying what it is.
        $this->postJson('/api/v1/attendance/exceptions', [
            'scope' => 'employee', 'employee_id' => $employee->id, 'exception_type' => 'other',
            'date_from' => '2026-09-11', 'date_to' => '2026-09-11',
        ])->assertStatus(422);

        $days = $this->days($employee, '2026-09-08', '2026-09-11');
        $this->assertSame('excused', $days['2026-09-08']['status']);
        $this->assertSame('Approved leave', $days['2026-09-08']['exception']['label']);
        $this->assertSame('excused', $days['2026-09-10']['status']);
        $this->assertSame(['no_record'], $days['2026-09-11']['discrepancies']);

        $this->assertSame('excused', $this->days($colleague, '2026-09-10', '2026-09-10')['2026-09-10']['status']);
        $this->assertSame(['no_record'], $this->days($elsewhere, '2026-09-10', '2026-09-10')['2026-09-10']['discrepancies']);

        // Worked on a day of leave: attendance outside the schedule, to look at.
        $this->encode($employee, [['work_date' => '2026-09-08', 'time_in' => '08:00', 'time_out' => '17:00']])->assertOk();
        $this->assertSame(['outside_schedule'], $this->days($employee, '2026-09-08', '2026-09-08')['2026-09-08']['discrepancies']);

        // Withdrawn, the leave no longer applies, but it stays on file.
        $this->postJson("/api/v1/attendance/exceptions/{$leave}/void", ['reason' => 'Leave was cancelled'])->assertOk();
        $this->assertSame(['no_record'], $this->days($employee, '2026-09-09', '2026-09-09')['2026-09-09']['discrepancies']);
        $this->assertDatabaseCount('attendance_exceptions', 2);
        $this->getJson('/api/v1/attendance/exceptions?include_withdrawn=1')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_workforce_summary_lists_each_employees_discrepancies(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $clean = $this->deployedEmployee($hr);
        $this->dayShift($clean);
        $late = $this->deployedEmployee($hr);
        $this->dayShift($late);

        $this->encode($clean, [['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00']]);
        $this->encode($late, [['work_date' => '2026-09-07', 'time_in' => '08:40', 'time_out' => '17:00']]);

        $rows = collect($this->getJson('/api/v1/attendance?from=2026-09-07&to=2026-09-07')->assertOk()->json('data'))
            ->keyBy('employee_id');

        $this->assertSame(0, $rows[$clean->id]['summary']['discrepancy_days']);
        $this->assertSame(40, $rows[$late->id]['summary']['late_minutes']);

        $only = $this->getJson('/api/v1/attendance?from=2026-09-07&to=2026-09-07&only_discrepancies=1')->json('data');
        $this->assertCount(1, $only);
        $this->assertSame($late->id, $only[0]['employee_id']);

        $byClient = $this->getJson("/api/v1/attendance?from=2026-09-07&to=2026-09-07&client_company_id={$clean->current_client_company_id}")->json('data');
        $this->assertSame([$clean->id], array_column($byClient, 'employee_id'));

        $this->getJson('/api/v1/attendance?from=2026-07-01&to=2026-09-30')->assertStatus(422);
    }

    public function test_attendance_is_staff_work(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);

        $user = User::factory()->create([
            'email' => 'employee'.$employee->id.'@example.test',
            'user_type' => 'employee',
            'applicant_id' => $employee->applicant_id,
            'employee_id' => $employee->id,
        ]);
        $user->assignRole('portal');
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/employees/{$employee->id}/attendance")->assertForbidden();
        $this->getJson("/api/v1/employees/{$employee->id}/attendance/days/2026-09-07")->assertForbidden();
        $this->getJson('/api/v1/attendance')->assertForbidden();
        $this->getJson('/api/v1/attendance/discrepancies')->assertForbidden();
        $this->getJson('/api/v1/attendance/imports')->assertForbidden();
        $this->getJson('/api/v1/attendance/exceptions')->assertForbidden();
        $this->encode($employee, [['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00']])->assertForbidden();
        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => '2026-09-01', 'work_days' => [1], 'shift_start' => '08:00', 'shift_end' => '17:00',
        ])->assertForbidden();
        $this->postJson('/api/v1/attendance/exceptions', [
            'scope' => 'employee', 'employee_id' => $employee->id, 'exception_type' => 'approved_leave',
            'date_from' => '2026-09-08', 'date_to' => '2026-09-08',
        ])->assertForbidden();

        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('employee_schedules', 0);
        $this->assertDatabaseCount('attendance_exceptions', 0);
    }

    public function test_hr_and_administrators_hold_the_attendance_permissions(): void
    {
        foreach ([$this->hrUser(), $this->adminUser()] as $user) {
            $this->assertTrue($user->can('attendance.view'));
            $this->assertTrue($user->can('attendance.manage'));
            $this->assertTrue($user->can('attendance.review'));
        }
    }
}
