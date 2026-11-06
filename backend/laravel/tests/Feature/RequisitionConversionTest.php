<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\JobPosition;
use App\Models\JobRequest;
use App\Models\ManpowerRequisition;
use App\Services\DeploymentService;
use App\Services\ManpowerRequisitionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Support\CoordinatorFixtures;
use Tests\TestCase;

/**
 * Approving a coordinator's requisition creates its manpower request
 * (30 October 2026).
 *
 * What these hold the system to: HR never types the requisition in again;
 * what the form says is carried over and nothing is invented; a department or
 * position the form leaves open is settled by HR, never guessed; a new
 * position is only added on approval; one requisition is only ever one
 * request; a rejected or returned one creates nothing; the requisition itself
 * is never rewritten; and the request then fills from its own deployments.
 */
class RequisitionConversionTest extends TestCase
{
    use CoordinatorFixtures;
    use RefreshDatabase;

    private const QUALIFICATIONS = "At least 5'2\" in height. Can work for extended periods while standing.";

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpTwoClients();
    }

    /** A requisition on CDE's form, submitted to HR. */
    private function submitted(array $overrides = []): ManpowerRequisition
    {
        $this->actAs($this->coordinatorFor($this->clientA));

        $id = $this->postJson('/api/v1/coordinator/requisitions', array_merge([
            'date_requested' => now()->toDateString(),
            'required_by' => now()->addWeek()->toDateString(),
            'workers_needed' => 3,
            'male_count' => 3,
            'position_title' => 'SC Sealer',
            'qualifications' => self::QUALIFICATIONS,
            'other_remarks' => 'Day shift only.',
            'request_reason' => 'replacement',
            'request_reason_details' => 'Marinduque D.',
            'client_department_id' => $this->deptA->id,
            'submit' => true,
        ], $overrides))->assertCreated()->json('data.id');

        $this->actAs($this->hr);

        return ManpowerRequisition::findOrFail($id);
    }

    private function approve(ManpowerRequisition $requisition, array $extra = [])
    {
        return $this->postJson("/api/v1/requisitions/{$requisition->id}/review", ['action' => 'approve'] + $extra);
    }

    public function test_approval_creates_one_request_carrying_what_the_form_says(): void
    {
        $position = $this->jobPosition($this->clientA, $this->hr, ['position_title' => 'SC Sealer']);
        $requisition = $this->submitted(['job_position_id' => $position->id]);
        $before = $requisition->fresh()->only(['position_title', 'workers_needed', 'qualifications', 'other_remarks', 'client_department_id']);

        $this->getJson("/api/v1/requisitions/{$requisition->id}")->assertOk()
            ->assertJsonPath('data.review_actions', ['start_review', 'return', 'approve', 'reject'])
            ->assertJsonPath('data.conversion.needs_department', false)
            ->assertJsonPath('data.conversion.needs_position', false);

        $response = $this->approve($requisition, ['note' => 'Go.'])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.acknowledged_by.name', $this->hr->full_name)
            ->assertJsonPath('data.can_convert', false);

        $this->assertSame(1, JobRequest::count());
        $request = JobRequest::firstOrFail();
        $response->assertJsonPath('data.job_request.id', $request->id)
            ->assertJsonPath('data.job_request.request_code', $request->request_code)
            ->assertJsonPath('data.job_request.created_by', $this->hr->full_name);
        $this->assertStringContainsString($request->request_code, $response->json('message'));

        // The mapping.
        $this->assertSame($requisition->id, $request->manpower_requisition_id);
        $this->assertSame($request->id, $requisition->fresh()->job_request_id);
        $this->assertSame($this->clientA->id, $request->client_company_id);
        $this->assertSame($this->deptA->id, $request->client_department_id);
        $this->assertSame($position->id, $request->job_position_id);
        $this->assertSame('SC Sealer', $request->position_title);
        $this->assertSame(3, (int) $request->workers_needed);
        $this->assertSame(0, (int) $request->workers_fulfilled);
        $this->assertSame(3, $request->remaining_headcount);
        $this->assertSame('open', $request->request_status);
        $this->assertSame(now()->toDateString(), $request->date_requested->toDateString());
        $this->assertSame(now()->addWeek()->toDateString(), $request->deployment_deadline->toDateString());
        // All three asked for are men, so the form leaves no doubt.
        $this->assertSame('male', $request->gender_preference);
        $this->assertSame("Coordinator requisition {$requisition->requisition_code}", $request->request_source);
        $this->assertSame($this->hr->id, $request->created_by);
        $this->assertStringContainsString('Reason of Request: Replacement of Marinduque D.', $request->remarks);
        $this->assertStringContainsString('Required Qualification(s): '.self::QUALIFICATIONS, $request->remarks);
        $this->assertStringContainsString('Other Remarks: Day shift only.', $request->remarks);

        // Free-text qualifications are not split into structured requirements.
        foreach (['required_education', 'required_experience_months', 'required_certifications', 'age_min', 'age_max', 'height_min_cm', 'physical_requirement'] as $field) {
            $this->assertNull($request->{$field}, "{$field} was invented");
        }

        // The requisition is not rewritten.
        $this->assertSame($before, $requisition->fresh()->only(array_keys($before)));

        // Conversion is on the audit trail.
        $this->assertTrue(AuditLog::where('module_key', 'requisitions')->where('action_type', 'link')->exists());
    }

    public function test_the_request_shows_its_source_and_the_original_text_apart_from_criteria(): void
    {
        $requisition = $this->submitted();
        $this->approve($requisition, ['confirm_new_position' => true])->assertOk();
        $request = JobRequest::firstOrFail();

        $this->getJson('/api/v1/job-requests')->assertOk()
            ->assertJsonPath('data.0.source', 'requisition')
            ->assertJsonPath('data.0.source_requisition.requisition_code', $requisition->requisition_code);
        $this->assertSame(1, $this->getJson('/api/v1/job-requests?source=requisition')->json('meta.total'));
        $this->assertSame(0, $this->getJson('/api/v1/job-requests?source=direct')->json('meta.total'));

        $this->getJson("/api/v1/job-requests/{$request->id}")->assertOk()
            ->assertJsonPath('data.source_requisition.qualifications', self::QUALIFICATIONS)
            ->assertJsonPath('data.source_requisition.approved_by', $this->hr->full_name)
            ->assertJsonPath('data.source_requisition.requested_by', $requisition->requester->full_name);

        // HR sets criteria on the request; the requisition's words stay.
        $this->postJson("/api/v1/job-requests/{$request->id}/criteria", [
            'criteria' => [
                ['criteria_code' => 'age', 'mandatory_flag' => true, 'min_value' => 18, 'max_value' => 35],
                ['criteria_code' => 'experience', 'weight_score' => 60, 'min_value' => 0, 'max_value' => 24],
                ['criteria_code' => 'education', 'weight_score' => 40, 'expected_value' => 'high_school'],
            ],
        ])->assertOk();
        $this->postJson("/api/v1/job-requests/{$request->id}/evaluate")->assertOk();

        $this->assertSame(self::QUALIFICATIONS, $requisition->fresh()->qualifications);
        $this->getJson("/api/v1/job-requests/{$request->id}")
            ->assertJsonPath('data.source_requisition.qualifications', self::QUALIFICATIONS);
    }

    public function test_a_missing_department_is_chosen_by_hr_not_guessed(): void
    {
        $requisition = $this->submitted(['client_department_id' => null]);

        $this->getJson("/api/v1/requisitions/{$requisition->id}")
            ->assertJsonPath('data.conversion.needs_department', true);

        $this->approve($requisition, ['confirm_new_position' => true])
            ->assertStatus(422)->assertJsonValidationErrors('client_department_id');
        // Another client's department is refused too.
        $this->approve($requisition, ['confirm_new_position' => true, 'client_department_id' => $this->deptB->id])
            ->assertStatus(422)->assertJsonValidationErrors('client_department_id');

        // Nothing happened.
        $this->assertSame('submitted', $requisition->fresh()->status);
        $this->assertSame(0, JobRequest::count());
        $this->assertSame(0, JobPosition::where('position_title', 'SC Sealer')->count());

        $this->approve($requisition, ['confirm_new_position' => true, 'client_department_id' => $this->deptA->id])->assertOk()
            ->assertJsonPath('data.job_request.department_name', $this->deptA->department_name)
            ->assertJsonPath('data.job_request.department_set_by_hr', true);

        $this->assertSame($this->deptA->id, JobRequest::firstOrFail()->client_department_id);
        // The requisition still says what the coordinator said: nothing.
        $this->assertNull($requisition->fresh()->client_department_id);
        // Who chose it is on the audit trail.
        $link = AuditLog::where('module_key', 'requisitions')->where('action_type', 'link')->sole();
        $this->assertTrue($link->new_values_json['department_set_by_hr']);
        $this->assertSame('new_position', $link->new_values_json['position']);
        $this->assertSame($this->hr->id, $link->actor_user_id);
    }

    public function test_a_department_the_coordinator_named_cannot_be_swapped_on_approval(): void
    {
        $other = $this->department($this->clientA, $this->hr, ['department_name' => 'Packing', 'department_code' => 'PACK']);
        $requisition = $this->submitted();

        $this->approve($requisition, ['confirm_new_position' => true, 'client_department_id' => $other->id])
            ->assertStatus(422)->assertJsonValidationErrors('client_department_id');
        $this->assertSame(0, JobRequest::count());
    }

    public function test_a_new_position_is_only_added_when_hr_confirms_it_on_approval(): void
    {
        $existing = $this->jobPosition($this->clientA, $this->hr, ['position_title' => 'Packer']);
        $requisition = $this->submitted();

        $this->getJson("/api/v1/requisitions/{$requisition->id}")
            ->assertJsonPath('data.conversion.needs_position', true)
            ->assertJsonPath('data.conversion.matching_position', null);

        // Not added by being asked for.
        $this->assertSame(0, JobPosition::where('position_title', 'SC Sealer')->count());

        $this->approve($requisition)->assertStatus(422)->assertJsonValidationErrors('job_position_id');
        $this->assertSame(0, JobPosition::where('position_title', 'SC Sealer')->count());
        $this->assertSame('submitted', $requisition->fresh()->status);

        // HR may say it is one of the client's positions after all...
        $other = $this->submitted(['position_title' => 'Packer (night)']);
        $this->approve($other, ['job_position_id' => $existing->id])->assertOk();
        $this->assertSame($existing->id, JobRequest::where('manpower_requisition_id', $other->id)->value('job_position_id'));
        $this->assertSame('Packer (night)', $other->fresh()->position_title);

        // ...or confirm it is new, and only then is it added - once.
        $this->approve($requisition, ['confirm_new_position' => true])->assertOk();
        $this->assertSame(1, JobPosition::where('client_company_id', $this->clientA->id)->where('position_title', 'SC Sealer')->count());

        // Asked for again, the title now matches and nothing is created.
        $again = $this->submitted(['position_title' => 'sc sealer']);
        $this->getJson("/api/v1/requisitions/{$again->id}")->assertJsonPath('data.conversion.needs_position', false);
        $this->approve($again)->assertOk();
        $this->assertSame(1, JobPosition::whereRaw('LOWER(position_title) = ?', ['sc sealer'])->count());
    }

    public function test_a_rejected_requisition_creates_nothing(): void
    {
        $requisition = $this->submitted();

        $this->postJson("/api/v1/requisitions/{$requisition->id}/review", ['action' => 'reject', 'note' => 'Client cancelled.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.job_request', null);

        $this->assertSame(0, JobRequest::count());
        $this->assertSame(0, JobPosition::where('position_title', 'SC Sealer')->count());
        $this->assertSame('Client cancelled.', $requisition->fresh()->review_note);
        $this->assertSame($this->hr->id, $requisition->fresh()->reviewed_by);

        // And cannot be approved or converted afterwards.
        $this->approve($requisition, ['confirm_new_position' => true])->assertStatus(409);
        $this->postJson("/api/v1/requisitions/{$requisition->id}/convert", ['confirm_new_position' => true])->assertStatus(409);
        $this->assertSame(0, JobRequest::count());
    }

    public function test_a_returned_requisition_is_corrected_resubmitted_and_converted_with_its_history(): void
    {
        $requisition = $this->submitted(['workers_needed' => 2, 'male_count' => null]);
        $coordinator = $requisition->requester;

        $this->postJson("/api/v1/requisitions/{$requisition->id}/review", ['action' => 'return', 'note' => 'It is 4, not 2.'])->assertOk();
        $this->assertSame(0, JobRequest::count());

        $this->actAs($coordinator);
        $this->postJson("/api/v1/coordinator/requisitions/{$requisition->id}", ['workers_needed' => 4, 'submit' => true])
            ->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->actAs($this->hr);
        $this->approve($requisition, ['confirm_new_position' => true])->assertOk();

        $requisition->refresh();
        $this->assertSame(4, (int) JobRequest::firstOrFail()->workers_needed);
        // Neither all men nor all women: no gender preference is made up.
        $this->assertNull(JobRequest::firstOrFail()->gender_preference);
        $this->assertSame($requisition->requisition_code, ManpowerRequisition::find($requisition->id)->requisition_code);
        $this->assertSame(
            ['draft', 'submitted', 'returned', 'submitted', 'approved'],
            $requisition->history()->pluck('to_status')->all()
        );
        $this->assertSame('It is 4, not 2.', $requisition->history()->where('to_status', 'returned')->value('note'));
    }

    public function test_approving_again_never_creates_a_second_request(): void
    {
        $requisition = $this->submitted();

        $this->approve($requisition, ['confirm_new_position' => true])->assertOk();
        // Double click, retry, a second officer.
        $this->approve($requisition, ['confirm_new_position' => true])->assertStatus(409);
        $this->postJson("/api/v1/requisitions/{$requisition->id}/convert", ['confirm_new_position' => true])->assertStatus(409);
        $this->actAs($this->admin);
        $this->approve($requisition, ['confirm_new_position' => true])->assertStatus(409);
        // Opening it creates nothing.
        $this->getJson("/api/v1/requisitions/{$requisition->id}")->assertOk()->assertJsonPath('data.can_convert', false);

        $this->assertSame(1, JobRequest::count());
        $this->assertSame(1, $requisition->history()->where('to_status', 'approved')->count());
    }

    public function test_the_database_refuses_a_second_request_for_one_requisition(): void
    {
        $requisition = $this->submitted();
        $this->approve($requisition, ['confirm_new_position' => true])->assertOk();

        // Whatever slips past the lock - two approvals at the same instant on
        // a database without row locks - the unique index stops.
        $this->expectException(UniqueConstraintViolationException::class);
        $this->jobRequest($this->deptA, $this->hr, ['manpower_requisition_id' => $requisition->id]);
    }

    public function test_a_race_the_database_settles_is_reported_as_already_recorded(): void
    {
        $requisition = $this->submitted();

        // The other approval committed its request first, but this one read
        // the requisition before the link was written.
        $this->jobRequest($this->deptA, $this->hr, ['manpower_requisition_id' => $requisition->id]);
        ManpowerRequisition::whereKey($requisition->id)->update(['job_request_id' => null]);

        try {
            app(ManpowerRequisitionService::class)->review($requisition, $this->hr, 'approve', null, ['confirm_new_position' => true]);
            $this->fail('A second request was created.');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->assertSame(1, JobRequest::count());
        $this->assertSame('submitted', $requisition->fresh()->status);
    }

    public function test_only_hr_with_the_right_to_record_requests_can_approve(): void
    {
        $requisition = $this->submitted();
        $coordinator = $requisition->requester;

        // A coordinator never reaches HR's routes.
        $this->actAs($coordinator);
        $this->approve($requisition, ['confirm_new_position' => true])->assertForbidden();
        $this->postJson("/api/v1/requisitions/{$requisition->id}/convert")->assertForbidden();

        // Reviewing without the right to record a request leaves out "Approve".
        $this->hr->revokePermissionTo('job_requests.create');
        $this->hr->roles->each->revokePermissionTo('job_requests.create');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actAs($this->hr->fresh());
        $this->assertNotContains('approve', $this->getJson("/api/v1/requisitions/{$requisition->id}")->json('data.review_actions'));
        $this->approve($requisition, ['confirm_new_position' => true])->assertForbidden();

        $this->assertSame(0, JobRequest::count());
        $this->assertSame('submitted', $requisition->fresh()->status);
    }

    public function test_a_requisition_approved_before_this_change_is_converted_once(): void
    {
        $coordinator = $this->coordinatorFor($this->clientA);
        $legacy = ManpowerRequisition::create([
            'requisition_code' => 'MRF-2026-09990',
            'form_version' => 'cde-2026-10',
            'client_company_id' => $this->clientA->id,
            'position_title' => 'Utility Staff',
            'workers_needed' => 2,
            'female_count' => 2,
            'date_requested' => now()->toDateString(),
            'required_by' => now()->addWeek()->toDateString(),
            'request_reason' => 'new_position',
            'requested_by' => $coordinator->id,
            'status' => 'approved',
        ]);

        $this->actAs($this->hr);
        $this->getJson("/api/v1/requisitions/{$legacy->id}")->assertOk()
            ->assertJsonPath('data.can_convert', true)
            ->assertJsonPath('data.conversion.needs_department', true)
            ->assertJsonPath('data.conversion.needs_position', true);

        $this->postJson("/api/v1/requisitions/{$legacy->id}/convert", ['confirm_new_position' => true])
            ->assertStatus(422)->assertJsonValidationErrors('client_department_id');

        $this->postJson("/api/v1/requisitions/{$legacy->id}/convert", [
            'confirm_new_position' => true,
            'client_department_id' => $this->deptA->id,
        ])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.can_convert', false);

        $request = JobRequest::firstOrFail();
        $this->assertSame('female', $request->gender_preference);
        $this->assertSame($legacy->id, $request->manpower_requisition_id);

        $this->postJson("/api/v1/requisitions/{$legacy->id}/convert", ['client_department_id' => $this->deptA->id])->assertStatus(409);
        $this->assertSame(1, JobRequest::count());
    }

    public function test_a_converted_request_keeps_the_approved_position_and_headcount(): void
    {
        $requisition = $this->submitted();
        $this->approve($requisition, ['confirm_new_position' => true])->assertOk();
        $request = JobRequest::firstOrFail();

        $this->patchJson("/api/v1/job-requests/{$request->id}", ['workers_needed' => 9])->assertStatus(422);
        $this->patchJson("/api/v1/job-requests/{$request->id}", ['position_title' => 'Something else'])->assertStatus(422);
        // Unchanged values and the request's own details are fine.
        $this->patchJson("/api/v1/job-requests/{$request->id}", ['workers_needed' => 3, 'age_min' => 18, 'remarks' => 'Bring PPE.'])->assertOk();

        $this->assertSame(3, (int) $request->fresh()->workers_needed);
        $this->assertSame(18, (int) $request->fresh()->age_min);
    }

    public function test_deployments_fill_only_their_own_request(): void
    {
        $requisition = $this->submitted(['male_count' => null]);
        $this->approve($requisition, ['confirm_new_position' => true])->assertOk();
        $generated = JobRequest::firstOrFail();
        $other = $this->jobRequest($this->deptA, $this->hr, ['workers_needed' => 5]);

        // Shortlisting or endorsing fills nothing; a deployment does.
        $applicant = $this->applicant($this->hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $this->hr, 'all');
        $this->completeTraining($applicant, $this->hr);
        $this->assertSame(0, (int) $generated->fresh()->workers_fulfilled);

        app(DeploymentService::class)->deploy($applicant, $generated, ['deployment_date' => now()->toDateString()], $this->hr);

        $generated->refresh();
        $this->assertSame(1, (int) $generated->workers_fulfilled);
        $this->assertSame(2, $generated->remaining_headcount);
        $this->assertSame('partially_fulfilled', $generated->request_status);
        $this->assertSame(3, (int) $generated->workers_needed);
        $this->assertSame(0, (int) $other->fresh()->workers_fulfilled);

        // The same applicant cannot be counted twice.
        try {
            app(DeploymentService::class)->deploy($applicant->fresh(), $generated, ['deployment_date' => now()->toDateString()], $this->hr);
        } catch (RuntimeException) {
        }
        $this->assertSame(1, (int) $generated->fresh()->workers_fulfilled);
    }

    public function test_direct_requests_still_work_and_do_not_duplicate_a_requisition(): void
    {
        $payload = fn (array $o = []) => array_merge([
            'client_company_id' => $this->clientA->id,
            'client_department_id' => $this->deptA->id,
            'position_title' => 'Forklift Operator',
            'workers_needed' => 2,
            'date_requested' => now()->toDateString(),
            'request_source' => 'phone',
        ], $o);

        $this->actAs($this->hr);
        // A source is required.
        $this->postJson('/api/v1/job-requests', $payload(['request_source' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('request_source');

        $id = $this->postJson('/api/v1/job-requests', $payload())->assertCreated()
            ->assertJsonPath('data.source', 'direct')
            ->assertJsonPath('data.manpower_requisition_id', null)
            ->json('data.id');
        $this->assertNull(JobRequest::find($id)->manpower_requisition_id);

        // A requisition waiting on HR for the same client and position blocks a
        // direct copy of it, whatever the capitals.
        $requisition = $this->submitted();
        $this->postJson('/api/v1/job-requests', $payload(['position_title' => 'sc sealer']))
            ->assertStatus(409)->assertJsonFragment(['success' => false]);
        $this->assertStringContainsString(
            $requisition->requisition_code,
            $this->postJson('/api/v1/job-requests', $payload(['position_title' => 'SC Sealer']))->json('message')
        );

        // Another client, or another position, is not a duplicate.
        $this->postJson('/api/v1/job-requests', $payload(['position_title' => 'Packer']))->assertCreated();
        $this->postJson('/api/v1/job-requests', $payload([
            'client_company_id' => $this->clientB->id,
            'client_department_id' => $this->deptB->id,
            'position_title' => 'SC Sealer',
        ]))->assertCreated();

        // Once approved, the requisition has its request and the block lifts:
        // a further direct request is a separate need.
        $this->approve($requisition, ['confirm_new_position' => true])->assertOk();
        $this->postJson('/api/v1/job-requests', $payload(['position_title' => 'SC Sealer']))->assertCreated();
    }
}
