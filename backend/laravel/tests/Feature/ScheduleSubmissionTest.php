<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\ScheduleSubmission;
use App\Models\User;
use App\Notifications\ScheduleReviewed;
use App\Notifications\ScheduleSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CoordinatorFixtures;
use Tests\TestCase;

/**
 * The coordinator writes a worker's schedule, HR approves it, and only then is
 * the DTR compared with it.
 *
 * Two clients, A and B, each with a coordinator. The dates are fixed so the
 * weekdays are known: "today" is Wednesday 30 September 2026, 7 September 2026
 * is a Monday, and the workers were placed on 30 August 2026.
 */
class ScheduleSubmissionTest extends TestCase
{
    use CoordinatorFixtures;
    use RefreshDatabase;

    private User $coordinatorA;
    private User $coordinatorB;
    private Employee $workerA;
    private Employee $workerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        $this->setUpTwoClients();

        $this->coordinatorA = $this->coordinatorFor($this->clientA);
        $this->coordinatorB = $this->coordinatorFor($this->clientB);

        $this->actAs($this->hr);
        $this->workerA = $this->placeEmployee($this->deptA, 'Ana', 'Reyes');
        $this->workerB = $this->placeEmployee($this->deptB, 'Ben', 'Cruz');
    }

    private function proposal(array $overrides = []): array
    {
        return array_merge([
            'effective_from' => '2026-10-05',
            'work_days' => [1, 2, 3, 4, 5],
            'shift_start' => '08:00',
            'shift_end' => '17:00',
            'break_start' => '12:00',
            'break_minutes' => 60,
            'source_note' => 'Client HR memo dated 28 September 2026',
        ], $overrides);
    }

    /** Written and sent by coordinator A; returns the submission id. */
    private function submitted(array $overrides = [], ?Employee $worker = null): int
    {
        $this->actAs($this->coordinatorA);
        $worker ??= $this->workerA;

        return $this->postJson("/api/v1/coordinator/employees/{$worker->id}/schedules", $this->proposal($overrides) + ['submit' => true])
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->json('data.id');
    }

    private function review(int $id, string $action, array $extra = [])
    {
        $this->actAs($this->hr);

        return $this->postJson("/api/v1/attendance/schedule-submissions/{$id}/review", ['action' => $action] + $extra);
    }

    private function days(Employee $employee, string $from, string $to): array
    {
        $this->actAs($this->hr);

        return collect($this->getJson("/api/v1/employees/{$employee->id}/attendance?from={$from}&to={$to}")
            ->assertOk()->json('data.days'))->keyBy('date')->all();
    }

    // ------------------------------------------------------------ scenarios

    public function test_a_coordinator_submits_a_schedule_that_stays_pending_until_hr_approves_it(): void
    {
        Notification::fake();

        $id = $this->submitted(['effective_from' => '2026-09-07']);
        $submission = ScheduleSubmission::findOrFail($id);

        $this->assertSame('submitted', $submission->status);
        $this->assertSame($this->clientA->id, $submission->client_company_id);
        $this->assertSame($this->deptA->id, $submission->client_department_id);
        $this->assertMatchesRegularExpression('/^WS-2026-\d+$/', $submission->submission_code);
        $this->assertSame(0, EmployeeSchedule::count(), 'Submitting puts nothing in force.');
        Notification::assertSentTo($this->hr, ScheduleSubmitted::class);
        Notification::assertNotSentTo($this->coordinatorA, ScheduleSubmitted::class);

        // While it waits, the DTR has nothing to be compared with.
        $this->actAs($this->hr);
        $this->postJson("/api/v1/employees/{$this->workerA->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-08', 'time_in' => '08:20', 'time_out' => '17:00'],
        ]])->assertOk();
        $days = $this->days($this->workerA, '2026-09-07', '2026-09-08');
        $this->assertSame('no_schedule', $days['2026-09-07']['status']);
        $this->assertSame(['unscheduled_record'], $days['2026-09-08']['discrepancies']);

        // The profile shows it as waiting, with nothing approved yet.
        $profile = $this->getJson("/api/v1/employees/{$this->workerA->id}/attendance")->assertOk()->json('data');
        $this->assertNull($profile['current_schedule']);
        $this->assertSame([$id], array_column($profile['schedule_submissions'], 'id'));

        $this->review($id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');

        $schedule = EmployeeSchedule::sole();
        $this->assertSame($id, $schedule->submission->id);
        $this->assertSame('2026-09-07', $schedule->effective_from->toDateString());
        $this->assertSame($this->hr->id, $schedule->created_by);
        Notification::assertSentTo($this->coordinatorA, ScheduleReviewed::class);

        // From approval on, the same DTR day is measured against it.
        $days = $this->days($this->workerA, '2026-09-08', '2026-09-08');
        $this->assertSame(['late'], $days['2026-09-08']['discrepancies']);
        $this->assertSame(20, $days['2026-09-08']['late_minutes']);
    }

    public function test_a_coordinator_cannot_reach_another_clients_workers_or_schedules(): void
    {
        $this->actAs($this->coordinatorA);
        $this->postJson("/api/v1/coordinator/employees/{$this->workerB->id}/schedules", $this->proposal())->assertForbidden();

        // A client id in the body changes nothing: the scope is the assignment.
        $this->postJson("/api/v1/coordinator/employees/{$this->workerA->id}/schedules", $this->proposal([
            'client_company_id' => $this->clientB->id,
        ]))->assertCreated()->assertJsonPath('data.client_company_id', $this->clientA->id);

        $this->actAs($this->coordinatorB);
        $theirs = $this->postJson("/api/v1/coordinator/employees/{$this->workerB->id}/schedules", $this->proposal() + ['submit' => true])
            ->assertCreated()->json('data.id');

        $this->actAs($this->coordinatorA);
        $this->getJson("/api/v1/coordinator/schedules/{$theirs}")->assertForbidden();
        $this->putJson("/api/v1/coordinator/schedules/{$theirs}", $this->proposal())->assertForbidden();
        $this->postJson("/api/v1/coordinator/schedules/{$theirs}/withdraw")->assertForbidden();
        $listed = array_column($this->getJson('/api/v1/coordinator/schedules?status=all')->assertOk()->json('data'), 'id');
        $this->assertNotContains($theirs, $listed);

        // Nor HR's own endpoints.
        $this->getJson('/api/v1/attendance/schedule-submissions')->assertForbidden();
        $this->postJson("/api/v1/attendance/schedule-submissions/{$theirs}/review", ['action' => 'approve'])->assertForbidden();
        $this->postJson("/api/v1/employees/{$this->workerA->id}/schedules", $this->proposal())->assertForbidden();
    }

    public function test_another_coordinators_draft_is_their_own(): void
    {
        $colleague = $this->coordinatorFor($this->clientA);
        $this->actAs($colleague);
        $draft = $this->postJson("/api/v1/coordinator/employees/{$this->workerA->id}/schedules", $this->proposal())
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->actAs($this->coordinatorA);
        $this->getJson("/api/v1/coordinator/schedules/{$draft}")->assertForbidden();
        $this->postJson("/api/v1/coordinator/schedules/{$draft}/submit")->assertForbidden();

        // HR never sees an unsent draft.
        $this->actAs($this->hr);
        $this->getJson("/api/v1/attendance/schedule-submissions/{$draft}")->assertNotFound();
    }

    public function test_only_hr_with_attendance_manage_can_decide_and_nobody_approves_their_own(): void
    {
        $id = $this->submitted();

        // Read-only attendance access can look but not decide.
        $viewer = User::factory()->create(['email' => 'viewer.'.uniqid().'@cdemanpower.local', 'user_type' => 'hr']);
        $viewer->givePermissionTo('attendance.view');
        $this->actAs($viewer);
        $this->getJson("/api/v1/attendance/schedule-submissions/{$id}")->assertOk()->assertJsonPath('data.can_review', false);
        $this->postJson("/api/v1/attendance/schedule-submissions/{$id}/review", ['action' => 'approve'])->assertForbidden();
        $this->assertSame('submitted', ScheduleSubmission::find($id)->status);

        // A schedule whose writer is the reviewer is refused, however it got there.
        $own = ScheduleSubmission::find($id)->replicate(['submission_code']);
        $own->fill(['submission_code' => 'WS-TEST-OWN', 'created_by' => $this->hr->id, 'employee_id' => $this->workerA->id])->save();
        $this->review($own->id, 'approve')->assertForbidden();

        // An administrator holds attendance.manage and may decide.
        $this->actAs($this->admin);
        $this->postJson("/api/v1/attendance/schedule-submissions/{$id}/review", ['action' => 'approve'])->assertOk();
    }

    public function test_hr_returns_an_incomplete_schedule_with_a_reason_and_the_coordinator_resubmits(): void
    {
        Notification::fake();
        $id = $this->submitted(['shift_start' => '07:00']);

        $this->review($id, 'return')->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->review($id, 'return', ['note' => 'The memo says 08:00, not 07:00.'])
            ->assertOk()->assertJsonPath('data.status', 'returned');
        Notification::assertSentTo($this->coordinatorA, ScheduleReviewed::class,
            fn (ScheduleReviewed $n) => str_contains($n->toArray($this->coordinatorA)['body'], 'The memo says 08:00'));

        // A returned schedule cannot be approved until it comes back.
        $this->review($id, 'approve')->assertStatus(409);

        $this->actAs($this->coordinatorA);
        $detail = $this->getJson("/api/v1/coordinator/schedules/{$id}")->assertOk()->json('data');
        $this->assertSame('The memo says 08:00, not 07:00.', $detail['review_note']);
        $this->assertTrue($detail['is_editable']);

        $this->putJson("/api/v1/coordinator/schedules/{$id}", $this->proposal() + ['submit' => true])
            ->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->review($id, 'approve')->assertOk();
        $this->assertSame('08:00', EmployeeSchedule::sole()->time('shift_start'));

        $events = collect(ScheduleSubmission::find($id)->events)->map(fn ($e) => [$e->from_status, $e->to_status])->all();
        $this->assertSame([
            [null, 'draft'],
            ['draft', 'submitted'],
            ['submitted', 'returned'],
            ['returned', 'returned'],   // the edit, with what changed
            ['returned', 'submitted'],
            ['submitted', 'approved'],
        ], $events);
        $edit = ScheduleSubmission::find($id)->events[3];
        $this->assertSame(['before' => '07:00', 'after' => '08:00'], $edit->changes['shift_start']);
    }

    public function test_an_approved_schedule_is_never_overwritten_and_a_change_starts_a_new_one(): void
    {
        $first = $this->submitted(['effective_from' => '2026-09-07']);
        $this->review($first, 'approve')->assertOk();

        $this->actAs($this->coordinatorA);
        $this->putJson("/api/v1/coordinator/schedules/{$first}", $this->proposal(['shift_start' => '06:00']))->assertStatus(409);
        $this->postJson("/api/v1/coordinator/schedules/{$first}/withdraw")->assertStatus(409);

        // The change: a new schedule from a later date.
        $second = $this->submitted(['effective_from' => '2026-10-05', 'shift_start' => '09:00', 'shift_end' => '18:00']);

        $this->actAs($this->hr);
        $detail = $this->getJson("/api/v1/attendance/schedule-submissions/{$second}")->assertOk()->json('data');
        $this->assertSame('08:00', $detail['current_schedule']['shift_start']);
        $this->assertSame(['shift_start', 'shift_end'], $detail['changes']);

        $this->review($second, 'approve')->assertOk();

        $old = EmployeeSchedule::where('effective_from', '2026-09-07')->sole();
        $this->assertSame('08:00', $old->time('shift_start'), 'The approved schedule keeps its hours.');
        $this->assertSame('2026-10-04', $old->effective_to->toDateString());
        $this->assertSame(2, EmployeeSchedule::inForce()->count());

        // Days already compared keep the schedule they were compared with.
        $this->postJson("/api/v1/employees/{$this->workerA->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-08', 'time_in' => '08:00', 'time_out' => '17:00'],
        ]])->assertOk();
        $this->assertSame('on_schedule', $this->days($this->workerA, '2026-09-08', '2026-09-08')['2026-09-08']['status']);
    }

    public function test_conflicting_schedules_are_refused(): void
    {
        $id = $this->submitted(['effective_from' => '2026-10-05']);

        // Only one schedule per employee is with HR at a time.
        $this->actAs($this->coordinatorA);
        $this->postJson("/api/v1/coordinator/employees/{$this->workerA->id}/schedules", $this->proposal(['effective_from' => '2026-10-12']))
            ->assertStatus(409);

        // HR meanwhile sets one from a later date directly.
        $this->actAs($this->hr);
        $this->postJson("/api/v1/employees/{$this->workerA->id}/schedules", [
            'effective_from' => '2026-10-07', 'work_days' => [1, 2, 3, 4, 5], 'shift_start' => '09:00', 'shift_end' => '18:00',
        ])->assertCreated();

        $checks = collect($this->getJson("/api/v1/attendance/schedule-submissions/{$id}")->json('data.checks'));
        $this->assertSame('error', $checks->firstWhere('code', 'later_schedule')['level']);

        $this->review($id, 'approve')->assertStatus(409);
        $this->assertSame('submitted', ScheduleSubmission::find($id)->status);
        $this->assertSame(1, EmployeeSchedule::count(), 'No overlapping schedule was created.');

        // It can still be returned for a new date.
        $this->review($id, 'return', ['note' => 'HR already set hours from 7 October.'])->assertOk();
    }

    public function test_back_dating_over_compared_days_is_hr_s_decision_and_must_be_confirmed(): void
    {
        $first = $this->submitted(['effective_from' => '2026-09-07']);
        $this->review($first, 'approve')->assertOk();

        // A coordinator cannot rewrite days already compared.
        $this->actAs($this->coordinatorA);
        $draft = $this->postJson("/api/v1/coordinator/employees/{$this->workerA->id}/schedules", $this->proposal(['effective_from' => '2026-09-21']))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/coordinator/schedules/{$draft}/submit")->assertUnprocessable();
        $this->postJson("/api/v1/coordinator/schedules/{$draft}/withdraw", ['reason' => 'Wrong start date'])->assertOk();

        // Submitted for tomorrow, but HR decides a week later.
        $later = $this->submitted(['effective_from' => '2026-10-01', 'shift_start' => '09:00', 'shift_end' => '18:00']);
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

        $this->review($later, 'approve')->assertUnprocessable()->assertJsonValidationErrors('confirm_replaces_past');
        $this->review($later, 'approve', ['confirm_replaces_past' => true])->assertOk();
        $this->assertSame('2026-09-30', EmployeeSchedule::where('effective_from', '2026-09-07')->sole()->effective_to->toDateString());
    }

    public function test_the_comparison_uses_the_approved_schedule_for_each_date_including_night_shifts_rest_days_and_leave(): void
    {
        // A day shift for one week, then a night shift. Both start in the past
        // on days no approved schedule covered, which a coordinator may do.
        $day = $this->submitted(['effective_from' => '2026-09-07', 'effective_to' => '2026-09-13']);
        $this->review($day, 'approve')->assertOk();
        $night =$this->submitted(['effective_from' => '2026-09-14', 'shift_start' => '22:00', 'shift_end' => '06:00', 'break_start' => null, 'break_minutes' => null]);
        $this->review($night, 'approve')->assertOk();

        $this->postJson('/api/v1/attendance/exceptions', [
            'scope' => 'employee', 'employee_id' => $this->workerA->id, 'exception_type' => 'approved_leave',
            'date_from' => '2026-09-16', 'date_to' => '2026-09-16',
        ])->assertCreated();

        $this->postJson("/api/v1/employees/{$this->workerA->id}/attendance", ['entries' => [
            ['work_date' => '2026-09-08', 'time_in' => '08:10', 'time_out' => '16:45'],  // day shift: late 10, early 15
            ['work_date' => '2026-09-14', 'time_in' => '22:10', 'time_out' => '06:00'],  // night shift, ends next day: late 10
            ['work_date' => '2026-09-15', 'time_in' => '22:00'],                          // missing time-out
            ['work_date' => '2026-09-19', 'time_in' => '22:00', 'time_out' => '06:00'],  // Saturday: rest day
        ], 'complete_from' => '2026-09-07', 'complete_to' => '2026-09-18'])->assertOk();

        $days = $this->days($this->workerA, '2026-09-07', '2026-09-21');

        $this->assertSame(['late', 'early_departure'], $days['2026-09-08']['discrepancies']);
        $this->assertSame('08:00', $days['2026-09-08']['schedule']['start']);

        $this->assertSame(['late'], $days['2026-09-14']['discrepancies']);
        $this->assertSame(10, $days['2026-09-14']['late_minutes']);
        $this->assertTrue($days['2026-09-14']['schedule']['crosses_midnight']);

        $this->assertSame(['missing_time_out'], $days['2026-09-15']['discrepancies']);
        $this->assertSame('excused', $days['2026-09-16']['status']);
        // Blank on a DTR declared complete: a gap to review, never an absence.
        $this->assertSame(['no_record'], $days['2026-09-17']['discrepancies']);
        $this->assertSame(['outside_schedule'], $days['2026-09-19']['discrepancies']);
        // After the declared period, a blank day is only awaiting the DTR.
        $this->assertSame('awaiting_dtr', $days['2026-09-21']['status']);
        $this->assertSame([], $days['2026-09-21']['discrepancies']);
    }

    public function test_every_step_is_in_the_audit_trail(): void
    {
        $id = $this->submitted(['shift_start' => '07:00']);
        $this->review($id, 'return', ['note' => 'Check the start time.'])->assertOk();
        $this->actAs($this->coordinatorA);
        $this->putJson("/api/v1/coordinator/schedules/{$id}", $this->proposal() + ['submit' => true])->assertOk();
        $this->review($id, 'approve')->assertOk();

        $actions = AuditLog::query()
            ->where('record_type', 'ScheduleSubmission')
            ->where('record_id', $id)
            ->orderBy('id')
            ->pluck('action_type')
            ->all();
        $this->assertSame(['create', 'submit', 'return', 'update', 'submit', 'approve'], $actions);

        $created = AuditLog::query()->where('record_type', 'EmployeeSchedule')->where('action_type', 'create')->sole();
        $this->assertSame(ScheduleSubmission::find($id)->submission_code, $created->new_values_json['schedule_submission']);
        $this->assertSame($this->clientA->id, $created->client_company_id);
    }

    public function test_hr_coordinator_and_profile_views_show_the_same_schedule(): void
    {
        $id = $this->submitted(['effective_from' => '2026-09-07']);
        $this->review($id, 'approve')->assertOk();
        $scheduleId = EmployeeSchedule::sole()->id;

        $profile = $this->getJson("/api/v1/employees/{$this->workerA->id}/attendance")->assertOk()->json('data');
        $this->assertSame($scheduleId, $profile['current_schedule']['id']);
        $this->assertSame($this->coordinatorA->full_name, $profile['schedules'][0]['submission']['proposed_by']);
        $this->assertSame($this->hr->full_name, $profile['schedules'][0]['submission']['approved_by']);
        $this->assertSame([], $profile['schedule_submissions'], 'Nothing is waiting once approved.');

        $hrList = $this->getJson('/api/v1/attendance/schedule-submissions?status=approved')->assertOk();
        $this->assertSame($scheduleId, $hrList->json('data.0.employee_schedule_id'));
        $this->assertSame(1, $hrList->json('meta.status_counts.approved'));

        $this->actAs($this->coordinatorA);
        $mine = $this->getJson("/api/v1/coordinator/employees/{$this->workerA->id}")->assertOk()->json('data');
        $this->assertSame($scheduleId, $mine['current_schedule_id']);
        $this->assertSame($scheduleId, $mine['schedules'][0]['id']);
        $this->assertSame($profile['current_schedule']['description'], $mine['attendance']['schedule']);
        $this->assertSame('approved', $mine['schedule_submissions'][0]['status']);
    }

    public function test_the_proposal_is_held_to_the_schedule_rules(): void
    {
        $this->actAs($this->coordinatorA);
        $url = "/api/v1/coordinator/employees/{$this->workerA->id}/schedules";

        $this->postJson($url, $this->proposal(['effective_from' => '2026-08-01']))->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->postJson($url, $this->proposal(['effective_to' => '2026-10-01']))->assertUnprocessable()->assertJsonValidationErrors('effective_to');
        $this->postJson($url, $this->proposal(['work_days' => []]))->assertUnprocessable()->assertJsonValidationErrors('work_days');
        $this->postJson($url, $this->proposal(['shift_end' => '08:00']))->assertUnprocessable()->assertJsonValidationErrors('shift_end');
        $this->postJson($url, $this->proposal(['break_minutes' => 600]))->assertUnprocessable()->assertJsonValidationErrors('break_minutes');

        // A source is needed before it goes to HR.
        $draft = $this->postJson($url, $this->proposal(['source_note' => '']))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/coordinator/schedules/{$draft}/submit")->assertUnprocessable()->assertJsonValidationErrors('source_note');

        // An end date is kept, and a seven-day week is flagged for HR.
        $this->putJson("/api/v1/coordinator/schedules/{$draft}", $this->proposal(['effective_to' => '2026-12-31', 'work_days' => [1, 2, 3, 4, 5, 6, 7]]) + ['submit' => true])
            ->assertOk();
        $this->actAs($this->hr);
        $checks = collect($this->getJson("/api/v1/attendance/schedule-submissions/{$draft}")->json('data.checks'));
        $this->assertSame('warning', $checks->firstWhere('code', 'no_rest_day')['level']);
        $this->review($draft, 'approve')->assertOk();
        $this->assertSame('2026-12-31', EmployeeSchedule::sole()->effective_to->toDateString());
    }
}
