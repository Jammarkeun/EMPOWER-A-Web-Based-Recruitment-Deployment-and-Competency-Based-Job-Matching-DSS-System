<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicationStatusHistory;
use App\Models\User;
use App\Services\ApplicantLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Audit R-12: a status change lands whole or not at all.
 *
 * The disposition used to be saved before the transition map was checked, a
 * walk-in was created in one transaction and moved into screening in another,
 * and an identity check was marked before the move it was meant to release. A
 * failure part-way through left records that said one thing and were another.
 */
class ApplicantStatusAtomicityTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_a_refused_move_leaves_no_disposition_behind(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'applied']);
        Sanctum::actingAs($hr);

        // "applied" cannot jump to "ready for deployment".
        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", [
            'to_status' => 'ready_for_deployment',
            'disposition_reason' => 'not_suitable',
            'disposition_note' => 'Should never be saved',
        ])->assertStatus(409);

        $fresh = $applicant->fresh();
        $this->assertSame('applied', $fresh->current_status);
        $this->assertNull($fresh->disposition_reason);
        $this->assertNull($fresh->disposition_note);
        $this->assertNull($fresh->disposition_recorded_at);
    }

    public function test_an_allowed_move_saves_its_disposition_with_it(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);
        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", [
            'to_status' => 'archived',
            'reason' => 'Never completed requirements',
            'disposition_reason' => 'incomplete_requirements',
        ])->assertOk();

        $fresh = $applicant->fresh();
        $this->assertSame('archived', $fresh->current_status);
        $this->assertSame('incomplete_requirements', $fresh->disposition_reason);
        $this->assertNotNull($fresh->disposition_recorded_at);
        $this->assertDatabaseHas('application_status_history', [
            'applicant_id' => $applicant->id, 'from_status' => 'initial_screening', 'to_status' => 'archived',
        ]);
    }

    public function test_a_failure_while_writing_history_rolls_back_the_status_and_disposition(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);

        ApplicationStatusHistory::creating(function () {
            throw new RuntimeException('Simulated failure while writing the history row');
        });

        Sanctum::actingAs($hr);
        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", [
            'to_status' => 'archived',
            'disposition_reason' => 'not_suitable',
        ])->assertStatus(500);

        $fresh = $applicant->fresh();
        $this->assertSame('initial_screening', $fresh->current_status);
        $this->assertNull($fresh->disposition_reason);
        $this->assertDatabaseMissing('archives', ['entity_type' => 'applicant', 'entity_id' => $applicant->id]);
    }

    public function test_a_walk_in_is_not_left_half_registered_when_the_first_move_fails(): void
    {
        $hr = $this->hrUser();

        ApplicationStatusHistory::creating(function () {
            throw new RuntimeException('Simulated failure while writing the history row');
        });

        Sanctum::actingAs($hr);
        $this->postJson('/api/v1/applicants', [
            'source_channel' => 'walk_in',
            'first_name' => 'Half',
            'last_name' => 'Registered',
            'present_address' => 'Sta. Cruz, Laguna',
            'application_date' => now()->toDateString(),
        ])->assertStatus(500);

        $this->assertSame(0, Applicant::where('last_name', 'Registered')->count());
        $this->assertDatabaseMissing('applicant_requirements', []);
    }

    public function test_a_failed_identity_release_does_not_leave_the_applicant_marked_verified(): void
    {
        $hr = $this->hrUser();
        // Registered online and somehow already in screening: the move into
        // screening cannot happen, so the identity mark must not stick either.
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening', 'source_channel' => 'online']);
        $applicant->forceFill(['self_registered_at' => now()->subDay()])->save();

        Sanctum::actingAs($hr);
        $this->postJson("/api/v1/applicants/{$applicant->id}/verify-identity")->assertStatus(400);

        $this->assertNull($applicant->fresh()->identity_verified_at);
        $this->assertNull($applicant->fresh()->identity_verified_by);
    }

    public function test_a_move_based_on_a_stale_status_is_refused(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);

        // Another officer archives the record after this one loaded it.
        Applicant::whereKey($applicant->id)->update(['current_status' => 'archived']);

        try {
            app(ApplicantLifecycleService::class)->transition($applicant, 'incomplete_requirements', $hr, 'Stale screen');
            $this->fail('A move from a status the record no longer has should be refused.');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->assertSame('archived', $applicant->fresh()->current_status);
        $this->assertSame(0, ApplicationStatusHistory::where('applicant_id', $applicant->id)->count());
    }

    public function test_only_staff_with_the_permission_can_change_a_status(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);

        $portal = User::factory()->create(['email' => 'p'.uniqid().'@x.test', 'user_type' => 'applicant', 'applicant_id' => $applicant->id]);
        $portal->assignRole('portal');
        $coordinator = User::factory()->create(['email' => 'c'.uniqid().'@x.test', 'user_type' => 'coordinator']);
        $coordinator->assignRole('coordinator');

        foreach ([$portal, $coordinator] as $user) {
            Sanctum::actingAs($user);
            $this->patchJson("/api/v1/applicants/{$applicant->id}/status", ['to_status' => 'archived'])->assertForbidden();
        }

        // Protected fields cannot be set through the ordinary edit either.
        Sanctum::actingAs($hr);
        $this->patchJson("/api/v1/applicants/{$applicant->id}", [
            'current_status' => 'approved',
            'folder_category' => 'folder_1',
            'first_name' => 'Edited',
        ])->assertOk();

        $fresh = $applicant->fresh();
        $this->assertSame('initial_screening', $fresh->current_status);
        $this->assertSame('folder_3', $fresh->folder_category);
        $this->assertSame('Edited', $fresh->first_name);
    }
}
