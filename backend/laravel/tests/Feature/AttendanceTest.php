<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * "DTR to compare with schedule."
 *
 * The review panel's recommendation. HR encodes the schedule a worker was given
 * and the DTR the client's timekeeping produced; the system sets one beside the
 * other and reports where they differ. It never prices a minute: nothing here
 * is payroll.
 *
 * Dates are fixed so the weekdays are known. 7 September 2026 is a Monday.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    }

    private function deployedEmployee(User $hr, string $on = '2026-09-01'): Employee
    {
        $department = $this->department($this->clientCompany($hr, ['company_name' => 'Client '.uniqid()]), $hr);
        $request = $this->jobRequest($department, $hr, ['workers_needed' => 10]);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return app(DeploymentService::class)->deploy($applicant, $request, ['deployment_date' => $on], $hr)->employee;
    }

    private function dayShift(Employee $employee, string $from = '2026-09-01'): void
    {
        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => $from,
            'work_days' => [1, 2, 3, 4, 5],
            'shift_start' => '08:00',
            'shift_end' => '17:00',
        ])->assertCreated();
    }

    private function days(Employee $employee, string $from, string $to): array
    {
        return collect(
            $this->getJson("/api/v1/employees/{$employee->id}/attendance?from={$from}&to={$to}")->assertOk()->json('data.days')
        )->keyBy('date')->all();
    }

    public function test_the_dtr_is_compared_with_the_schedule_day_by_day(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);
        $this->dayShift($employee);

        $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-07', 'time_in' => '07:55', 'time_out' => '17:00'],   // on time
            ['work_date' => '2026-09-08', 'time_in' => '08:25', 'time_out' => '17:00'],   // late 25
            ['work_date' => '2026-09-09', 'time_in' => '08:00', 'time_out' => '16:30'],   // undertime 30
            ['work_date' => '2026-09-10', 'time_in' => '08:00', 'time_out' => '18:15'],   // past the end 75
            // 11 September: scheduled, nothing recorded
            ['work_date' => '2026-09-12', 'time_in' => '08:00', 'time_out' => '12:00'],   // Saturday
            ['work_date' => '2026-09-14', 'time_in' => '08:00'],                          // no time-out
        ]])->assertOk()->assertJsonPath('data.saved', 6);

        $days = $this->days($employee, '2026-09-07', '2026-09-14');

        $this->assertSame('present', $days['2026-09-07']['status']);
        $this->assertSame([], $days['2026-09-07']['flags']);

        $this->assertSame(['late'], $days['2026-09-08']['flags']);
        $this->assertSame(25, $days['2026-09-08']['late_minutes']);

        $this->assertSame(['undertime'], $days['2026-09-09']['flags']);
        $this->assertSame(30, $days['2026-09-09']['undertime_minutes']);

        $this->assertSame(['overtime'], $days['2026-09-10']['flags']);
        $this->assertSame(75, $days['2026-09-10']['overtime_minutes']);
        // Staying late is reported but is not a shortfall against the schedule.
        $this->assertSame('present', $days['2026-09-10']['status']);

        $this->assertSame('absent', $days['2026-09-11']['status']);
        $this->assertSame('rest_day_work', $days['2026-09-12']['status']);
        $this->assertSame('rest_day', $days['2026-09-13']['status']);
        $this->assertSame(['missing_time_out'], $days['2026-09-14']['flags']);

        $summary = $this->getJson("/api/v1/employees/{$employee->id}/attendance?from=2026-09-07&to=2026-09-14")
            ->json('data.summary');

        $this->assertSame(6, $summary['scheduled_days']);
        $this->assertSame(5, $summary['days_present']);
        $this->assertSame(1, $summary['days_absent']);
        $this->assertSame(1, $summary['days_late']);
        $this->assertSame(25, $summary['late_minutes']);
        $this->assertSame(30, $summary['undertime_minutes']);
        $this->assertSame(75, $summary['overtime_minutes']);
        $this->assertSame(1, $summary['incomplete_records']);
        $this->assertSame(1, $summary['rest_day_work']);
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

        $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-07', 'time_in' => '22:05', 'time_out' => '06:00'],
            ['work_date' => '2026-09-08', 'time_in' => '21:58', 'time_out' => '05:30'],
        ]])->assertOk();

        $days = $this->days($employee, '2026-09-07', '2026-09-08');

        $this->assertSame(5, $days['2026-09-07']['late_minutes']);
        $this->assertTrue($days['2026-09-07']['time_out_next_day']);
        $this->assertSame([], array_diff($days['2026-09-07']['flags'], ['late']));

        $this->assertSame(['undertime'], $days['2026-09-08']['flags']);
        $this->assertSame(30, $days['2026-09-08']['undertime_minutes']);
    }

    public function test_the_grace_period_is_a_setting_and_defaults_to_none(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);
        $this->dayShift($employee);

        $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-07', 'time_in' => '08:08', 'time_out' => '17:00'],
        ]]);

        $this->assertSame(8, $this->days($employee, '2026-09-07', '2026-09-07')['2026-09-07']['late_minutes']);

        config(['empower.attendance.late_grace_minutes' => 10]);

        $day = $this->days($employee, '2026-09-07', '2026-09-07')['2026-09-07'];
        $this->assertSame(0, $day['late_minutes']);
        $this->assertSame('present', $day['status']);
    }

    public function test_a_schedule_change_keeps_the_old_schedule_for_the_days_it_covered(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr);
        $this->dayShift($employee);

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

        // A schedule cannot be slipped in before one that already exists.
        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => '2026-09-10',
            'work_days' => [1],
            'shift_start' => '08:00',
            'shift_end' => '17:00',
        ])->assertStatus(400);
    }

    public function test_only_days_actually_worked_within_employment_can_be_recorded(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $employee = $this->deployedEmployee($hr, '2026-09-10');

        $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => [
            ['work_date' => '2026-10-02', 'time_in' => '08:00'],
        ]])->assertStatus(400);

        $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-01', 'time_in' => '08:00'],
        ]])->assertStatus(400);

        $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-10', 'time_in' => '8:00'],
        ]])->assertStatus(422);

        // Clearing a row removes that day's record.
        $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-10', 'time_in' => '08:00', 'time_out' => '17:00'],
        ]])->assertOk();
        $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-10', 'time_in' => null, 'time_out' => null, 'remarks' => ''],
        ]])->assertOk()->assertJsonPath('data.cleared', 1);

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

        // The 14th and 15th were worked days with no record: absences.
        $this->assertSame('absent', $days['2026-09-15']['status']);
        $this->assertSame('outside_employment', $days['2026-09-16']['status']);

        // A late-arriving DTR for the last days can still be encoded.
        $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-15', 'time_in' => '08:00', 'time_out' => '17:00'],
        ]])->assertOk();
        $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-16', 'time_in' => '08:00', 'time_out' => '17:00'],
        ]])->assertStatus(400);
    }

    public function test_the_workforce_summary_lists_each_employees_discrepancies(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $clean = $this->deployedEmployee($hr);
        $this->dayShift($clean);
        $late = $this->deployedEmployee($hr);
        $this->dayShift($late);

        $this->postJson("/api/v1/employees/{$clean->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00'],
        ]]);
        $this->postJson("/api/v1/employees/{$late->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-07', 'time_in' => '08:40', 'time_out' => '17:00'],
        ]]);

        $rows = collect($this->getJson('/api/v1/attendance?from=2026-09-07&to=2026-09-07')->assertOk()->json('data'))
            ->keyBy('employee_id');

        $this->assertSame(0, $rows[$clean->id]['summary']['days_with_discrepancy']);
        $this->assertSame(40, $rows[$late->id]['summary']['late_minutes']);

        $only = $this->getJson('/api/v1/attendance?from=2026-09-07&to=2026-09-07&only_discrepancies=1')->json('data');
        $this->assertCount(1, $only);
        $this->assertSame($late->id, $only[0]['employee_id']);

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
        $this->getJson('/api/v1/attendance')->assertForbidden();
        $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00'],
        ]])->assertForbidden();
        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => '2026-09-01', 'work_days' => [1], 'shift_start' => '08:00', 'shift_end' => '17:00',
        ])->assertForbidden();

        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('employee_schedules', 0);
    }

    public function test_hr_holds_the_attendance_permissions(): void
    {
        $this->assertTrue($this->hrUser()->can('attendance.view'));
        $this->assertTrue($this->hrUser()->can('attendance.manage'));
    }
}
