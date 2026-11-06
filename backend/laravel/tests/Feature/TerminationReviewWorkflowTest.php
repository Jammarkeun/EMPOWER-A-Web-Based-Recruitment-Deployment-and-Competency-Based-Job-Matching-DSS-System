<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\IncidentReport;
use App\Models\Termination;
use App\Models\TerminationClearanceItem;
use App\Models\User;
use App\Models\ViolationReport;
use App\Notifications\TerminationFinalised;
use App\Notifications\TerminationNotice;
use App\Notifications\TerminationRequestReviewed;
use App\Notifications\TerminationRequestSubmitted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\ProcessesTerminations;
use Tests\TestCase;

/**
 * The two-stage termination (2026-11-03): HR requests it, an administrator
 * approves, returns, or rejects the request, and HR finalises an approved
 * request only once its pre-termination clearance is complete.
 *
 * What these hold the system to: the administrators are told of a request
 * once, and nobody else is; approval changes nothing about the employee; the
 * clearance is checked by the server at the moment of finalisation, whatever
 * is sent; and the records HR reads before finalising - violations, incident
 * reports, obligations - are shown for what they are, and none of them
 * decides anything.
 */
class TerminationReviewWorkflowTest extends TestCase
{
    use CoordinatorFixtures;
    use ProcessesTerminations;
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoClients();

        // As the local demo runs: a relation read without being loaded fails.
        Model::preventLazyLoading();

