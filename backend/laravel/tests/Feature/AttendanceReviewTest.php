<?php

namespace Tests\Feature;

use App\Models\AttendanceReview;
use App\Models\Employee;
use App\Models\User;
use App\Services\CoordinatorAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

/**
 * HR's review of what the comparison flags.
 *
 * A flagged day is only a pointer. A person verifies it, corrects the data
 * behind it, or closes it with an explanation, and every step is kept. A
 * violation may follow a verified day, and only a verified one, through the
 * existing violation workflow; nothing here records discipline by itself.
 */
class AttendanceReviewTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));

        $this->hr = $this->hrUser();
        Sanctum::actingAs($this->hr);
        $this->employee = $this->deployedEmployee($this->hr);
        $this->dayShift($this->employee);

        $this->encode($this->employee, [
            ['work_date' => '2026-09-07', 'time_in' => '08:00', 'time_out' => '17:00'],
            ['work_date' => '2026-09-08', 'time_in' => '08:45', 'time_out' => '17:00'],
            ['work_date' => '2026-09-09', 'time_in' => '08:00'],
        ], ['complete_from' => '2026-09-07', 'complete_to' => '2026-09-10'])->assertOk();
    }

    private function review(string $date, string $status, ?string $note = null)
    {
        return $this->postJson("/api/v1/employees/{$this->employee->id}/attendance/days/{$date}/review", array_filter([
            'status' => $status,
            'note' => $note,
        ]));
    }

    private function queue(string $query = '')
    {
        return $this->getJson('/api/v1/attendance/discrepancies?from=2026-09-07&to=2026-09-10'.$query)->assertOk();
    }

    public function test_flagged_days_wait_in_the_queue_for_review(): void
    {
        $queue = $this->queue();

        $this->assertSame(3, $queue->json('meta.total'));
        $this->assertSame(3, $queue->json('meta.status_counts.for_review'));

        $rows = collect($queue->json('data'))->keyBy('date');
        $this->assertSame(['late'], $rows['2026-09-08']['discrepancies']);
        $this->assertSame(45, $rows['2026-09-08']['late_minutes']);
        $this->assertSame('08:00', $rows['2026-09-08']['schedule']['start']);
        $this->assertSame('08:45', $rows['2026-09-08']['time_in']);
        $this->assertNull($rows['2026-09-08']['review']);
        $this->assertSame(['missing_time_out'], $rows['2026-09-09']['discrepancies']);
        $this->assertSame(['no_record'], $rows['2026-09-10']['discrepancies']);
        $this->assertSame($this->employee->currentCompany->company_name, $rows['2026-09-08']['company']);

        $this->assertSame(['2026-09-08'], array_column($this->queue('&discrepancy=late')->json('data'), 'date'));
        $this->assertSame(0, $this->queue('&client_company_id='.$this->clientCompany($this->hr)->id)->json('meta.total'));
    }

    public function test_a_review_moves_through_its_statuses_with_every_step_kept(): void
    {
        $this->review('2026-09-08', 'under_verification')->assertOk()->assertJsonPath('data.status', 'under_verification');

        // Verifying says what was found.
        $this->review('2026-09-08', 'verified')->assertStatus(422);
        $this->review('2026-09-08', 'verified', 'Client supervisor confirms the late arrival')->assertOk();

        $this->review('2026-09-08', 'closed', 'Talked to the worker; no further action')->assertOk();

        // Closed is final until reopened, with a reason.
        $this->review('2026-09-08', 'verified', 'x')->assertStatus(422);
        $this->review('2026-09-08', 'under_verification')->assertStatus(422);
        $this->review('2026-09-08', 'under_verification', 'Client sent a corrected DTR')->assertOk();

        $day = $this->getJson("/api/v1/employees/{$this->employee->id}/attendance/days/2026-09-08")->assertOk()->json('data');
        $this->assertSame(
            ['under_verification', 'verified', 'closed', 'under_verification'],
            array_column($day['review_events'], 'to_status')
        );
        $this->assertSame('Client supervisor confirms the late arrival', $day['review_events'][1]['note']);
        $this->assertSame(45, $day['review_events'][1]['snapshot']['late_minutes']);

        // A day nothing flagged has nothing to review.
        $this->review('2026-09-07', 'under_verification')->assertStatus(422);
        $this->assertSame(1, AttendanceReview::count());
    }

    public function test_a_correction_clears_the_flag_but_the_review_stays_to_be_closed(): void
    {
        $this->review('2026-09-09', 'under_verification')->assertOk();

        // The client confirms the worker did clock out; HR corrects the DTR.
        $this->encode($this->employee, [['work_date' => '2026-09-09', 'time_in' => '08:00', 'time_out' => '17:00']], [
            'reason' => 'Client confirmed the 17:00 punch',
        ])->assertOk();

        $row = collect($this->queue('&status=under_verification')->json('data'))->firstWhere('date', '2026-09-09');
        $this->assertFalse($row['still_flagged']);
        $this->assertSame(['missing_time_out'], $row['review']['discrepancy_types']);

        $this->review('2026-09-09', 'corrected', 'DTR corrected from the client\'s confirmation')->assertOk();
        $this->review('2026-09-09', 'closed', 'Resolved')->assertOk();

        $this->assertSame(1, $this->queue('&status=closed')->json('meta.total'));
        $this->assertSame(2, $this->queue()->json('meta.total'));
    }

    public function test_only_a_verified_discrepancy_can_become_a_violation(): void
    {
        $this->review('2026-09-08', 'under_verification')->assertOk();
        $review = AttendanceReview::first();

        $violation = fn (array $overrides = []) => $this->postJson("/api/v1/employees/{$this->employee->id}/violations", $overrides + [
            'violation_date' => '2026-09-08',
            'violation_type' => 'late',
            'description' => 'Timed in at 08:45 against an 08:00 start, verified with the client.',
            'attendance_review_id' => $review->id,
        ]);

        $violation()->assertStatus(422);
        $this->assertDatabaseCount('employee_violations', 0);

        $this->review('2026-09-08', 'verified', 'Confirmed by the client supervisor')->assertOk();

        $violation(['violation_date' => '2026-09-09'])->assertStatus(422);
        $violation()->assertCreated();
        $violation()->assertStatus(409);

        $this->assertDatabaseCount('employee_violations', 1);
        $this->assertNotNull($review->fresh()->employee_violation_id);

        $day = $this->getJson("/api/v1/employees/{$this->employee->id}/attendance/days/2026-09-08")->json('data');
        $this->assertSame('late', $day['violation']['violation_type']);
        $this->assertStringContainsString('Recorded as a violation', end($day['review_events'])['note']);

        // Once a violation stands on it, the day is not "corrected" away.
        $this->review('2026-09-08', 'corrected', 'Trying to undo')->assertStatus(422);
    }

    public function test_reviewing_needs_its_own_permission(): void
    {
        $role = Role::create(['name' => 'encoder', 'guard_name' => 'web']);
        $role->givePermissionTo(['attendance.view', 'attendance.manage']);
        $encoder = User::factory()->create(['email' => 'encoder@cdemanpower.local', 'user_type' => 'hr']);
        $encoder->assignRole($role);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($encoder);

        $this->queue();
        $this->review('2026-09-08', 'under_verification')->assertForbidden();
        $this->assertDatabaseCount('attendance_reviews', 0);
    }

    public function test_coordinators_see_a_summary_of_their_own_client_only(): void
    {
        $other = $this->deployedEmployee($this->hr);
        $coordinator = User::factory()->create(['email' => 'coord@cdemanpower.local', 'user_type' => 'coordinator']);
        $coordinator->assignRole('coordinator');
        app(CoordinatorAssignmentService::class)->assign($coordinator, $this->employee->currentCompany, $this->adminUser());
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($coordinator);

        $this->travelTo(Carbon::parse('2026-09-10 12:00:00'));

        $summary = $this->getJson("/api/v1/coordinator/employees/{$this->employee->id}")->assertOk()->json('data.attendance.summary');
        $this->assertSame(1, $summary['late_days']);
        $this->assertSame(1, $summary['missing_punch_days']);
        $this->assertArrayNotHasKey('days_absent', $summary);

        $this->getJson("/api/v1/coordinator/employees/{$other->id}")->assertForbidden();

        // None of HR's attendance screens or actions.
        $this->getJson('/api/v1/attendance')->assertForbidden();
        $this->getJson('/api/v1/attendance/discrepancies')->assertForbidden();
        $this->getJson("/api/v1/employees/{$this->employee->id}/attendance")->assertForbidden();
        $this->review('2026-09-08', 'under_verification')->assertForbidden();
        $this->postJson('/api/v1/attendance/imports', [])->assertForbidden();
    }
}
