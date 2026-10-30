<?php

namespace Tests\Feature;

use App\Models\JobRequest;
use App\Models\ManpowerRequisition;
use App\Models\ManpowerRequisitionStatusHistory;
use App\Notifications\RequisitionReviewed;
use App\Notifications\RequisitionSubmitted;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CoordinatorFixtures;
use Tests\TestCase;

/**
 * The coordinator's manpower requisition, on CDE's own Manpower Requisition
 * Form (received 29 October 2026).
 *
 * What these hold the system to: the form is CDE's - its fields, its Reason of
 * Request boxes, nothing the temporary default form added; the client comes
 * from the coordinator's assignment; submitting approves nothing; only HR
 * moves it on, never the person who filed it, and whoever approves it is the
 * form's "Acknowledged by"; every change is kept; requisitions filed on the
 * temporary form keep what they recorded; and an approved requisition becomes
 * a manpower request through the ordinary request rules, linked both ways.
 */
class ManpowerRequisitionTest extends TestCase
{
    use CoordinatorFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoClients();
    }

    /** CDE's sample, as a coordinator would enter it. */
    private function requisition(array $overrides = []): array
    {
        return array_merge([
            'date_requested' => now()->toDateString(),
            'required_by' => now()->addDay()->toDateString(),
            'workers_needed' => 1,
            'male_count' => 1,
            'position_title' => 'SC Sealer',
            'qualifications' => "At least 5'2\" in height. Can work for extended periods while standing.",
            'request_reason' => 'replacement',
            'request_reason_details' => 'Marinduque D.',
            'client_department_id' => $this->deptA->id,
        ], $overrides);
    }

    private function submitted(?\App\Models\User $coordinator = null): int
    {
        $this->actAs($coordinator ?? $this->coordinatorFor($this->clientA));

        return $this->postJson('/api/v1/coordinator/requisitions', $this->requisition(['submit' => true]))
            ->assertCreated()->json('data.id');
    }

    /** A requisition filed before CDE's form arrived, as the temporary form stored it. */
    private function temporaryFormRequisition(\App\Models\User $coordinator, array $overrides = []): ManpowerRequisition
    {
        return ManpowerRequisition::create(array_merge([
            'requisition_code' => 'MRF-2026-09999',
            'form_version' => 'provisional-2026-10',
            'client_company_id' => $this->clientA->id,
            'client_department_id' => $this->deptA->id,
            'position_title' => 'Production Helper',
            'workers_needed' => 10,
            'date_requested' => now()->subWeek()->toDateString(),
            'required_by' => now()->addWeek()->toDateString(),
            'reason' => 'Additional line for the holiday orders.',
            'gender_requirement' => 'as_specified',
            'male_count' => 6,
            'female_count' => 4,
            'employment_type' => 'contractual',
            'work_schedule' => 'Night shift',
            'client_requester_name' => 'Plant Manager',
            'requested_by' => $coordinator->id,
            'status' => 'approved',
        ], $overrides));
    }

    public function test_the_form_is_cdes_with_its_reason_of_request_boxes_in_order(): void
    {
        $this->actAs($this->coordinatorFor($this->clientA));

        $form = $this->getJson('/api/v1/coordinator/requisitions/options')->assertOk()->json('data');

        $this->assertFalse($form['form']['provisional']);
        $this->assertSame('cde-2026-10', $form['form']['version']);
        $this->assertSame('Manpower Requisition Form', $form['form']['title']);
        $this->assertSame(
            ['New Position', 'Replacement', 'Additional Manpower', 'Reliever Manpower', 'Others'],
            array_column($form['form']['reasons'], 'label')
        );
        $this->assertArrayNotHasKey('optional_fields', $form['form']);
        // Only this client's departments.
        $this->assertSame([$this->deptA->id], array_column($form['departments'], 'id'));
    }

    public function test_a_coordinator_files_a_requisition_for_their_own_client_only(): void
    {
        Notification::fake();
        $coordinator = $this->coordinatorFor($this->clientA);
        $this->actAs($coordinator);

        $response = $this->postJson('/api/v1/coordinator/requisitions', $this->requisition([
            'client_company_id' => $this->clientB->id,
            'other_remarks' => 'Heavy lifting.',
            // The temporary form's fields are not on CDE's form, so ignored.
            'reason' => 'Free text',
            'gender_requirement' => 'male',
            'employment_type' => 'contractual',
            'work_schedule' => 'Night shift',
            'compensation_notes' => 'PHP 610 a day',
            'client_requester_name' => 'Plant Manager',
            'status' => 'approved',
            'requested_by' => $this->hr->id,
        ]))->assertCreated();

        $requisition = ManpowerRequisition::findOrFail($response->json('data.id'));
        $this->assertSame($this->clientA->id, $requisition->client_company_id);
        $this->assertSame($coordinator->id, $requisition->requested_by);
        $this->assertSame('draft', $requisition->status);
        $this->assertSame('cde-2026-10', $requisition->form_version);
        $this->assertSame('replacement', $requisition->request_reason);
        $this->assertSame('Marinduque D.', $requisition->request_reason_details);
        $this->assertSame('Heavy lifting.', $requisition->other_remarks);
        $this->assertSame(1, $requisition->male_count);
        $this->assertNull($requisition->female_count);
        foreach (['reason', 'gender_requirement', 'employment_type', 'work_schedule', 'compensation_notes', 'client_requester_name'] as $retired) {
            $this->assertNull($requisition->{$retired}, "{$retired} is not on CDE's form");
        }

        $response->assertJsonPath('data.provisional', false)
            ->assertJsonPath('data.request_reason_label', 'Replacement')
            ->assertJsonPath('data.requested_by.name', $coordinator->full_name)
            ->assertJsonPath('data.acknowledged_by', null);
        $this->assertMatchesRegularExpression('/^MRF-\d{4}-\d{5}$/', $requisition->requisition_code);
        Notification::assertNothingSent();

        // Another client's department or position is refused.
        $this->postJson('/api/v1/coordinator/requisitions', $this->requisition(['client_department_id' => $this->deptB->id]))
            ->assertForbidden();
        $positionB = $this->jobPosition($this->clientB, $this->hr, ['position_title' => 'Assembler']);
        $this->postJson('/api/v1/coordinator/requisitions', $this->requisition(['job_position_id' => $positionB->id]))
            ->assertForbidden();

        // A position from the client's list fixes the title.
        $positionA = $this->jobPosition($this->clientA, $this->hr, ['position_title' => 'Packer']);
        $this->postJson('/api/v1/coordinator/requisitions', $this->requisition(['job_position_id' => $positionA->id, 'position_title' => null]))
            ->assertCreated()->assertJsonPath('data.position_title', 'Packer');

        // Another client's coordinator sees none of it.
        $this->actAs($this->coordinatorFor($this->clientB));
        $this->getJson("/api/v1/coordinator/requisitions/{$requisition->id}")->assertForbidden();
        $this->postJson("/api/v1/coordinator/requisitions/{$requisition->id}", ['other_remarks' => 'x'])->assertForbidden();
        $this->assertSame(0, $this->getJson('/api/v1/coordinator/requisitions')->json('meta.total'));
    }

    public function test_the_required_fields_and_the_cross_field_rules_are_enforced(): void
    {
        $this->actAs($this->coordinatorFor($this->clientA));

        $this->postJson('/api/v1/coordinator/requisitions', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_requested', 'required_by', 'workers_needed', 'position_title', 'request_reason'])
            ->assertJsonPath('errors.request_reason.0', 'Tick the Reason of Request.')
            ->assertJsonPath('errors.required_by.0', 'Enter the Date Needed.');

        $this->postJson('/api/v1/coordinator/requisitions', $this->requisition(['workers_needed' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('workers_needed');

        $this->postJson('/api/v1/coordinator/requisitions', $this->requisition(['date_requested' => now()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('date_requested');

        $this->postJson('/api/v1/coordinator/requisitions', $this->requisition([
            'date_requested' => now()->toDateString(),
            'required_by' => now()->subDay()->toDateString(),
        ]))->assertStatus(422)->assertJsonPath('errors.required_by.0', 'The Date Needed cannot be before the Date requested.');

        // More men and women than the Total Manpower Needed.
        $this->postJson('/api/v1/coordinator/requisitions', $this->requisition([
            'workers_needed' => 5, 'male_count' => 4, 'female_count' => 2,
        ]))->assertStatus(422)->assertJsonValidationErrors('male_count');

        // Only the form's boxes can be ticked, and "Others" needs its blank filled.
        $this->postJson('/api/v1/coordinator/requisitions', $this->requisition(['request_reason' => 'seasonal']))
            ->assertStatus(422)->assertJsonValidationErrors('request_reason');
        $this->postJson('/api/v1/coordinator/requisitions', $this->requisition(['request_reason' => 'others', 'request_reason_details' => null]))
            ->assertStatus(422)->assertJsonPath('errors.request_reason_details.0', 'Write the reason beside "Others".');

        $this->assertSame(0, ManpowerRequisition::count());

        // Optional: the qualifications, the remarks, the split, the department
        // and whom a replacement is for.
        $this->postJson('/api/v1/coordinator/requisitions', [
            'date_requested' => now()->toDateString(),
            'required_by' => now()->addWeek()->toDateString(),
            'workers_needed' => 3,
            'position_title' => 'Packer',
            'request_reason' => 'additional_manpower',
        ])->assertCreated()->assertJsonPath('data.qualifications', null);
    }

    public function test_only_replacement_and_others_keep_what_is_written_beside_the_tick(): void
    {
        $this->actAs($this->coordinatorFor($this->clientA));

        $id = $this->postJson('/api/v1/coordinator/requisitions', $this->requisition([
            'request_reason' => 'additional_manpower',
            'request_reason_details' => 'Stray text',
        ]))->assertCreated()->assertJsonPath('data.request_reason_details', null)->json('data.id');

        $this->postJson("/api/v1/coordinator/requisitions/{$id}", ['request_reason' => 'others', 'request_reason_details' => 'Client audit'])
            ->assertOk()->assertJsonPath('data.request_reason_details', 'Client audit');

        // Ticking another box clears it.
        $this->postJson("/api/v1/coordinator/requisitions/{$id}", ['request_reason' => 'new_position'])
            ->assertOk()->assertJsonPath('data.request_reason_details', null);

        // Details alone cannot be attached to a box that takes none.
        $this->postJson("/api/v1/coordinator/requisitions/{$id}", ['request_reason_details' => 'Sneaked in'])
            ->assertOk()->assertJsonPath('data.request_reason_details', null);
    }

    public function test_a_saved_requisition_reopens_with_what_was_entered_and_can_be_corrected(): void
    {
        $coordinator = $this->coordinatorFor($this->clientA);
        $this->actAs($coordinator);

        $id = $this->postJson('/api/v1/coordinator/requisitions', $this->requisition(['other_remarks' => 'Heavy lifting.']))
            ->assertCreated()->json('data.id');

        $this->getJson("/api/v1/coordinator/requisitions/{$id}")->assertOk()
            ->assertJsonPath('data.date_requested', now()->toDateString())
            ->assertJsonPath('data.required_by', now()->addDay()->toDateString())
            ->assertJsonPath('data.workers_needed', 1)
            ->assertJsonPath('data.male_count', 1)
            ->assertJsonPath('data.position_title', 'SC Sealer')
            ->assertJsonPath('data.qualifications', "At least 5'2\" in height. Can work for extended periods while standing.")
            ->assertJsonPath('data.other_remarks', 'Heavy lifting.')
            ->assertJsonPath('data.request_reason', 'replacement')
            ->assertJsonPath('data.request_reason_details', 'Marinduque D.')
            ->assertJsonPath('data.department_name', 'Loading Department')
            ->assertJsonPath('data.can_edit', true);

        // Clearing an optional field really clears it.
        $this->postJson("/api/v1/coordinator/requisitions/{$id}", ['other_remarks' => null, 'male_count' => null, 'workers_needed' => 2])
            ->assertOk()
            ->assertJsonPath('data.other_remarks', null)
            ->assertJsonPath('data.male_count', null)
            ->assertJsonPath('data.workers_needed', 2);
    }

    public function test_submitting_notifies_hr_and_approves_nothing(): void
    {
        Notification::fake();
        $coordinator = $this->coordinatorFor($this->clientA);
        $id = $this->submitted($coordinator);

        $requisition = ManpowerRequisition::find($id);
        $this->assertSame('submitted', $requisition->status);
        $this->assertNull($requisition->job_request_id);
        $this->assertSame(0, JobRequest::count());
        Notification::assertSentTo($this->hr, RequisitionSubmitted::class, function (RequisitionSubmitted $notification) {
            return str_contains($notification->toArray($this->hr)['body'], '1 × SC Sealer (Replacement)');
        });

        // Sent: no longer the coordinator's to change or discard.
        $this->postJson("/api/v1/coordinator/requisitions/{$id}", ['workers_needed' => 50])->assertStatus(409);
        $this->deleteJson("/api/v1/coordinator/requisitions/{$id}")->assertForbidden();

        // And a coordinator never reaches HR's decision.
        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'approve'])->assertForbidden();
        $this->getJson('/api/v1/requisitions')->assertForbidden();
        $this->assertSame('submitted', ManpowerRequisition::find($id)->status);
    }

    public function test_hr_reviews_returns_approves_and_fulfils_and_the_approver_is_acknowledged_by(): void
    {
        Notification::fake();
        $coordinator = $this->coordinatorFor($this->clientA);
        $id = $this->submitted($coordinator);

        $this->actAs($this->hr);
        $this->assertSame([$id], array_column($this->getJson('/api/v1/requisitions')->json('data'), 'id'));
        $this->getJson("/api/v1/requisitions/{$id}")->assertOk()
            ->assertJsonPath('data.request_reason_label', 'Replacement')
            ->assertJsonPath('data.qualifications', "At least 5'2\" in height. Can work for extended periods while standing.");

        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'start_review'])->assertOk()
            ->assertJsonPath('data.status', 'under_review');

        // Returning needs a reason.
        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'return'])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'return', 'note' => 'Which shift?'])->assertOk()
            ->assertJsonPath('data.acknowledged_by', null);
        Notification::assertSentTo($coordinator, RequisitionReviewed::class);

        // Fulfilling something not approved is refused.
        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'fulfil'])->assertStatus(409);

        $this->actAs($coordinator);
        $this->postJson("/api/v1/coordinator/requisitions/{$id}", ['other_remarks' => 'Day shift only.', 'submit' => true])->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.other_remarks', 'Day shift only.');

        $this->actAs($this->hr);
        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'fulfil'])->assertStatus(409);
        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'approve', 'note' => 'OK for 1.'])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.acknowledged_by.name', $this->hr->full_name);
        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'reject', 'note' => 'Too late'])->assertStatus(409);
        // Still the approver after a later step moves reviewed_by on.
        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'fulfil'])->assertOk()
            ->assertJsonPath('data.status', 'fulfilled')
            ->assertJsonPath('data.acknowledged_by.name', $this->hr->full_name);

        $history = ManpowerRequisition::find($id)->history()->pluck('to_status')->all();
        $this->assertSame(['draft', 'submitted', 'under_review', 'returned', 'submitted', 'approved', 'fulfilled'], $history);
        $this->assertSame('Which shift?', ManpowerRequisition::find($id)->history()->where('to_status', 'returned')->value('note'));
        $this->assertSame(4, \App\Models\AuditLog::where('module_key', 'requisitions')->where('action_type', 'review')->count());
    }

    public function test_rejecting_needs_a_reason_and_nobody_reviews_their_own_requisition(): void
    {
        $id = $this->submitted();

        $this->actAs($this->hr);
        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'reject'])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'reject', 'note' => 'Client cancelled by phone.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.acknowledged_by', null);

        // A requisition recorded under an HR officer's own name cannot be
        // approved by that officer.
        $own = ManpowerRequisition::create($this->requisition() + [
            'requisition_code' => 'MRF-TEST-1',
            'form_version' => 'cde-2026-10',
            'client_company_id' => $this->clientA->id,
            'requested_by' => $this->hr->id,
            'status' => 'submitted',
        ]);
        $this->postJson("/api/v1/requisitions/{$own->id}/review", ['action' => 'approve'])->assertForbidden();
        $this->assertSame('submitted', $own->fresh()->status);
        $this->assertSame([], $this->getJson("/api/v1/requisitions/{$own->id}")->json('data.review_actions'));
    }

    public function test_an_approved_requisition_is_recorded_as_a_manpower_request_once(): void
    {
        $id = $this->submitted();
        $this->actAs($this->hr);

        $request = fn (array $overrides = []) => array_merge([
            'client_company_id' => $this->clientA->id,
            'client_department_id' => $this->deptA->id,
            'position_title' => 'SC Sealer',
            'workers_needed' => 1,
            'date_requested' => now()->toDateString(),
            'remarks' => "Reason of Request: Replacement of Marinduque D.\nRequired Qualification(s): At least 5'2\"",
            'manpower_requisition_id' => $id,
        ], $overrides);

        // Not approved yet.
        $this->postJson('/api/v1/job-requests', $request())->assertStatus(409);
        $this->assertSame(0, JobRequest::count());

        $this->postJson("/api/v1/requisitions/{$id}/review", ['action' => 'approve'])->assertOk();
        $this->assertTrue($this->getJson("/api/v1/requisitions/{$id}")->json('data.can_record_request'));

        // Another client's request cannot claim it.
        $this->postJson('/api/v1/job-requests', $request([
            'client_company_id' => $this->clientB->id,
            'client_department_id' => $this->deptB->id,
        ]))->assertStatus(422);

        $jobRequestId = $this->postJson('/api/v1/job-requests', $request())->assertCreated()->json('data.id');
        $this->assertSame($jobRequestId, ManpowerRequisition::find($id)->job_request_id);
        $this->assertSame($jobRequestId, $this->getJson("/api/v1/requisitions/{$id}")->json('data.job_request.id'));
        $this->assertStringContainsString('Marinduque D.', JobRequest::find($jobRequestId)->remarks);

        // Only once.
        $this->postJson('/api/v1/job-requests', $request())->assertStatus(409);
        $this->assertSame(1, JobRequest::count());
    }

    public function test_requisitions_filed_on_the_temporary_form_keep_what_they_recorded(): void
    {
        $coordinator = $this->coordinatorFor($this->clientA);
        $old = $this->temporaryFormRequisition($coordinator);
        ManpowerRequisitionStatusHistory::create([
            'manpower_requisition_id' => $old->id, 'to_status' => 'approved',
            'changed_by' => $this->hr->id, 'changed_at' => now()->subDay(),
        ]);

        $this->actAs($this->hr);
        $this->getJson("/api/v1/requisitions/{$old->id}")->assertOk()
            ->assertJsonPath('data.provisional', true)
            ->assertJsonPath('data.form_version', 'provisional-2026-10')
            ->assertJsonPath('data.reason', 'Additional line for the holiday orders.')
            ->assertJsonPath('data.gender_requirement_label', 'Other / as specified by the client')
            ->assertJsonPath('data.employment_type_label', 'Contractual')
            ->assertJsonPath('data.work_schedule', 'Night shift')
            ->assertJsonPath('data.client_requester_name', 'Plant Manager')
            ->assertJsonPath('data.request_reason', null)
            ->assertJsonPath('data.can_record_request', true);
        $this->assertSame([$old->id], array_column($this->getJson('/api/v1/requisitions?status=all')->json('data'), 'id'));

        $this->actAs($coordinator);
        $this->getJson("/api/v1/coordinator/requisitions/{$old->id}")->assertOk()
            ->assertJsonPath('data.provisional', true)
            ->assertJsonPath('data.can_edit', false);
    }

    public function test_a_temporary_form_draft_is_completed_on_cdes_form_before_it_reaches_hr(): void
    {
        Notification::fake();
        $coordinator = $this->coordinatorFor($this->clientA);
        $draft = $this->temporaryFormRequisition($coordinator, ['status' => 'draft']);
        $this->actAs($coordinator);

        // Not on the temporary form any more.
        $this->postJson("/api/v1/coordinator/requisitions/{$draft->id}/submit")
            ->assertStatus(422)->assertJsonValidationErrors('request_reason');
        $this->postJson("/api/v1/coordinator/requisitions/{$draft->id}", ['other_remarks' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('request_reason');
        Notification::assertNothingSent();

        $this->postJson("/api/v1/coordinator/requisitions/{$draft->id}", [
            'request_reason' => 'additional_manpower',
            'qualifications' => 'Can stand for long periods.',
            'submit' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.form_version', 'cde-2026-10')
            ->assertJsonPath('data.provisional', false);

        // What the temporary form recorded is still there.
        $fresh = $draft->fresh();
        $this->assertSame('Additional line for the holiday orders.', $fresh->reason);
        $this->assertSame('Night shift', $fresh->work_schedule);
        $this->assertSame('Plant Manager', $fresh->client_requester_name);
        Notification::assertSentTo($this->hr, RequisitionSubmitted::class);
    }

    public function test_a_temporary_form_attachment_stays_private_and_cdes_form_takes_no_upload(): void
    {
        Storage::fake('documents');
        $coordinator = $this->coordinatorFor($this->clientA);
        Storage::disk('documents')->put('requisitions/1/0b8f6a6e-4d0c-4f39-9c55-3a8f5c1d2e7a.pdf', '%PDF-1.4');
        $old = $this->temporaryFormRequisition($coordinator, [
            'attachment_path' => 'requisitions/1/0b8f6a6e-4d0c-4f39-9c55-3a8f5c1d2e7a.pdf',
            'attachment_name' => 'client-letter.pdf',
        ]);

        $this->actAs($coordinator);
        $this->getJson("/api/v1/coordinator/requisitions/{$old->id}")->assertOk()
            ->assertJsonPath('data.has_attachment', true)
            ->assertJsonMissingPath('data.attachment_path');
        $this->getJson("/api/v1/coordinator/requisitions/{$old->id}/attachment")->assertOk()->assertJsonStructure(['data' => ['url']]);

        // CDE's form has no attachment: a file sent along is not stored.
        $id = $this->post('/api/v1/coordinator/requisitions', $this->requisition([
            'attachment' => $this->pdfUpload('client-letter.pdf'),
        ]), ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->assertNull(ManpowerRequisition::find($id)->attachment_path);
        $this->assertCount(1, Storage::disk('documents')->allFiles());

        $this->actAs($this->coordinatorFor($this->clientB));
        $this->getJson("/api/v1/coordinator/requisitions/{$old->id}/attachment")->assertForbidden();

        $this->actAs($this->hr);
        $this->getJson("/api/v1/requisitions/{$old->id}/attachment")->assertOk();
    }

    public function test_the_temporary_forms_field_switches_are_gone_from_settings(): void
    {
        $keys = array_keys(SettingsService::EDITABLE);

        $this->assertSame([], array_values(array_filter($keys, fn ($key) => str_starts_with($key, 'empower.requisition_form'))));
    }
}