        $this->employee = $this->placeEmployee($this->deptA, 'Ana', 'Reyes');
    }

    private function file(array $overrides = [], ?Employee $employee = null): int
    {
        $employee ??= $this->employee;
        $this->actAs($this->hr);

        return $this->postJson("/api/v1/employees/{$employee->id}/terminations", array_merge([
            'reason' => 'Fourth AWOL within twelve months',
            'termination_date' => now()->addDays(7)->toDateString(),
            'remarks' => 'Internal: supervisor statements on file.',
            'status' => 'for_review',
        ], $overrides))->assertCreated()->json('data.id');
    }

    private function url(int $id, string $action = ''): string
    {
        return "/api/v1/employees/{$this->employee->id}/terminations/{$id}".($action ? "/{$action}" : '');
    }

    private function review(int $id, string $decision, ?string $note = null, ?User $as = null)
    {
        $this->actAs($as ?? $this->admin);

        return $this->postJson($this->url($id, 'review'), array_filter(['decision' => $decision, 'note' => $note]));
    }

    private function item(int $id, string $key): int
    {
        return Termination::findOrFail($id)->clearanceItems()->where('item_key', $key)->value('id');
    }

    private function clear(int $id, string $key, array $data)
    {
        return $this->putJson($this->url($id, 'clearance-items/'.$this->item($id, $key)), $data);
    }

    /** @return \Illuminate\Support\Collection<int, DatabaseNotification> */
    private function sent(string $type)
    {
        return DatabaseNotification::query()->where('type', $type)->get();
    }

    private function portalLogin(): User
    {
        $user = User::factory()->create([
            'email' => 'portal.'.uniqid().'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $this->employee->applicant_id,
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('portal');

        return $user;
    }

    // ================================================== the administrator's notice

    /** Tests 1, 2, 18. */
    public function test_submitting_a_request_notifies_each_administrator_once_and_nobody_else(): void
    {
        $secondAdmin = $this->adminUser();
        $coordinator = $this->coordinatorFor($this->clientA);

        // A draft stays with HR: nobody is told yet.
        $draft = $this->file(['status' => 'draft']);
        $this->assertCount(0, $this->sent(TerminationRequestSubmitted::class));

        $this->actAs($this->hr);
        $this->postJson($this->url($draft, 'submit'))->assertOk();

        $sent = $this->sent(TerminationRequestSubmitted::class);
        $this->assertEqualsCanonicalizing([$this->admin->id, $secondAdmin->id], $sent->pluck('notifiable_id')->all());
        $this->assertTrue($sent->every(fn ($n) => $n->notifiable_type === User::class));

        $data = $sent->first()->data;
        $this->assertSame('Termination request pending review', $data['title']);
        $this->assertSame("/separations/terminations/{$draft}", $data['link']);
        $this->assertStringContainsString('TRM-', $data['body']);
        $this->assertStringContainsString('Ana Reyes', $data['body']);
        $this->assertStringContainsString($this->employee->employee_number, $data['body']);
        // The internal reason and remarks are not put into the notification.
        $this->assertStringNotContainsString('supervisor statements', $data['body']);

        // Opening, reloading, and submitting again send nothing more.
        $this->actAs($this->admin);
        $this->getJson("/api/v1/terminations/{$draft}")->assertOk();
        $this->getJson("/api/v1/terminations/{$draft}")->assertOk();
        $this->actAs($this->hr);
        $this->postJson($this->url($draft, 'submit'))->assertStatus(409);
        $this->assertCount(2, $this->sent(TerminationRequestSubmitted::class));

        // It is counted as unread for the administrator, and for nobody else.
        $this->actAs($this->admin);
        $this->assertSame(1, $this->getJson('/api/v1/notifications/unread-count')->assertOk()->json('data.unread_count'));
        $this->actAs($coordinator);
        $this->assertSame(0, $this->getJson('/api/v1/notifications/unread-count')->assertOk()->json('data.unread_count'));
        $this->assertSame(0, DatabaseNotification::query()->where('notifiable_type', Employee::class)
            ->where('type', '!=', \App\Notifications\DeploymentRecorded::class)->count());
    }

    /** Tests 3, 4. */
    public function test_the_notification_opens_the_request_and_only_staff_with_access_can_read_it(): void
    {
        $id = $this->file();
        $link = $this->sent(TerminationRequestSubmitted::class)->first()->data['link'];

        $this->actAs($this->admin);
        $this->getJson('/api/v1'.str_replace('/separations', '', $link))
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'for_review')
            ->assertJsonPath('data.employee.id', $this->employee->id)
            ->assertJsonPath('data.employee.full_name', 'Ana Reyes')
            ->assertJsonPath('data.filed_by', $this->hr->full_name)
            ->assertJsonPath('data.can.review', true)
            ->assertJsonPath('data.can.finalise', false);

        $this->actAs($this->coordinatorFor($this->clientA));
        $this->getJson("/api/v1/terminations/{$id}")->assertForbidden();

        $this->actAs($this->portalLogin());
        $this->getJson("/api/v1/terminations/{$id}")->assertForbidden();

        $this->actAs($this->admin);
        $this->getJson('/api/v1/terminations/999999')->assertNotFound();

        // A request reached through another employee's address is not found.
        $other = $this->placeEmployee($this->deptA, 'Ben', 'Cruz');
        $this->postJson("/api/v1/employees/{$other->id}/terminations/{$id}/review", ['decision' => 'approve'])->assertNotFound();
        $this->assertSame('for_review', Termination::find($id)->status);
    }

    // ================================================================= approval

    /** Tests 5, 6, 7, 18. */
    public function test_approval_returns_the_request_to_hr_without_terminating_anybody(): void
    {
        $id = $this->file();
        $deployment = $this->employee->activeDeployment();

        $this->review($id, 'approve')->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.stage', 'clearance_in_progress');

        $termination = Termination::find($id);
        $this->assertSame($this->admin->id, $termination->approved_by);
        $this->assertNotNull($termination->approved_at);
        $this->assertNull($termination->finalized_at);
        $this->assertSame($deployment->id, $termination->deployment_id);

        // Nothing about the employee has changed.
        $employee = $this->employee->fresh();
        $this->assertSame('active', $employee->employment_status);
        $this->assertSame('active', $employee->applicant->current_status);
        $this->assertSame('active', $deployment->fresh()->deployment_status);
        $this->assertDatabaseMissing('archives', ['entity_type' => 'employee', 'entity_id' => $employee->id]);
        $this->assertDatabaseMissing('employee_status_history', ['employee_id' => $employee->id, 'to_status' => 'terminated']);
        $this->assertCount(0, $this->sent(TerminationNotice::class));

        // The filer is told, once, with a link to the case.
        $told = $this->sent(TerminationRequestReviewed::class);
        $this->assertSame([$this->hr->id], $told->pluck('notifiable_id')->all());
        $this->assertSame('Termination request approved - clearance needed', $told->first()->data['title']);
        $this->assertSame("/separations/terminations/{$id}", $told->first()->data['link']);

        // HR sees the approved case with its checklist.
        $this->actAs($this->hr);
        $case = $this->getJson("/api/v1/terminations/{$id}")->assertOk()
            ->assertJsonPath('data.review.approved_by', $this->admin->full_name)
            ->assertJsonPath('data.can.update_clearance', true)
            ->assertJsonPath('data.can.finalise', false)
            ->assertJsonPath('data.can.review', false)
            ->json('data');
        $this->assertSame(
            ['outstanding_obligations', 'violation_history_reviewed'],
            array_column($case['clearance']['items'], 'key')
        );
        $this->assertSame(['pending_review'], array_values(array_unique(array_column($case['clearance']['items'], 'status'))));

        // The decision is in the case history, by the administrator.
        $this->assertDatabaseHas('separation_events', [
            'case_type' => 'termination', 'case_id' => $id, 'event' => 'approved', 'actor_id' => $this->admin->id,
            'visible_to_employee' => false,
        ]);
    }

    /** Tests 4, 19. */
    public function test_a_request_is_decided_once_and_not_by_the_person_who_filed_it(): void
    {
        // HR and coordinators cannot decide it.
        $id = $this->file();
        $this->review($id, 'approve', as: $this->hr)->assertForbidden();
        $this->review($id, 'approve', as: $this->coordinatorFor($this->clientA))->assertForbidden();
        $this->assertSame('for_review', Termination::find($id)->status);

        $this->review($id, 'approve')->assertOk();
        $this->review($id, 'approve')->assertStatus(409);
        $this->review($id, 'reject', 'Second thoughts')->assertStatus(409);

        $this->assertSame(1, Termination::find($id)->events()->where('event', 'approved')->count());
        $this->assertCount(1, $this->sent(TerminationRequestReviewed::class));
        $this->assertSame(2, Termination::find($id)->clearanceItems()->count());

        // An administrator who files a request cannot approve it themselves.
        $other = $this->placeEmployee($this->deptA, 'Ben', 'Cruz');
        $this->actAs($this->admin);
        $own = $this->postJson("/api/v1/employees/{$other->id}/terminations", [
            'reason' => 'Misconduct', 'termination_date' => now()->toDateString(), 'status' => 'for_review',
        ])->assertCreated()->json('data.id');
        $this->assertCount(0, $this->sent(TerminationRequestSubmitted::class)->where('notifiable_id', $this->admin->id)
            ->filter(fn ($n) => str_contains($n->data['link'], (string) $own)));

        $this->postJson("/api/v1/employees/{$other->id}/terminations/{$own}/review", ['decision' => 'approve'])
            ->assertForbidden();
        $this->getJson("/api/v1/terminations/{$own}")->assertJsonPath('data.can.review', false)
            ->assertJsonPath('data.can.review_blocked', 'You filed this request, so another administrator must review it.');
    }

    public function test_a_returned_request_is_corrected_by_hr_and_reviewed_again(): void
    {
        $id = $this->file();

        $this->review($id, 'return')->assertStatus(422);
        $this->review($id, 'return', 'Attach the supervisor\'s incident statements.')->assertOk()
            ->assertJsonPath('data.status', 'returned');

        $told = $this->sent(TerminationRequestReviewed::class)->first()->data;
        $this->assertSame('Termination request returned for correction', $told['title']);
        $this->assertStringContainsString('supervisor\'s incident statements', $told['body']);

        $this->actAs($this->hr);
        // Not finalisable, and not approvable, while it is with HR.
        $this->postJson($this->url($id, 'finalise'), ['confirm' => true])->assertStatus(409);
        $this->postJson($this->url($id), ['reason' => 'Fourth AWOL; statements attached'])->assertOk()
            ->assertJsonPath('data.reason', 'Fourth AWOL; statements attached');
        $this->postJson($this->url($id, 'submit'))->assertOk()->assertJsonPath('data.status', 'for_review');

        // Picked by title: notification ids are UUIDs, so rows come back in no particular order.
        $titles = $this->sent(TerminationRequestSubmitted::class)->pluck('data.title');
        $this->assertSame(1, $titles->filter(fn ($t) => $t === 'Termination request pending review')->count());
        $this->assertSame(1, $titles->filter(fn ($t) => $t === 'Corrected termination request pending review')->count());

        // Once with the administrator again, HR can no longer edit it.
        $this->postJson($this->url($id), ['reason' => 'Changed afterwards'])->assertStatus(409);
        $this->review($id, 'approve')->assertOk();
    }

    public function test_a_rejected_request_is_closed_and_the_employee_stays_active(): void
    {
        $id = $this->file();

        $this->review($id, 'reject')->assertStatus(422);
        $this->review($id, 'reject', 'The absences were covered by approved leave.')->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.is_open', false);

        $this->actAs($this->hr);
        $this->postJson($this->url($id, 'submit'))->assertStatus(409);
        $this->postJson($this->url($id), ['reason' => 'x'])->assertStatus(409);
        $this->postJson($this->url($id, 'finalise'), ['confirm' => true])->assertStatus(409);
        $this->postJson($this->url($id, 'withdraw'), ['reason' => 'x'])->assertStatus(400);

        $this->assertSame('active', $this->employee->fresh()->employment_status);
        $this->assertSame('Termination request rejected', $this->sent(TerminationRequestReviewed::class)->first()->data['title']);

        // A closed request no longer holds the one-open-case guard.
        $this->file();
    }

    // ================================================================ clearance

    /** Tests 9, 13, 15, 16. */
    public function test_finalisation_is_refused_until_every_mandatory_item_is_resolved(): void
    {
        $id = $this->file();
        $this->review($id, 'approve')->assertOk();
        $this->actAs($this->hr);

        $this->postJson($this->url($id, 'finalise'), ['confirm' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('clearance');

        // An obligation with no written basis is not cleared.
        $this->clear($id, 'outstanding_obligations', ['status' => 'cleared'])->assertStatus(422);
        $this->clear($id, 'outstanding_obligations', [
            'status' => 'requires_action',
            'remarks' => 'Accounting reports a cash advance of 2,000 not yet settled.',
        ])->assertOk();
        $this->clear($id, 'violation_history_reviewed', ['status' => 'cleared'])->assertOk();

        $errors = $this->postJson($this->url($id, 'finalise'), ['confirm' => true])
            ->assertStatus(422)->json('errors.clearance');
        $this->assertContains('Outstanding obligations verified: requires action', $errors);
        $this->assertNotContains('Violation history reviewed: reviewed', $errors);
        $this->assertSame('active', $this->employee->fresh()->employment_status);

        $this->clear($id, 'outstanding_obligations', [
            'status' => 'cleared',
            'remarks' => 'Accounting confirms the advance will be settled from the final pay, as CDE does.',
        ])->assertOk();

        $this->getJson("/api/v1/terminations/{$id}")->assertJsonPath('data.stage', 'ready_for_finalization')
            ->assertJsonPath('data.can.finalise', true)
            ->assertJsonPath('data.clearance.outstanding', []);

        $this->postJson($this->url($id, 'finalise'), ['confirm' => true])->assertOk();
        $this->assertSame('terminated', $this->employee->fresh()->employment_status);
    }

    /** Test 14. */
    public function test_not_applicable_needs_a_reason_and_a_record_review_cannot_be_skipped(): void
    {
        $id = $this->file();
        $this->review($id, 'approve')->assertOk();
        $this->actAs($this->hr);

        $this->clear($id, 'outstanding_obligations', ['status' => 'not_applicable'])->assertStatus(422);
        $this->clear($id, 'outstanding_obligations', ['status' => 'not_applicable', 'remarks' => 'Hired last week; no advance or loan was possible.'])
            ->assertOk();

        $item = Termination::find($id)->clearanceItems()->where('item_key', 'outstanding_obligations')->first();
        $this->assertSame('not_applicable', $item->status);
        $this->assertSame($this->hr->id, $item->verified_by);
        $this->assertNotNull($item->verified_at);

        // A record is reviewed, or not yet: never "not applicable".
        $this->clear($id, 'violation_history_reviewed', ['status' => 'not_applicable', 'remarks' => 'Skip'])->assertStatus(422);
        $this->clear($id, 'violation_history_reviewed', ['status' => 'requires_action'])->assertStatus(422);

        // Moving an item back clears its verification.
        $this->clear($id, 'outstanding_obligations', ['status' => 'pending_review'])->assertOk();
        $this->assertNull($item->fresh()->verified_by);

        // Nobody without the processing permission records clearance.
        $this->actAs($this->coordinatorFor($this->clientA));
        $this->clear($id, 'violation_history_reviewed', ['status' => 'cleared'])->assertForbidden();
        $this->actAs($this->portalLogin());
        $this->clear($id, 'violation_history_reviewed', ['status' => 'cleared'])->assertForbidden();
    }

    /** Test 15. */
    public function test_direct_requests_cannot_bypass_the_approval_or_the_clearance(): void
    {
        $this->actAs($this->hr);
        // A request cannot be filed already approved or finalised.
        $this->postJson("/api/v1/employees/{$this->employee->id}/terminations", [
            'reason' => 'x', 'termination_date' => now()->toDateString(), 'status' => 'approved',
        ])->assertStatus(422);
        $this->postJson("/api/v1/employees/{$this->employee->id}/terminations", [
            'reason' => 'x', 'termination_date' => now()->toDateString(), 'status' => 'finalized',
        ])->assertStatus(422);

        $id = $this->file(['status' => 'draft']);
        // Status, approver and clearance are not fields HR can send.
        $this->postJson($this->url($id), [
            'status' => 'approved', 'approved_by' => $this->hr->id, 'clearance_status' => 'cleared', 'reason' => 'Updated',
        ])->assertOk();
        $termination = Termination::find($id);
        $this->assertSame('draft', $termination->status);
        $this->assertNull($termination->approved_by);
        $this->assertNull($termination->clearance_status);

        $this->postJson($this->url($id, 'finalise'), ['confirm' => true])->assertStatus(409);

        // A clearance item from another case is not reachable through this one.
        $other = $this->placeEmployee($this->deptA, 'Ben', 'Cruz');
        $this->actAs($this->hr);
        $otherId = $this->postJson("/api/v1/employees/{$other->id}/terminations", [
            'reason' => 'Misconduct', 'termination_date' => now()->toDateString(), 'status' => 'for_review',
        ])->json('data.id');
        $this->actAs($this->admin);
        $this->postJson("/api/v1/employees/{$other->id}/terminations/{$otherId}/review", ['decision' => 'approve'])->assertOk();
        $foreign = Termination::find($otherId)->clearanceItems()->first();

        $this->actAs($this->hr);
        $this->postJson($this->url($id, 'submit'))->assertOk();
        $this->review($id, 'approve')->assertOk();
        $this->actAs($this->hr);
        $this->putJson($this->url($id, "clearance-items/{$foreign->id}"), ['status' => 'cleared', 'remarks' => 'x'])->assertNotFound();
        $this->assertSame('pending_review', $foreign->fresh()->status);
    }

    // ============================================================= finalisation

    /** Tests 16, 17, 18, 19. */
    public function test_finalising_ends_the_employment_once_keeps_its_history_and_tells_the_right_people(): void
    {
        $id = $this->file(['termination_date' => now()->toDateString()]);
        $this->approveAndClearTermination($this->employee->id, $id, $this->hr);
        $deployment = $this->employee->activeDeployment();

        $this->postJson($this->url($id, 'finalise'))->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->postJson($this->url($id, 'finalise'), ['confirm' => true, 'employee_message' => 'Please come to the office.'])
            ->assertOk();

        $termination = Termination::find($id);
        $this->assertSame('finalized', $termination->status);
        $this->assertSame($this->hr->id, $termination->finalized_by);
        $this->assertNotSame($this->hr->id, $termination->approved_by);
        $this->assertSame('cleared', $termination->clearance_status);
        $this->assertNull($termination->open_employee_id);

        $employee = $this->employee->fresh();
        $this->assertSame('terminated', $employee->employment_status);
        $this->assertSame('terminated', $employee->applicant->current_status);
        $this->assertSame('ended', $deployment->fresh()->deployment_status);
        $this->assertSame(now()->toDateString(), $deployment->fresh()->end_date->toDateString());
        // History kept, nothing deleted.
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('deployments', ['id' => $deployment->id]);
        $this->assertDatabaseHas('employee_status_history', ['employee_id' => $employee->id, 'from_status' => 'active', 'to_status' => 'terminated']);
        $this->assertDatabaseCount('archives', 1);

        // The employee is told once; the approving administrator is told it was finalised.
        $this->assertCount(1, $this->sent(TerminationNotice::class));
        $finalised = $this->sent(TerminationFinalised::class);
        $this->assertContains($termination->approved_by, $finalised->pluck('notifiable_id')->all());
        $this->assertNotContains($this->hr->id, $finalised->pluck('notifiable_id')->all());

        // A second attempt changes nothing and sends nothing.
        $this->postJson($this->url($id, 'finalise'), ['confirm' => true])->assertStatus(409);
        $this->clear($id, 'violation_history_reviewed', ['status' => 'pending_review'])->assertStatus(409);
        $this->assertDatabaseCount('archives', 1);
        $this->assertSame(1, $employee->statusHistory()->where('to_status', 'terminated')->count());
        $this->assertCount(1, $this->sent(TerminationNotice::class));
        $this->assertCount(1, $this->sent(TerminationFinalised::class));
    }

    public function test_an_employee_who_left_another_way_cannot_be_terminated_on_an_old_approval(): void
    {
        $id = $this->file();
        $this->review($id, 'approve')->assertOk();

        // Meanwhile the employee resigned and the resignation was completed.
        $this->employee->forceFill(['employment_status' => 'resigned'])->save();

        $this->approveAndClearTerminationItems($id);
        $this->postJson($this->url($id, 'finalise'), ['confirm' => true])->assertStatus(409);
        $this->assertSame('approved', Termination::find($id)->status);
        $this->assertCount(0, $this->sent(TerminationNotice::class));
    }

    private function approveAndClearTerminationItems(int $id): void
    {
        $this->actAs($this->hr);
        foreach (['outstanding_obligations', 'violation_history_reviewed'] as $key) {
            $this->clear($id, $key, ['status' => 'cleared', 'remarks' => 'Verified.'])->assertOk();
        }
    }

    // ============================================== what HR reads before deciding

    /** Tests 8, 10. */
    public function test_obligations_are_reported_as_unrecorded_never_as_none_owed(): void
    {
        $id = $this->file();
        $this->review($id, 'approve')->assertOk();
        $this->actAs($this->hr);

        $case = $this->getJson("/api/v1/terminations/{$id}")->assertOk()->json('data');
        $this->assertFalse($case['obligations']['records_available']);
        $this->assertStringContainsString('does not keep loan', $case['obligations']['message']);

        $obligation = collect($case['clearance']['items'])->firstWhere('key', 'outstanding_obligations');
        $this->assertTrue($obligation['requires_remarks']);
        $this->assertTrue($obligation['is_mandatory']);
        $this->assertSame('pending_review', $obligation['status']);

        // Saved with no reference; one sent anyway is not stored.
        $this->clear($id, 'outstanding_obligations', ['status' => 'cleared', 'remarks' => 'Accounting: nothing owed.', 'reference' => 'ACCT-114'])
            ->assertOk();
        $this->assertNull(Termination::find($id)->clearanceItems()->where('item_key', 'outstanding_obligations')->value('reference'));

        $obligation = collect($this->getJson("/api/v1/terminations/{$id}")->json('data.clearance.items'))
            ->firstWhere('key', 'outstanding_obligations');
        $this->assertSame('Accounting: nothing owed.', $obligation['remarks']);
        $this->assertArrayNotHasKey('reference', $obligation);
        $this->assertSame($this->hr->full_name, $obligation['verified_by']);
    }

    /** Test 11. */
    public function test_violation_history_is_shown_apart_from_unvalidated_reports_and_decides_nothing(): void
    {
        foreach (range(1, 4) as $n) {
            EmployeeViolation::create([
                'employee_id' => $this->employee->id,
                'client_company_id' => $this->clientA->id,
                'violation_date' => now()->subDays($n)->toDateString(),
                'violation_type' => 'awol',
                'description' => "Absent without leave ({$n})",
                'issued_by' => $this->hr->id,
                'status' => 'open',
            ]);
        }
        foreach (['submitted' => 'VR-1', 'dismissed' => 'VR-2', 'draft' => 'VR-3'] as $status => $code) {
            ViolationReport::create([
                'report_code' => $code,
                'employee_id' => $this->employee->id,
                'client_company_id' => $this->clientA->id,
                'reported_by' => $this->admin->id,
                'violation_type' => 'late',
                'incident_date' => now()->subDays(2)->toDateString(),
                'details' => 'Reported late',
                'status' => $status,
            ]);
        }

        // Reaching the threshold terminates nobody, and opens no case.
        $this->assertSame('active', $this->employee->fresh()->employment_status);
        $this->assertDatabaseCount('terminations', 0);

        $id = $this->file();
        $this->actAs($this->admin);
        $violations = $this->getJson("/api/v1/terminations/{$id}")->assertOk()->json('data.violations');

        $this->assertCount(4, $violations['official']);
        // Enough to read each one in full, and no storage path.
        $first = $violations['official'][0];
        $this->assertSame('Absent without leave (1)', $first['description']);
        $this->assertSame($this->hr->full_name, $first['recorded_by']);
        $this->assertFalse($first['has_evidence']);
        $this->assertNotNull($first['expires_on']);
        $this->assertArrayNotHasKey('evidence_path', $first);
        $this->assertSame(4, $violations['active_count']);
        // The coordinator's draft is theirs; the others are listed as not validated.
        $this->assertSame(['VR-1', 'VR-2'], collect($violations['unvalidated_reports'])->pluck('report_code')->sort()->values()->all());

        $this->assertSame('for_review', Termination::find($id)->status);
        $this->assertSame(4, EmployeeViolation::where('employee_id', $this->employee->id)->where('status', 'open')->count());
    }

    /** Test 12. */
    public function test_incident_reports_are_context_from_the_placement_and_never_the_employees(): void
    {
        $make = fn (string $code, $company, $department, $date, string $status = 'submitted') => IncidentReport::create([
            'report_code' => $code,
            'client_company_id' => $company->id,
            'client_department_id' => $department?->id,
            'reported_by' => $this->admin->id,
            'title' => "Incident {$code}",
            'incident_date' => $date,
            'details' => 'Trucks did not run; workers sent home.',
            'status' => $status,
        ]);

        $make('IR-HERE', $this->clientA, $this->deptA, now()->subWeek()->toDateString());
        $make('IR-SITE', $this->clientA, null, now()->subDays(3)->toDateString(), 'resolved');
        $make('IR-OTHER-CLIENT', $this->clientB, $this->deptB, now()->subWeek()->toDateString());
        $make('IR-BEFORE', $this->clientA, $this->deptA, now()->subYear()->toDateString());
        $make('IR-DRAFT', $this->clientA, $this->deptA, now()->subDay()->toDateString(), 'draft');

        $id = $this->file();
        $this->actAs($this->admin);
        $incidents = $this->getJson("/api/v1/terminations/{$id}")->assertOk()->json('data.incidents');

        $this->assertEqualsCanonicalizing(['IR-HERE', 'IR-SITE'], array_column($incidents['context'], 'report_code'));
        $this->assertStringContainsString('none is attributed to this employee', $incidents['note']);
        foreach ($incidents['context'] as $row) {
            $this->assertArrayNotHasKey('employee_id', $row);
        }

        // Nothing about them enters the clearance, and nothing becomes a violation.
        $this->review($id, 'approve')->assertOk();
        $keys = Termination::find($id)->clearanceItems()->pluck('item_key')->all();
        $this->assertStringNotContainsString('incident', implode(',', $keys));
        $this->approveAndClearTerminationItems($id);
        $this->postJson($this->url($id, 'finalise'), ['confirm' => true])->assertOk();
        $this->assertDatabaseCount('employee_violations', 0);
        $this->assertSame(5, IncidentReport::count());
    }

    // ============================================== the removed office clearance

    /**
     * An office-by-office signed clearance was a checklist item until
     * 2026-11-03, when it was removed as our assumption rather than CDE's
     * process. A request approved before then still has its row; the row
     * stays on file, but no longer shows, counts, or holds up finalisation.
     */
    public function test_a_request_approved_with_the_removed_office_item_is_not_held_up_by_it(): void
    {
        $id = $this->file();
        $this->review($id, 'approve')->assertOk();

        // What approval wrote while the item was still on the checklist.
        $retired = TerminationClearanceItem::create([
            'termination_id' => $id,
            'item_key' => 'office_clearance',
            'label' => 'Clearance signed by each office concerned',
            'kind' => 'clearance',
            'is_mandatory' => true,
            'status' => 'pending_review',
            'remarks' => 'Started before the change.',
        ]);

        $this->actAs($this->hr);
        $case = $this->getJson("/api/v1/terminations/{$id}")->assertOk()->json('data');
        $this->assertSame(['outstanding_obligations', 'violation_history_reviewed'], array_column($case['clearance']['items'], 'key'));
        $this->assertCount(2, $case['clearance']['outstanding']);
        $this->assertStringNotContainsString('signed by each office', json_encode($case));

        // It cannot be edited either.
        $this->putJson($this->url($id, "clearance-items/{$retired->id}"), ['status' => 'cleared'])->assertStatus(409);

        // Only the two remaining items are named as outstanding.
        $errors = $this->postJson($this->url($id, 'finalise'), ['confirm' => true])->assertStatus(422)->json('errors.clearance');
        $this->assertCount(3, $errors, 'The heading and the two remaining items, and no third.');
        $this->approveAndClearTerminationItems($id);
        $this->postJson($this->url($id, 'finalise'), ['confirm' => true])->assertOk();

        // The old row is kept exactly as it was, and the case still opens.
        $this->assertDatabaseHas('termination_clearance_items', [
            'id' => $retired->id, 'status' => 'pending_review', 'remarks' => 'Started before the change.',
        ]);
        $this->getJson("/api/v1/terminations/{$id}")->assertOk()->assertJsonPath('data.status', 'finalized');
    }

    // ===================================================================== lists

    public function test_the_separations_list_groups_terminations_by_stage(): void
    {
        $waiting = $this->file();
        $approvedEmployee = $this->placeEmployee($this->deptA, 'Ben', 'Cruz');
        $approved = $this->file([], $approvedEmployee);
        $this->actAs($this->admin);
        $this->postJson("/api/v1/employees/{$approvedEmployee->id}/terminations/{$approved}/review", ['decision' => 'approve'])->assertOk();

        $ids = fn (string $stage) => collect($this->getJson("/api/v1/separations?type=termination&stage={$stage}")
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$waiting], $ids('awaiting_review'));
        $this->assertSame([$approved], $ids('approved'));
        $this->assertSame([], $ids('finalized'));

        $row = collect($this->getJson('/api/v1/separations?type=termination')->json('data'))->firstWhere('id', $approved);
        $this->assertSame('clearance_in_progress', $row['stage']);
        $this->assertSame(2, $row['clearance_outstanding']);
        $this->assertSame($this->hr->full_name, $row['filed_by']);

        $this->getJson('/api/v1/separations?stage=nonsense')->assertStatus(422);
    }

    public function test_only_one_open_request_per_employee_at_any_stage(): void
    {
        $id = $this->file();
        $this->actAs($this->hr);
        $this->postJson("/api/v1/employees/{$this->employee->id}/terminations", [
            'reason' => 'Again', 'termination_date' => now()->toDateString(), 'status' => 'for_review',
        ])->assertStatus(400);

        $this->review($id, 'approve')->assertOk();
        $this->actAs($this->hr);
        $this->postJson("/api/v1/employees/{$this->employee->id}/terminations", [
            'reason' => 'Again', 'termination_date' => now()->toDateString(),
        ])->assertStatus(400);

        $this->assertDatabaseCount('terminations', 1);
    }
}
