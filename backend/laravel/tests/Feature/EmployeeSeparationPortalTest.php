<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Resignation;
use App\Models\SeparationEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CoordinatorFixtures;
use Tests\TestCase;

/**
 * Resignation requests from the portal, and separation notices to it.
 *
 * The employee asks; HR decides, clears and completes; an administrator
 * finalises a termination. These tests hold each side to its part: a request
 * never ends anybody's employment, nothing short of a finalised termination
 * tells the employee they are dismissed, the employee never sees the office's
 * own notes, and every action is resolved from the signed-in account rather
 * than from an id somebody sent.
 */
class EmployeeSeparationPortalTest extends TestCase
{
    use CoordinatorFixtures;
    use RefreshDatabase;

    private Employee $employee;
    private User $login;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoClients();

        // As the local demo runs (APP_ENV=local): a relation read without
        // being loaded fails here rather than in front of an employee. Reset
        // for every test when the application boots again.
        Model::preventLazyLoading();

        $this->employee = $this->placeEmployee($this->deptA, 'Ana', 'Reyes');
        // Made at registration, so it still says "applicant" after deployment -
        // the case that used to hide My employment.
        $this->login = $this->portalLogin($this->employee);
    }

    private function portalLogin(Employee $employee, string $type = 'applicant'): User
    {
        $user = User::factory()->create([
            'email' => 'portal'.$employee->id.'.'.uniqid().'@example.test',
            'user_type' => $type,
            'applicant_id' => $employee->applicant_id,
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('portal');

        return $user;
    }

    private function request(array $overrides = [])
    {
        return $this->postJson('/api/v1/portal/resignation', array_merge([
            'requested_exit_date' => now()->addDays(30)->toDateString(),
            'reason' => 'Moving back to the province',
            'remarks' => 'I can stay longer if needed.',
            'acknowledged' => true,
        ], $overrides));
    }

    private function hrUpdate(Resignation $resignation, array $data)
    {
        return $this->putJson("/api/v1/employees/{$resignation->employee_id}/resignations/{$resignation->id}", $data);
    }

    /** Notifications addressed to the employee's own records, as the portal reads them. */
    private function employeeNotifications(): array
    {
        $this->actAs($this->login->fresh());

        return $this->getJson('/api/v1/notifications?per_page=50')->assertOk()->json('data.notifications');
    }

    private function finalisedTermination(array $finalise = []): int
    {
        $this->actAs($this->hr);
        $id = $this->postJson("/api/v1/employees/{$this->employee->id}/terminations", [
            'reason' => 'Fourth AWOL within twelve months',
            'termination_date' => now()->toDateString(),
            'remarks' => 'Supervisor says he was seen at a competitor. Do not tell the employee.',
            'status' => 'for_review',
        ])->assertCreated()->json('data.id');

        $this->actAs($this->admin);
        $this->postJson(
            "/api/v1/employees/{$this->employee->id}/terminations/{$id}/finalise",
            array_merge(['confirm' => true], $finalise)
        )->assertOk();

        return $id;
    }

    // ======================================================== the request itself

    /** 1, 4, 6. */
    public function test_a_deployed_employee_with_an_applicant_login_can_send_a_request(): void
    {
        $other = $this->placeEmployee($this->deptA, 'Ben', 'Cruz');
        $this->actAs($this->login->fresh());

        // The portal account says "applicant", but there is employment behind it.
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.employment.has_record', true)
            ->assertJsonPath('data.employment.status', 'active');

        $this->getJson('/api/v1/portal/employment')->assertOk()
            ->assertJsonPath('data.separation.eligibility.can_request', true)
            ->assertJsonPath('data.separation.notice_days', 30);

        $this->request([
            // Neither is the employee's to choose, and both are ignored.
            'employee_id' => $other->id,
            'filing_date' => '2020-01-01',
        ])->assertCreated()
            ->assertJsonPath('data.resignation.status', 'filed')
            ->assertJsonPath('data.resignation.status_label', 'Submitted, awaiting HR review')
            ->assertJsonPath('data.eligibility.can_request', false);

        $resignation = Resignation::sole();
        $this->assertSame($this->employee->id, $resignation->employee_id);
        $this->assertSame(0, $other->resignations()->count());
        $this->assertSame(today()->toDateString(), $resignation->filing_date->toDateString());
        $this->assertSame('portal', $resignation->source);
        $this->assertSame($this->login->id, $resignation->filed_by);
        $this->assertSame($this->employee->activeDeployment()->id, $resignation->deployment_id);
        $this->assertNull($resignation->processed_by);
        $this->assertSame('I can stay longer if needed.', $resignation->employee_remarks);

        // A request is not a separation.
        $this->assertSame('active', $this->employee->fresh()->employment_status);
        $this->assertSame('active', $this->employee->applicant->fresh()->current_status);
        $this->assertSame('active', $this->employee->activeDeployment()->deployment_status);

        // The office hears about it; HR and the administrator both handle it.
        foreach ([$this->hr, $this->admin] as $staff) {
            $this->assertSame(
                'Ana Reyes submitted a resignation request',
                $staff->notifications()->sole()->data['title']
            );
        }
    }

    public function test_the_request_must_be_complete_and_acknowledged(): void
    {
        $this->actAs($this->login->fresh());

        $this->postJson('/api/v1/portal/resignation', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['requested_exit_date', 'reason', 'acknowledged']);

        $this->request(['acknowledged' => false])->assertStatus(422)->assertJsonValidationErrors('acknowledged');
        $this->request(['requested_exit_date' => now()->subDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('requested_exit_date');

        $this->assertDatabaseCount('resignations', 0);
    }

    public function test_a_letter_is_stored_privately_and_opened_by_its_owner_only(): void
    {
        Storage::fake('documents');
        $this->actAs($this->login->fresh());

        $this->request(['letter' => UploadedFile::fake()->create('letter.pdf', 40, 'application/pdf')])
            ->assertCreated()
            ->assertJsonPath('data.resignation.has_letter', true);

        $this->assertNotNull(Resignation::sole()->resignation_letter_path);
        $this->getJson('/api/v1/portal/resignation/letter')->assertOk()->assertJsonStructure(['data' => ['url']]);
        // The path itself never leaves the server.
        $this->assertStringNotContainsString('resignations/', json_encode($this->getJson('/api/v1/portal/employment')->json()));
    }

    /** 2. */
    public function test_an_applicant_who_was_never_deployed_cannot_request(): void
    {
        $applicant = $this->applicant($this->hr);
        $user = User::factory()->create([
            'email' => 'never.deployed@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]);
        $user->assignRole('portal');
        $this->actAs($user);

        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.employment.has_record', false);
        $this->request()->assertForbidden();
        $this->assertDatabaseCount('resignations', 0);
    }

    /** 5. */
    public function test_only_one_request_may_be_open_and_a_withdrawn_one_does_not_block_the_next(): void
    {
        $this->actAs($this->login->fresh());
        $this->request()->assertCreated();

        $this->request()->assertStatus(409)
            ->assertJsonPath('message', 'You already have a resignation request in progress.');
        $this->assertDatabaseCount('resignations', 1);

        // The database refuses a second open case even if the checks above
        // were somehow passed twice at once.
        try {
            Resignation::create([
                'employee_id' => $this->employee->id,
                'filing_date' => today(),
                'status' => 'filed',
            ]);
            $this->fail('A second open resignation was stored.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('resignations', 1);
        }

        $this->postJson('/api/v1/portal/resignation/withdraw', ['reason' => 'Changed my mind'])->assertOk()
            ->assertJsonPath('data.resignation.status_label', 'Withdrawn by you')
            ->assertJsonPath('data.eligibility.can_request', true);

        $this->request()->assertCreated();
        $this->assertSame(2, $this->employee->resignations()->count());
    }

    // ======================================================== HR's side

    /** 7, 14. */
    public function test_hr_asks_for_information_accepts_clears_and_completes(): void
    {
        $this->actAs($this->login->fresh());
        $this->request()->assertCreated();
        $resignation = Resignation::sole();

        // Asking for information needs a message for the employee.
        $this->actAs($this->hr);
        $this->hrUpdate($resignation, ['status' => 'info_requested'])->assertStatus(422);
        $this->hrUpdate($resignation, [
            'status' => 'info_requested',
            'employee_message' => 'Please confirm whether you can render the full 30 days.',
            'remarks' => 'Client is short two packers this month.',
        ])->assertOk();

        $this->actAs($this->login->fresh());
        $this->getJson('/api/v1/portal/employment')->assertOk()
            ->assertJsonPath('data.separation.resignation.status_label', 'More information needed')
            ->assertJsonPath('data.separation.resignation.hr_message', 'Please confirm whether you can render the full 30 days.')
            ->assertJsonPath('data.separation.resignation.can_respond', true);

        // Completing is not possible while HR waits for the answer.
        $this->actAs($this->hr);
        $this->hrUpdate($resignation, ['clearance_status' => 'cleared', 'exit_date' => now()->addDays(30)->toDateString()])->assertOk();
        $this->postJson("/api/v1/employees/{$this->employee->id}/resignations/{$resignation->id}/complete", ['confirm' => true])
            ->assertStatus(400);

        $this->actAs($this->login->fresh());
        $this->postJson('/api/v1/portal/resignation/respond', ['message' => 'Yes, I can stay the full 30 days.'])->assertOk()
            ->assertJsonPath('data.resignation.status', 'filed');
        $this->assertTrue($this->hr->notifications()->get()->contains(
            fn ($n) => $n->data['title'] === 'Ana Reyes replied about their resignation request'
        ));

        // Accepting needs a confirmed last day; this one has it already.
        $this->actAs($this->hr);
        $this->hrUpdate($resignation, ['status' => 'accepted'])->assertOk();

        // The employee can no longer withdraw it on their own.
        $this->actAs($this->login->fresh());
        $this->postJson('/api/v1/portal/resignation/withdraw')->assertStatus(409);

        $this->actAs($this->hr);
        $this->postJson("/api/v1/employees/{$this->employee->id}/resignations/{$resignation->id}/complete", ['confirm' => true])
            ->assertOk();

        $this->assertSame('resigned', $this->employee->fresh()->employment_status);
        $this->assertSame('resigned', $this->employee->applicant->fresh()->current_status);
        $this->assertDatabaseHas('archives', ['entity_type' => 'employee', 'entity_id' => $this->employee->id]);

        $titles = array_column($this->employeeNotifications(), 'title');
        $this->assertContains('HR needs more information about your resignation', $titles);
        $this->assertContains('Your resignation has been accepted', $titles);
        $this->assertContains('Your resignation is complete', $titles);
        // Not the generic "status changed" notice alongside it.
        $this->assertNotContains('Your application status has changed', $titles);

        $this->getJson('/api/v1/portal/employment')->assertOk()
            ->assertJsonPath('data.status', 'resigned')
            ->assertJsonPath('data.separation.resignation.status', 'completed')
            ->assertJsonPath('data.separation.resignation.clearance.label', 'Cleared')
            ->assertJsonPath('data.separation.eligibility.can_request', false);

        $events = SeparationEvent::where('case_type', 'resignation')->pluck('event')->all();
        foreach (['filed', 'info_requested', 'clearance_changed', 'exit_date_changed', 'employee_responded', 'accepted', 'completed'] as $event) {
            $this->assertContains($event, $events);
        }
    }

    public function test_saving_without_a_change_sends_nothing_and_writes_nothing(): void
    {
        $this->actAs($this->login->fresh());
        $this->request()->assertCreated();
        $resignation = Resignation::sole();

        $this->actAs($this->hr);
        $this->hrUpdate($resignation, ['clearance_status' => 'in_progress'])->assertOk();
        $events = SeparationEvent::count();
        $notices = DatabaseNotification::where('notifiable_type', $this->employee->getMorphClass())->count();

        $this->hrUpdate($resignation, ['clearance_status' => 'in_progress', 'status' => 'filed'])->assertOk();

        $this->assertSame($events, SeparationEvent::count());
        $this->assertSame($notices, DatabaseNotification::where('notifiable_type', $this->employee->getMorphClass())->count());
    }

    /** 8. */
    public function test_nobody_else_can_decide_or_change_the_request(): void
    {
        $this->actAs($this->login->fresh());
        $this->request()->assertCreated();
        $resignation = Resignation::sole();

        // The employee cannot accept or clear it through the staff routes.
        $this->hrUpdate($resignation, ['status' => 'accepted', 'clearance_status' => 'cleared'])->assertForbidden();

        // A coordinator - even the one covering this client - cannot either.
        $this->actAs($this->coordinatorFor($this->clientA));
        $this->hrUpdate($resignation, ['status' => 'accepted'])->assertForbidden();
        $this->postJson("/api/v1/employees/{$this->employee->id}/resignations/{$resignation->id}/complete", ['confirm' => true])
            ->assertForbidden();

        // Another employee has nothing of theirs to withdraw, and no way to
        // name this one.
        $this->actAs($this->portalLogin($this->placeEmployee($this->deptB, 'Carl', 'Diaz')));
        $this->postJson('/api/v1/portal/resignation/withdraw')->assertStatus(409);

        $this->assertDatabaseHas('resignations', [
            'id' => $resignation->id,
            'status' => 'filed',
            'clearance_status' => 'pending',
        ]);
    }

    public function test_hr_closing_a_request_needs_a_reason_and_keeps_it_internal(): void
    {
        $this->actAs($this->login->fresh());
        $this->request()->assertCreated();
        $resignation = Resignation::sole();

        $this->actAs($this->hr);
        $this->hrUpdate($resignation, ['status' => 'withdrawn'])->assertStatus(422);
        $this->hrUpdate($resignation, [
            'status' => 'withdrawn',
            'withdrawal_reason' => 'Employee told the supervisor he is staying; filed under pressure from a co-worker.',
        ])->assertOk();

        $this->actAs($this->login->fresh());
        $portal = $this->getJson('/api/v1/portal/employment')->assertOk()
            ->assertJsonPath('data.separation.resignation.status_label', 'Closed by HR')
            ->assertJsonPath('data.separation.eligibility.can_request', true);

        $this->assertStringNotContainsString('pressure from a co-worker', $portal->getContent());
    }

    // ======================================================== termination notices

    /** 9, 11, 12. */
    public function test_a_finalised_termination_sends_a_notice_without_the_offices_notes(): void
    {
        $id = $this->finalisedTermination([
            'employee_message' => 'Your employment ends today following repeated absences without leave. Please come to the office for your clearance.',
        ]);

        $notifications = $this->employeeNotifications();
        $notice = collect($notifications)->firstWhere('title', 'A notice about your employment');
        $this->assertNotNull($notice);
        $this->assertSame('/portal/employment', $notice['link']);
        $this->assertSame('employment', $notice['category']);
        $this->assertNotContains('Your application status has changed', array_column($notifications, 'title'));

        $unread = $this->getJson('/api/v1/notifications/unread-count')->json('data.unread_count');
        $this->assertGreaterThan(0, $unread);

        // HR sees the notice delivered but not yet read or opened.
        $this->actAs($this->hr);
        $this->getJson("/api/v1/employees/{$this->employee->id}")->assertOk()
            ->assertJsonPath('data.termination.notice.notification', 'unread')
            ->assertJsonPath('data.termination.notice.viewed_at', null)
            ->assertJsonPath('data.termination.notice.has_portal_account', true);

        // Reading the notification is one fact; opening the notice another.
        $this->actAs($this->login->fresh());
        $this->postJson("/api/v1/notifications/{$notice['id']}/read")->assertOk();
        $this->assertSame($unread - 1, $this->getJson('/api/v1/notifications/unread-count')->json('data.unread_count'));

        $employment = $this->getJson('/api/v1/portal/employment')->assertOk()
            ->assertJsonPath('data.status', 'terminated')
            ->assertJsonPath('data.separation.notice.effective_date', now()->toDateString())
            ->assertJsonPath('data.separation.notice.message', 'Your employment ends today following repeated absences without leave. Please come to the office for your clearance.')
            ->assertJsonPath('data.separation.notice.clearance.label', 'Not started yet')
            ->assertJsonPath('data.separation.notice.acknowledged_at', null);

        $overview = $this->getJson('/api/v1/portal')->assertOk()
            ->assertJsonPath('data.separation.kind', 'notice')
            ->assertJsonPath('data.separation.needs_attention', true);

        // The office's reason and remarks are nowhere in what the employee sees.
        foreach ([$employment, $overview, $this->getJson('/api/v1/notifications')] as $response) {
            $this->assertStringNotContainsString('Fourth AWOL', $response->getContent());
            $this->assertStringNotContainsString('competitor', $response->getContent());
        }

        $this->actAs($this->hr);
        $notice = $this->getJson("/api/v1/employees/{$this->employee->id}")->json('data.termination.notice');
        $this->assertSame('read', $notice['notification']);
        $this->assertNotNull($notice['viewed_at']);
        $this->assertNull($notice['acknowledged_at']);
    }

    /** D. */
    public function test_acknowledging_the_notice_records_receipt_once_and_changes_nothing_else(): void
    {
        $this->finalisedTermination();
        $this->actAs($this->login->fresh());

        $this->postJson('/api/v1/portal/separation-notice/acknowledge')->assertOk();
        $first = $this->employee->terminations()->sole()->notice_acknowledged_at;
        $this->assertNotNull($first);

        $this->travel(5)->minutes();
        $this->postJson('/api/v1/portal/separation-notice/acknowledge')->assertOk();

        $termination = $this->employee->terminations()->sole();
        $this->assertTrue($first->equalTo($termination->notice_acknowledged_at));
        $this->assertSame(1, SeparationEvent::where('event', 'notice_acknowledged')->count());
        $this->assertSame('terminated', $this->employee->fresh()->employment_status);
        $this->assertSame('finalized', $termination->status);
    }

    /** 10. */
    public function test_nothing_short_of_a_finalised_termination_sends_a_notice(): void
    {
        // A coordinator's violation report, submitted to HR.
        $this->actAs($this->coordinatorFor($this->clientA));
        $report = $this->postJson('/api/v1/coordinator/violation-reports', [
            'employee_id' => $this->employee->id,
            'violation_type' => 'awol',
            'incident_date' => now()->subDay()->toDateString(),
            'details' => 'Did not report for the morning shift.',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/coordinator/violation-reports/{$report}/submit")->assertOk();

        // A draft termination, one sent for review, and one withdrawn.
        $this->actAs($this->hr);
        $draft = $this->postJson("/api/v1/employees/{$this->employee->id}/terminations", [
            'reason' => 'Under consideration',
            'termination_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/employees/{$this->employee->id}/terminations/{$draft}/withdraw", ['reason' => 'Filed in error'])->assertOk();
        $this->postJson("/api/v1/employees/{$this->employee->id}/terminations", [
            'reason' => 'Awaiting the administrator',
            'termination_date' => now()->toDateString(),
            'status' => 'for_review',
        ])->assertCreated();

        $notifications = $this->employeeNotifications();
        $this->assertSame([], array_values(array_filter($notifications, fn ($n) => $n['category'] === 'employment')));

        $this->getJson('/api/v1/portal/employment')->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.separation.notice', null);
        $this->getJson('/api/v1/portal')->assertJsonPath('data.separation', null);

        $this->assertSame('active', $this->employee->fresh()->employment_status);
    }

    // ======================================================== after separation

    /** 3, 13. */
    public function test_a_former_employee_keeps_read_access_to_their_own_record_only(): void
    {
        $this->finalisedTermination();
        $this->actAs($this->login->fresh());

        // Their own record and notice remain readable.
        $this->getJson('/api/v1/portal/employment')->assertOk()
            ->assertJsonPath('data.separation.eligibility.can_request', false)
            ->assertJsonPath('data.separation.eligibility.reason', 'Your employment has already ended, so there is nothing to resign from.');
        $this->assertTrue($this->login->fresh()->is_active);

        // Nothing new can be filed against the ended employment.
        $this->request()->assertStatus(409);
        $this->assertDatabaseCount('resignations', 0);

        // And no staff record opens to them, theirs or anybody else's.
        $this->getJson("/api/v1/employees/{$this->employee->id}")->assertForbidden();
        $this->getJson('/api/v1/separations')->assertForbidden();
    }

    /** 14. */
    public function test_termination_clearance_follows_finalisation_and_is_told_once(): void
    {
        $this->actAs($this->hr);
        $id = $this->postJson("/api/v1/employees/{$this->employee->id}/terminations", [
            'reason' => 'Misconduct',
            'termination_date' => now()->toDateString(),
            'status' => 'for_review',
        ])->json('data.id');

        // Not before the decision.
        $this->putJson("/api/v1/employees/{$this->employee->id}/terminations/{$id}/clearance", ['clearance_status' => 'in_progress'])
            ->assertStatus(409);

        $this->actAs($this->admin);
        $this->postJson("/api/v1/employees/{$this->employee->id}/terminations/{$id}/finalise", ['confirm' => true])->assertOk();

        $this->actAs($this->hr);
        $this->putJson("/api/v1/employees/{$this->employee->id}/terminations/{$id}/clearance", ['clearance_status' => 'cleared'])->assertOk();
        $this->putJson("/api/v1/employees/{$this->employee->id}/terminations/{$id}/clearance", ['clearance_status' => 'cleared'])->assertOk();

        $titles = array_column($this->employeeNotifications(), 'title');
        $this->assertSame(1, count(array_keys($titles, 'Your clearance status has changed')));

        $this->getJson('/api/v1/portal/employment')
            ->assertJsonPath('data.separation.notice.clearance.label', 'Cleared');

        // A coordinator cannot record it.
        $this->actAs($this->coordinatorFor($this->clientA));
        $this->putJson("/api/v1/employees/{$this->employee->id}/terminations/{$id}/clearance", ['clearance_status' => 'pending'])
            ->assertForbidden();
    }

    public function test_hr_sees_portal_requests_apart_from_office_filings(): void
    {
        $this->actAs($this->login->fresh());
        $this->request()->assertCreated();

        $this->actAs($this->hr);
        $rows = $this->getJson('/api/v1/separations?source=portal&open_only=1')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('portal', $rows[0]['source']);
        $this->assertSame(now()->addDays(30)->toDateString(), $rows[0]['requested_exit_date']);

        $case = $this->getJson("/api/v1/employees/{$this->employee->id}")->json('data.resignation');
        $this->assertSame('portal', $case['source']);
        $this->assertSame(30, $case['notice_given_days']);
        $this->assertSame(['info_requested', 'accepted', 'withdrawn'], $case['allowed_statuses']);
        $this->assertTrue($case['history'][0]['by_employee']);
    }
}
