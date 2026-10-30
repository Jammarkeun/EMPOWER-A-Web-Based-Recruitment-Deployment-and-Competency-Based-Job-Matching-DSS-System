<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Training;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Services\ApplicantLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Training as the applicant sees it: one notice per roster event naming the
 * session, a reminder and a Training step on the portal read from the roster,
 * and nothing that marks training done before an officer records it.
 */
class PortalTrainingTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function portalUserFor(Applicant $applicant): User
    {
        $user = User::factory()->create([
            'email' => 'portal'.$applicant->id.'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('portal');

        return $user;
    }

    private function trainingSession(User $hr, array $overrides = []): Training
    {
        return Training::create(array_merge([
            'training_code' => 'TRN-P-'.uniqid(),
            'training_title' => 'Food safety orientation',
            'training_date' => now()->addDays(5)->toDateString(),
            'location' => 'CDE office, Sta. Cruz, Laguna',
            'trainer_name' => 'Ms. Lopez',
            'status' => 'scheduled',
            'created_by' => $hr->id,
        ], $overrides));
    }

    private function readyApplicant(User $hr, array $overrides = []): Applicant
    {
        $applicant = $this->applicant($hr, array_merge(['current_status' => 'ready_for_deployment'], $overrides));
        $this->verifyRequirements($applicant, $hr, 'all');

        return $applicant;
    }

    private function enrol(User $hr, Training $training, Applicant $applicant): TrainingEnrollment
    {
        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/trainings/{$training->id}/enrollments", ['applicant_ids' => [$applicant->id]])
            ->assertOk()
            ->assertJsonPath('data.enrolled_count', 1);

        return TrainingEnrollment::where('training_id', $training->id)->where('applicant_id', $applicant->id)->sole();
    }

    private function record(User $hr, Training $training, TrainingEnrollment $enrollment, array $values): void
    {
        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/trainings/{$training->id}/enrollments/{$enrollment->id}", $values)->assertOk();
    }

    /** The applicant's notifications, newest first, as their portal reads them. */
    private function notices(User $portal): array
    {
        Sanctum::actingAs($portal);

        return $this->getJson('/api/v1/notifications')->assertOk()->json('data.notifications');
    }

    /**
     * Notices written in the same second come back in no fixed order (the
     * tiebreak is a random UUID), so the tests compare titles as a set.
     */
    private function assertNoticeTitles(array $expected, User $portal): void
    {
        $this->assertEqualsCanonicalizing($expected, array_column($this->notices($portal), 'title'));
    }

    private function notice(User $portal, string $title): array
    {
        return collect($this->notices($portal))->firstWhere('title', $title);
    }

    private function training(User $portal): array
    {
        Sanctum::actingAs($portal);

        return $this->getJson('/api/v1/portal')->assertOk()->json('data.training');
    }

    public function test_enrolling_sends_one_notice_naming_the_session(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr);
        $applicant = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($applicant);

        $this->enrol($hr, $training, $applicant);

        $notices = $this->notices($portal);

        // One alert for one event: the bare status notice is not sent beside it.
        $this->assertCount(1, $notices);
        $this->assertSame('training', $notices[0]['category']);
        $this->assertSame('You are scheduled for training', $notices[0]['title']);
        $this->assertSame('/portal/training', $notices[0]['link']);
        $this->assertNull($notices[0]['read_at']);

        $body = $notices[0]['body'];
        $this->assertStringContainsString('registered for a training session by CDE Manpower Services', $body);
        $this->assertStringContainsString('Food safety orientation', $body);
        $this->assertStringContainsString(now()->addDays(5)->format('j F Y'), $body);
        $this->assertStringContainsString('CDE office, Sta. Cruz, Laguna', $body);
        $this->assertStringContainsString('necessary before you can proceed to the next step', $body);
        // The session record has no start time, so none is mentioned.
        $this->assertDoesNotMatchRegularExpression('/\d{1,2}:\d{2}/', $body);
    }

    public function test_the_dashboard_shows_the_session_and_leaves_the_notice_unread(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr);
        $applicant = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($applicant);

        $this->enrol($hr, $training, $applicant);

        Sanctum::actingAs($portal);
        $response = $this->getJson('/api/v1/portal')->assertOk();

        // Training is the fourth step, between the documents and the client.
        $this->assertSame(
            ['application', 'screening', 'requirements', 'training', 'client', 'deployed'],
            array_column($response->json('data.application.stages'), 'key')
        );
        $this->assertSame('Training', $response->json('data.application.stages.3.label'));
        $response->assertJsonPath('data.application.stage_number', 4)
            ->assertJsonPath('data.training.status', 'scheduled')
            ->assertJsonPath('data.training.required', true)
            ->assertJsonPath('data.training.sessions.0.title', 'Food safety orientation')
            ->assertJsonPath('data.training.sessions.0.date', now()->addDays(5)->toDateString())
            ->assertJsonPath('data.training.sessions.0.location', 'CDE office, Sta. Cruz, Laguna')
            ->assertJsonPath('data.training.sessions.0.trainer_name', 'Ms. Lopez')
            ->assertJsonPath('data.training.sessions.0.state', 'scheduled');

        // Staff notes and the officers' names stay on the staff side.
        $this->assertArrayNotHasKey('remarks', $response->json('data.training.sessions.0'));

        // Showing the reminder is not reading the notice.
        $this->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.by_category.training', 1);
    }

    public function test_the_training_page_shows_only_the_signed_in_applicants_sessions(): void
    {
        $hr = $this->hrUser();
        $mine = $this->trainingSession($hr, ['training_title' => 'My orientation']);
        $theirs = $this->trainingSession($hr, ['training_title' => 'Somebody else\'s orientation']);
        $me = $this->readyApplicant($hr);
        $them = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($me);

        $this->enrol($hr, $mine, $me);
        $theirEnrolment = $this->enrol($hr, $theirs, $them);

        Sanctum::actingAs($portal);
        $response = $this->getJson('/api/v1/portal/training')->assertOk();

        $this->assertSame(['My orientation'], array_column($response->json('data.sessions'), 'title'));

        // The staff training routes are closed to a portal account outright.
        $this->getJson('/api/v1/trainings')->assertForbidden();
        $this->getJson("/api/v1/trainings/{$theirs->id}")->assertForbidden();
        $this->getJson('/api/v1/trainings/candidates')->assertForbidden();
        $this->patchJson("/api/v1/trainings/{$theirs->id}/enrollments/{$theirEnrolment->id}", ['attendance_status' => 'absent'])
            ->assertForbidden();
        $this->assertSame('pending', $theirEnrolment->fresh()->attendance_status);

        // Nor can an applicant record their own completion.
        $myEnrolment = TrainingEnrollment::where('applicant_id', $me->id)->sole();
        $this->patchJson("/api/v1/trainings/{$mine->id}/enrollments/{$myEnrolment->id}", ['completion_status' => 'completed'])
            ->assertForbidden();
    }

    public function test_the_step_is_completed_only_when_hr_records_completion(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr, ['training_date' => now()->toDateString()]);
        $applicant = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($applicant);

        $enrollment = $this->enrol($hr, $training, $applicant);
        $this->assertSame('scheduled', $this->training($portal)['status']);

        // Attending is not completing.
        $this->record($hr, $training, $enrollment, ['attendance_status' => 'present']);
        $progress = $this->training($portal);
        $this->assertSame('in_progress', $progress['status']);
        $this->assertSame('present', $progress['sessions'][0]['attendance_status']);
        $this->assertNull($progress['sessions'][0]['completed_on']);

        $this->record($hr, $training, $enrollment, ['completion_status' => 'completed']);
        $progress = $this->training($portal);
        $this->assertSame('completed', $progress['status']);
        $this->assertSame('completed', $progress['sessions'][0]['state']);
        $this->assertSame(today()->toDateString(), $progress['sessions'][0]['completed_on']);
        $this->assertSame('training_completed', $applicant->fresh()->current_status);

        // One notice for each event, none of them the bare status notice.
        $this->assertNoticeTitles(['Training completed', 'You are scheduled for training'], $portal);
        $this->assertSame(['training', 'training'], array_column($this->notices($portal), 'category'));
    }

    public function test_with_the_gate_off_training_is_not_required_and_nothing_waits_on_it(): void
    {
        config(['empower.require_training_before_deployment' => false]);

        $hr = $this->hrUser();
        $applicant = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($applicant);

        $training = $this->training($portal);
        $this->assertSame('not_required', $training['status']);
        $this->assertFalse($training['required']);

        // Endorsement goes ahead without a session on record.
        app(ApplicantLifecycleService::class)->transition($applicant, 'client_evaluation', $hr, 'Endorsed.');
        $this->assertSame('client_evaluation', $applicant->fresh()->current_status);

        // Somebody enrolled anyway is not told it stands in their way.
        $other = $this->readyApplicant($hr);
        $this->enrol($hr, $this->trainingSession($hr), $other);
        $notice = $this->notices($this->portalUserFor($other))[0];
        $this->assertStringNotContainsString('necessary', $notice['body']);
    }

    public function test_with_the_gate_on_an_untrained_applicant_waits_and_cannot_be_endorsed(): void
    {
        $hr = $this->hrUser();
        $ready = $this->readyApplicant($hr);
        $early = $this->applicant($hr, ['current_status' => 'initial_screening']);

        $this->assertSame('pending', $this->training($this->portalUserFor($ready))['status']);
        $this->assertSame('not_reached', $this->training($this->portalUserFor($early))['status']);

        $this->expectException(RuntimeException::class);
        app(ApplicantLifecycleService::class)->transition($ready, 'client_evaluation', $hr, 'Endorsed.');
    }

    public function test_a_moved_session_is_reflected_and_announced_once(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr);
        $applicant = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($applicant);

        $this->enrol($hr, $training, $applicant);

        Sanctum::actingAs($hr);
        $newDate = now()->addDays(12)->toDateString();
        $this->patchJson("/api/v1/trainings/{$training->id}", [
            'training_date' => $newDate,
            'location' => 'Best Tiwi plant, Calamba',
        ])->assertOk();

        // A change that moves nothing tells nobody anything.
        $this->patchJson("/api/v1/trainings/{$training->id}", ['remarks' => 'Bring a pen.'])->assertOk();
        $this->patchJson("/api/v1/trainings/{$training->id}", ['training_date' => $newDate])->assertOk();

        $session = $this->training($portal)['sessions'][0];
        $this->assertSame($newDate, $session['date']);
        $this->assertSame('Best Tiwi plant, Calamba', $session['location']);

        $this->assertNoticeTitles(['Your training schedule has changed', 'You are scheduled for training'], $portal);

        $moved = $this->notice($portal, 'Your training schedule has changed');
        $this->assertStringContainsString('on '.now()->addDays(12)->format('j F Y').' at Best Tiwi plant, Calamba', $moved['body']);
    }

    public function test_a_cancelled_session_is_announced_as_such(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr);
        $applicant = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($applicant);

        $this->enrol($hr, $training, $applicant);

        Sanctum::actingAs($hr);
        $this->patchJson("/api/v1/trainings/{$training->id}", ['status' => 'cancelled'])->assertOk();

        $this->assertSame('ready_for_deployment', $applicant->fresh()->current_status);

        // Not "You are ready for deployment": the news is the cancellation.
        $this->assertNoticeTitles(['Your training session was cancelled', 'You are scheduled for training'], $portal);

        $progress = $this->training($portal);
        $this->assertSame('pending', $progress['status']);
        $this->assertSame('cancelled', $progress['sessions'][0]['state']);
    }

    public function test_several_sessions_are_listed_and_kept_apart(): void
    {
        $hr = $this->hrUser();
        $today = $this->trainingSession($hr, ['training_title' => 'Orientation', 'training_date' => now()->toDateString()]);
        $later = $this->trainingSession($hr, ['training_title' => 'Line safety', 'training_date' => now()->addDays(7)->toDateString()]);
        $applicant = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($applicant);

        $first = $this->enrol($hr, $today, $applicant);
        $second = $this->enrol($hr, $later, $applicant);

        // Enrolling again in the same session is skipped, and says nothing.
        Sanctum::actingAs($hr);
        $this->postJson("/api/v1/trainings/{$today->id}/enrollments", ['applicant_ids' => [$applicant->id]])
            ->assertOk()
            ->assertJsonPath('data.enrolled_count', 0);

        $this->assertNoticeTitles(['You are scheduled for training', 'You are scheduled for training'], $portal);

        $this->record($hr, $today, $first, ['attendance_status' => 'present']);
        $this->record($hr, $today, $first, ['completion_status' => 'completed']);

        // The other enrolment is untouched by the first one's record.
        $this->assertSame('pending', $second->fresh()->attendance_status);
        $this->assertSame('not_completed', $second->fresh()->completion_status);

        $training = $this->training($portal);
        $this->assertSame('completed', $training['status']);
        $states = array_column($training['sessions'], 'state', 'title');
        $this->assertSame(['Line safety' => 'scheduled', 'Orientation' => 'completed'], $states);
    }

    public function test_an_absence_shows_as_missed(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr, ['training_date' => now()->toDateString()]);
        $applicant = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($applicant);

        $enrollment = $this->enrol($hr, $training, $applicant);
        $this->record($hr, $training, $enrollment, ['attendance_status' => 'absent']);

        $progress = $this->training($portal);
        $this->assertSame('missed', $progress['status']);
        $this->assertSame('missed', $progress['sessions'][0]['state']);
        $this->assertStringContainsString('contact the office', $progress['explanation']);
    }

    public function test_being_taken_off_a_roster_is_announced_and_the_step_waits_again(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr);
        $applicant = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($applicant);

        $enrollment = $this->enrol($hr, $training, $applicant);

        Sanctum::actingAs($hr);
        $this->deleteJson("/api/v1/trainings/{$training->id}/enrollments/{$enrollment->id}")->assertOk();

        $this->assertNoticeTitles(['You are no longer on a training session', 'You are scheduled for training'], $portal);

        $progress = $this->training($portal);
        $this->assertSame('pending', $progress['status']);
        $this->assertSame([], $progress['sessions']);
    }

    public function test_a_withdrawn_completion_takes_the_step_back_and_says_so(): void
    {
        $hr = $this->hrUser();
        $training = $this->trainingSession($hr, ['training_date' => now()->toDateString()]);
        $applicant = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($applicant);

        $enrollment = $this->enrol($hr, $training, $applicant);
        $this->record($hr, $training, $enrollment, ['attendance_status' => 'present']);
        $this->record($hr, $training, $enrollment, ['completion_status' => 'completed']);
        $this->record($hr, $training, $enrollment, ['completion_status' => 'not_completed']);

        $this->assertSame('training_scheduled', $applicant->fresh()->current_status);
        $this->assertSame('in_progress', $this->training($portal)['status']);
        $this->assertNoticeTitles([
            'Your training completion was withdrawn',
            'Training completed',
            'You are scheduled for training',
        ], $portal);
    }

    public function test_muting_training_notices_does_not_hide_the_reminder(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->readyApplicant($hr);
        $portal = $this->portalUserFor($applicant);
        $portal->forceFill(['notification_preferences' => ['training' => false]])->save();

        $this->enrol($hr, $this->trainingSession($hr), $applicant);

        $this->assertSame([], $this->notices($portal));
        $this->assertSame('scheduled', $this->training($portal)['status']);
    }
}
