<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\AuditLog;
use App\Models\Training;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Services\ApplicantLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Training recorded as it happens (audit R-06): an applicant is enrolled in a
 * session, marked present or absent, and their completion recorded by a named
 * officer. The statuses follow the record, never the other way round, and
 * nothing counts as training unless a completion in a session that took place
 * says so.
 */
class TrainingWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function trainingSession(User $hr, array $overrides = []): Training
    {
        return Training::create(array_merge([
            'training_code' => 'TRN-T-'.uniqid(),
            'training_title' => 'Food safety orientation',
            'training_date' => now()->toDateString(),
            'location' => 'CDE office, Sta. Cruz, Laguna',
            'trainer_name' => 'Training Officer',
            'status' => 'scheduled',
            'created_by' => $hr->id,
        ], $overrides));
    }

    private function readyApplicant(User $hr): Applicant
    {
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');

        return $applicant;
    }

    private function enrol(Training $training, Applicant $applicant): TrainingEnrollment
    {
        $this->postJson("/api/v1/trainings/{$training->id}/enrollments", ['applicant_ids' => [$applicant->id]])
            ->assertOk()
            ->assertJsonPath('data.enrolled_count', 1);

        return TrainingEnrollment::where('training_id', $training->id)->where('applicant_id', $applicant->id)->sole();
    }

    private function record(Training $training, TrainingEnrollment $enrollment, array $values)
    {
        return $this->patchJson("/api/v1/trainings/{$training->id}/enrollments/{$enrollment->id}", $values);
    }

    public function test_enrolling_schedules_the_applicant_and_says_why_anyone_was_skipped(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr);
        $ready = $this->readyApplicant($hr);
        $tooEarly = $this->applicant($hr, ['current_status' => 'applied']);

        Sanctum::actingAs($hr);

        $response = $this->postJson("/api/v1/trainings/{$training->id}/enrollments", [
            'applicant_ids' => [$ready->id, $tooEarly->id],
        ])->assertOk();

        $response->assertJsonPath('data.enrolled_count', 1)
            ->assertJsonPath('data.skipped.0.applicant_id', $tooEarly->id);
        $this->assertStringContainsString('Not at a stage', $response->json('data.skipped.0.reason'));

        $this->assertSame('training_scheduled', $ready->fresh()->current_status);
        $this->assertSame('applied', $tooEarly->fresh()->current_status);
        $this->assertStringContainsString(
            $training->training_code,
            $ready->statusHistory()->where('to_status', 'training_scheduled')->sole()->reason
        );

        $enrollment = TrainingEnrollment::where('applicant_id', $ready->id)->sole();
        $this->assertSame('pending', $enrollment->attendance_status);
        $this->assertSame('not_completed', $enrollment->completion_status);

        // Enrolling the same person twice is reported, not duplicated.
        $this->postJson("/api/v1/trainings/{$training->id}/enrollments", ['applicant_ids' => [$ready->id]])
            ->assertOk()
            ->assertJsonPath('data.enrolled_count', 0);
        $this->assertSame(1, TrainingEnrollment::where('applicant_id', $ready->id)->count());
    }

    public function test_nobody_is_enrolled_in_a_cancelled_or_completed_session(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->readyApplicant($hr);

        Sanctum::actingAs($hr);

        foreach (['cancelled', 'completed'] as $status) {
            $training = $this->trainingSession($hr, ['status' => $status]);

            $this->postJson("/api/v1/trainings/{$training->id}/enrollments", ['applicant_ids' => [$applicant->id]])
                ->assertStatus(409);
        }

        $this->assertSame(0, TrainingEnrollment::count());
        $this->assertSame('ready_for_deployment', $applicant->fresh()->current_status);
    }

    public function test_attendance_is_not_recorded_for_a_session_that_has_not_happened(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr, ['training_date' => now()->addDays(3)->toDateString()]);
        $applicant = $this->readyApplicant($hr);

        Sanctum::actingAs($hr);
        $enrollment = $this->enrol($training, $applicant);

        $this->record($training, $enrollment, ['attendance_status' => 'present'])->assertStatus(409);

        $this->assertSame('pending', $enrollment->fresh()->attendance_status);
        $this->assertNull($enrollment->fresh()->attendance_recorded_by);
    }

    public function test_completion_needs_attendance_and_is_recorded_against_the_officer(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr);
        $applicant = $this->readyApplicant($hr);

        Sanctum::actingAs($hr);
        $enrollment = $this->enrol($training, $applicant);

        // Not inferred, and not possible without attendance.
        $this->record($training, $enrollment, ['completion_status' => 'completed'])->assertStatus(422);
        $this->assertSame('training_scheduled', $applicant->fresh()->current_status);

        $this->record($training, $enrollment, ['attendance_status' => 'present'])->assertOk();
        $enrollment->refresh();
        $this->assertSame('present', $enrollment->attendance_status);
        $this->assertSame($hr->id, $enrollment->attendance_recorded_by);
        $this->assertNotNull($enrollment->attendance_recorded_at);

        // Present is not completed.
        $this->assertFalse(app(ApplicantLifecycleService::class)->hasCompletedTraining($applicant));

        $this->record($training, $enrollment, ['completion_status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('message', 'Completion recorded.');

        $enrollment->refresh();
        $this->assertSame($hr->id, $enrollment->completion_recorded_by);
        $this->assertNotNull($enrollment->completion_recorded_at);
        $this->assertSame('training_completed', $applicant->fresh()->current_status);
        $this->assertTrue(app(ApplicantLifecycleService::class)->hasCompletedTraining($applicant));
    }

    public function test_a_completion_entered_in_error_can_be_withdrawn(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr);
        $applicant = $this->readyApplicant($hr);

        Sanctum::actingAs($hr);
        $enrollment = $this->enrol($training, $applicant);
        $this->record($training, $enrollment, ['attendance_status' => 'present'])->assertOk();
        $this->record($training, $enrollment, ['completion_status' => 'completed'])->assertOk();

        $this->record($training, $enrollment, ['completion_status' => 'not_completed'])
            ->assertOk()
            ->assertJsonPath('message', 'Completion withdrawn.');

        $this->assertSame('training_scheduled', $applicant->fresh()->current_status);
        $this->assertNull($enrollment->fresh()->completion_recorded_by);
        $this->assertFalse(app(ApplicantLifecycleService::class)->hasCompletedTraining($applicant));
    }

    public function test_a_completion_an_endorsement_rests_on_cannot_be_withdrawn(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr);
        $applicant = $this->readyApplicant($hr);

        Sanctum::actingAs($hr);
        $enrollment = $this->enrol($training, $applicant);
        $this->record($training, $enrollment, ['attendance_status' => 'present'])->assertOk();
        $this->record($training, $enrollment, ['completion_status' => 'completed'])->assertOk();

        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", ['to_status' => 'client_evaluation'])->assertOk();

        $this->record($training, $enrollment, ['completion_status' => 'not_completed'])->assertStatus(409);

        $this->assertSame('completed', $enrollment->fresh()->completion_status);
        $this->assertSame('client_evaluation', $applicant->fresh()->current_status);
    }

    public function test_only_an_untouched_enrolment_can_be_removed(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr);
        $applicant = $this->readyApplicant($hr);

        Sanctum::actingAs($hr);
        $enrollment = $this->enrol($training, $applicant);

        $this->deleteJson("/api/v1/trainings/{$training->id}/enrollments/{$enrollment->id}")->assertOk();

        $this->assertModelMissing($enrollment);
        $this->assertSame('ready_for_deployment', $applicant->fresh()->current_status);
        $this->assertTrue(AuditLog::where('module_key', 'training')->where('action_type', 'delete')
            ->where('record_id', $enrollment->id)->exists());

        // Once attendance is recorded the enrolment is part of the record.
        $again = $this->enrol($training, $applicant);
        $this->record($training, $again, ['attendance_status' => 'absent'])->assertOk();

        $this->deleteJson("/api/v1/trainings/{$training->id}/enrollments/{$again->id}")->assertStatus(409);
        $this->assertModelExists($again);
    }

    public function test_cancelling_a_session_releases_its_roster_but_not_a_session_with_completions(): void
    {
        $hr = $this->hrUser();
        $first = $this->trainingSession($hr);
        $scheduled = $this->readyApplicant($hr);

        Sanctum::actingAs($hr);
        $this->enrol($first, $scheduled);

        $this->patchJson("/api/v1/trainings/{$first->id}", ['status' => 'cancelled'])->assertOk();
        $this->assertSame('ready_for_deployment', $scheduled->fresh()->current_status);

        $second = $this->trainingSession($hr);
        $trained = $this->readyApplicant($hr);
        $enrollment = $this->enrol($second, $trained);
        $this->record($second, $enrollment, ['attendance_status' => 'present'])->assertOk();
        $this->record($second, $enrollment, ['completion_status' => 'completed'])->assertOk();

        $this->patchJson("/api/v1/trainings/{$second->id}", ['status' => 'cancelled'])->assertStatus(409);
        $this->assertSame('scheduled', $second->fresh()->status);
    }

    public function test_training_statuses_cannot_be_typed_on_the_status_endpoint(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->readyApplicant($hr);

        Sanctum::actingAs($hr);

        $response = $this->patchJson("/api/v1/applicants/{$applicant->id}/status", ['to_status' => 'training_scheduled'])
            ->assertStatus(409);

        $this->assertStringContainsString('Training screen', $response->json('message'));
        $this->assertSame('ready_for_deployment', $applicant->fresh()->current_status);
    }

    /**
     * Only a completion in a session that went ahead counts. A status word on
     * its own - from older data or a direct edit - and a completion in a
     * session later cancelled do not.
     */
    public function test_only_a_recorded_completion_in_a_session_that_took_place_counts(): void
    {
        $hr = $this->hrUser();
        $lifecycle = app(ApplicantLifecycleService::class);

        $statusOnly = $this->applicant($hr, ['current_status' => 'training_completed']);
        $this->verifyRequirements($statusOnly, $hr, 'all');
        $this->assertFalse($lifecycle->hasCompletedTraining($statusOnly));

        $cancelled = $this->readyApplicant($hr);
        TrainingEnrollment::create([
            'training_id' => $this->trainingSession($hr, ['status' => 'cancelled'])->id,
            'applicant_id' => $cancelled->id,
            'attendance_status' => 'present',
            'completion_status' => 'completed',
        ]);
        $this->assertFalse($lifecycle->hasCompletedTraining($cancelled));

        // And the endorsement gate holds on the strength of it.
        Sanctum::actingAs($hr);
        $this->patchJson("/api/v1/applicants/{$statusOnly->id}/status", ['to_status' => 'client_evaluation'])
            ->assertStatus(400);
        $this->assertSame('training_completed', $statusOnly->fresh()->current_status);
    }

    public function test_the_candidate_list_shows_who_is_still_waiting_for_training(): void
    {
        $hr = $this->hrUser();
        $waiting = $this->readyApplicant($hr);
        $trained = $this->readyApplicant($hr);
        $this->completeTraining($trained, $hr);

        Sanctum::actingAs($hr);

        $all = collect($this->getJson('/api/v1/trainings/candidates')->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($all[$waiting->id]['has_completed_training']);
        $this->assertTrue($all[$trained->id]['has_completed_training']);

        $awaiting = collect($this->getJson('/api/v1/trainings/candidates?awaiting_only=1')->assertOk()->json('data'));
        $this->assertTrue($awaiting->contains('id', $waiting->id));
        $this->assertFalse($awaiting->contains('id', $trained->id));
    }

    public function test_a_coordinator_cannot_change_training_records(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr);
        $applicant = $this->readyApplicant($hr);

        $coordinator = User::factory()->create(['email' => 'coord.'.uniqid().'@cde.test', 'user_type' => 'coordinator']);
        $coordinator->assignRole('coordinator');
        Sanctum::actingAs($coordinator);

        $this->postJson("/api/v1/trainings/{$training->id}/enrollments", ['applicant_ids' => [$applicant->id]])
            ->assertForbidden();
        $this->assertSame(0, TrainingEnrollment::count());
    }
}
