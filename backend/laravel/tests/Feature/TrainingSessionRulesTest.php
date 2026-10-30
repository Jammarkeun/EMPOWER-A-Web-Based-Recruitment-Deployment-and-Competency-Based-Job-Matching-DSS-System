<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\AuditLog;
use App\Models\Training;
use App\Models\TrainingEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * A session's own record stays consistent with its roster (audit R-06, WF-08).
 *
 * The roster workflow is covered by TrainingWorkflowTest. These cover the
 * session itself: correcting its details, and the status and date changes that
 * would leave the roster describing training that never happened.
 */
class TrainingSessionRulesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->hr = $this->hrUser();
        Sanctum::actingAs($this->hr);
    }

    private function trainingSession(array $overrides = []): Training
    {
        return Training::create(array_merge([
            'training_code' => 'TRN-R-'.uniqid(),
            'training_title' => 'Food safety orientation',
            'training_date' => now()->toDateString(),
            'location' => 'CDE office, Sta. Cruz, Laguna',
            'trainer_name' => 'Training Officer',
            'status' => 'scheduled',
            'created_by' => $this->hr->id,
        ], $overrides));
    }

    private function readyApplicant(): Applicant
    {
        $applicant = $this->applicant($this->hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $this->hr, 'all');

        return $applicant;
    }

    public function test_session_details_can_be_corrected_and_the_correction_is_audited(): void
    {
        $session = $this->trainingSession();

        $this->patchJson("/api/v1/trainings/{$session->id}", [
            'location' => 'Best Tiwi plant, Calios',
            'remarks' => 'Hosted by the client coordinator.',
        ])
            ->assertOk()
            ->assertJsonPath('data.location', 'Best Tiwi plant, Calios')
            ->assertJsonPath('data.remarks', 'Hosted by the client coordinator.')
            ->assertJsonStructure(['data' => ['counts', 'enrollments', 'can_record_outcomes']]);

        $entry = AuditLog::where('record_type', 'Training')->where('record_id', $session->id)->where('action_type', 'update')->sole();
        $this->assertSame('CDE office, Sta. Cruz, Laguna', $entry->old_values_json['location']);
        $this->assertSame('Best Tiwi plant, Calios', $entry->new_values_json['location']);
    }

    public function test_an_unchanged_date_is_not_reported_as_a_change(): void
    {
        $session = $this->trainingSession(['training_date' => '2026-10-01']);

        $this->patchJson("/api/v1/trainings/{$session->id}", ['training_date' => '2026-10-01'])->assertOk();

        $this->assertFalse(
            AuditLog::where('record_type', 'Training')->where('record_id', $session->id)->exists(),
            'Saving the same date should not write an audit entry claiming it changed.'
        );
    }

    public function test_required_details_cannot_be_blanked(): void
    {
        $session = $this->trainingSession();

        $this->patchJson("/api/v1/trainings/{$session->id}", ['training_title' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('training_title');
    }

    public function test_a_cancelled_session_cannot_be_reopened(): void
    {
        $session = $this->trainingSession(['status' => 'cancelled']);

        $this->patchJson("/api/v1/trainings/{$session->id}", ['status' => 'scheduled'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This session was cancelled and cannot be reopened. Schedule a new session instead.');

        $this->assertSame('cancelled', $session->fresh()->status);
    }

    public function test_a_session_cannot_be_marked_completed_or_ongoing_before_its_date(): void
    {
        $session = $this->trainingSession(['training_date' => now()->addDays(3)->toDateString()]);

        $this->patchJson("/api/v1/trainings/{$session->id}", ['status' => 'completed'])->assertStatus(409);
        $this->patchJson("/api/v1/trainings/{$session->id}", ['status' => 'ongoing'])->assertStatus(409);

        $this->assertSame('scheduled', $session->fresh()->status);
    }

    public function test_once_attendance_is_recorded_the_date_cannot_move_into_the_future(): void
    {
        $session = $this->trainingSession();
        $applicant = $this->readyApplicant();

        $this->postJson("/api/v1/trainings/{$session->id}/enrollments", ['applicant_ids' => [$applicant->id]])->assertOk();
        $enrolment = TrainingEnrollment::where('training_id', $session->id)->sole();
        $this->patchJson("/api/v1/trainings/{$session->id}/enrollments/{$enrolment->id}", ['attendance_status' => 'present'])->assertOk();

        $this->patchJson("/api/v1/trainings/{$session->id}", ['training_date' => now()->addWeek()->toDateString()])
            ->assertStatus(409);

        // Correcting it to another day that has passed is still allowed.
        $this->patchJson("/api/v1/trainings/{$session->id}", ['training_date' => now()->subDay()->toDateString()])
            ->assertOk();
    }

    public function test_a_session_with_nothing_recorded_can_be_rescheduled(): void
    {
        $session = $this->trainingSession();

        $this->patchJson("/api/v1/trainings/{$session->id}", ['training_date' => now()->addWeek()->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.can_record_outcomes', false);
    }

    public function test_a_completed_session_can_be_reopened_to_add_a_late_attendee(): void
    {
        $session = $this->trainingSession(['status' => 'completed']);
        $applicant = $this->readyApplicant();

        $this->postJson("/api/v1/trainings/{$session->id}/enrollments", ['applicant_ids' => [$applicant->id]])
            ->assertStatus(409);

        $this->patchJson("/api/v1/trainings/{$session->id}", ['status' => 'scheduled'])
            ->assertOk()
            ->assertJsonPath('data.accepts_enrolment', true);

        $this->postJson("/api/v1/trainings/{$session->id}/enrollments", ['applicant_ids' => [$applicant->id]])
            ->assertOk()
            ->assertJsonPath('data.enrolled_count', 1);
    }

    public function test_the_applicant_record_shows_training_as_the_endorsement_gate_sees_it(): void
    {
        $applicant = $this->readyApplicant();

        $this->getJson("/api/v1/applicants/{$applicant->id}")
            ->assertOk()
            ->assertJsonPath('data.training.relevant', true)
            ->assertJsonPath('data.training.requirement_met', false)
            ->assertJsonPath('data.training.sessions', []);

        // A completion in a session that was later cancelled does not count.
        $cancelled = $this->trainingSession(['status' => 'cancelled', 'training_title' => 'Called-off session']);
        TrainingEnrollment::create([
            'training_id' => $cancelled->id,
            'applicant_id' => $applicant->id,
            'attendance_status' => 'present',
            'completion_status' => 'completed',
        ]);

        $this->getJson("/api/v1/applicants/{$applicant->id}")
            ->assertJsonPath('data.training.requirement_met', false)
            ->assertJsonPath('data.training.sessions.0.counts_towards_requirement', false);

        $this->completeTraining($applicant, $this->hr);

        $response = $this->getJson("/api/v1/applicants/{$applicant->id}")
            ->assertJsonPath('data.training.requirement_met', true)
            ->assertJsonCount(2, 'data.training.sessions');

        $this->assertContains(true, array_column($response->json('data.training.sessions'), 'counts_towards_requirement'));
    }

    public function test_the_timeline_describes_training_in_words(): void
    {
        $applicant = $this->readyApplicant();
        $this->completeTraining($applicant, $this->hr);

        $details = collect($this->getJson("/api/v1/applicants/{$applicant->id}/timeline")->json('data'))
            ->where('type', 'training')
            ->pluck('detail');

        $this->assertSame(['Attended; completion recorded.'], $details->values()->all());
    }
}
