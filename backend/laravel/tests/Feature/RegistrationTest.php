<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Applicant self-registration.
 *
 * This is the only endpoint in the system that writes to the database without an
 * authenticated caller, so the tests here are as much about what registration
 * *cannot* do as what it can. The central guarantee is that an open endpoint
 * cannot put anyone into the recruitment pipeline: a self-registered applicant
 * is held before screening until an HR officer confirms their identity in
 * person, which is what the agency's office-visit requirement actually means.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'sex' => 'female',
            'birth_date' => now()->subYears(24)->toDateString(),
            'contact_number' => '09171234567',
            'present_address' => 'Brgy. Pagsawitan, Sta. Cruz, Laguna',
            'preferred_position' => 'Production Helper',
            'email' => 'ana.reyes@example.test',
            'password' => 'sekurangpassword',
            'password_confirmation' => 'sekurangpassword',
        ], $overrides);
    }

    public function test_anyone_can_see_the_document_checklist_without_an_account(): void
    {
        $this->getJson('/api/v1/register/requirements')
            ->assertOk()
            ->assertJsonStructure(['data' => ['primary', 'final', 'office' => ['name', 'address']]])
            ->assertJsonPath('data.office.name', 'CDE Manpower Services');

        // The point of the page is knowing what to bring, so an empty list would
        // make it useless even though the request succeeded.
        $this->assertNotEmpty($this->getJson('/api/v1/register/requirements')->json('data.primary'));
    }

    public function test_registration_creates_a_portal_account_and_an_applicant_record(): void
    {
        $response = $this->postJson('/api/v1/register', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'applicant_code', 'user', 'next_step']]);

        $user = User::where('email', 'ana.reyes@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('applicant', $user->user_type);
        $this->assertTrue($user->hasRole('portal'));
        $this->assertNotNull($user->applicant_id);

        $applicant = Applicant::find($user->applicant_id);
        $this->assertSame('online', $applicant->source_channel);
        $this->assertSame('applied', $applicant->current_status);
        $this->assertNull($applicant->created_by, 'No staff member created this record.');

        // The reference number is what the applicant quotes at the counter, so
        // it has to come back in the response rather than only exist in the row.
        $this->assertSame($applicant->applicant_code, $response->json('data.applicant_code'));
    }

    /**
     * Registering online is meant to save a wasted trip, which only works if the
     * checklist exists from the moment the account does.
     */
    public function test_registration_creates_the_document_checklist(): void
    {
        $this->postJson('/api/v1/register', $this->payload())->assertCreated();

        $applicant = Applicant::firstWhere('source_channel', 'online');

        $this->assertGreaterThan(0, ApplicantRequirement::where('applicant_id', $applicant->id)->count());
        $this->assertSame(
            0,
            ApplicantRequirement::where('applicant_id', $applicant->id)->where('status', '!=', 'missing')->count(),
            'Nothing has been submitted yet, so every item starts missing.'
        );
    }

    /**
     * The security boundary. Without this, an open endpoint would be a way to
     * inject candidates into the shortlist for a real client's job request.
     */
    public function test_a_self_registered_applicant_cannot_be_screened_before_an_identity_check(): void
    {
        $this->postJson('/api/v1/register', $this->payload())->assertCreated();

        $applicant = Applicant::firstWhere('source_channel', 'online');
        $this->assertTrue($applicant->awaiting_identity_check);

        Sanctum::actingAs($this->hrUser());

        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", [
            'to_status' => 'initial_screening',
        ])
            ->assertStatus(400)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'registered online'));

        $this->assertSame('applied', $applicant->fresh()->current_status);
    }

    /**
     * Archiving stays available. Someone who registers and never appears should
     * not be stuck in the list permanently with no way to clear them out.
     */
    public function test_an_unverified_registrant_can_still_be_archived(): void
    {
        $this->postJson('/api/v1/register', $this->payload())->assertCreated();
        $applicant = Applicant::firstWhere('source_channel', 'online');

        Sanctum::actingAs($this->hrUser());

        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", [
            'to_status' => 'archived',
            'reason' => 'Did not visit the office.',
        ])->assertOk();

        $this->assertSame('archived', $applicant->fresh()->current_status);
    }

    public function test_confirming_identity_releases_the_applicant_into_screening(): void
    {
        $this->postJson('/api/v1/register', $this->payload())->assertCreated();
        $applicant = Applicant::firstWhere('source_channel', 'online');

        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/applicants/{$applicant->id}/verify-identity", [
            'remarks' => 'Presented PhilSys ID.',
        ])
            ->assertOk()
            ->assertJsonPath('data.current_status', 'initial_screening')
            ->assertJsonPath('data.awaiting_identity_check', false);

        $applicant->refresh();
        $this->assertNotNull($applicant->identity_verified_at);
        // Who checked matters as much as that it happened: the record is only
        // trustworthy because a named officer put their name against it.
        $this->assertSame($hr->id, $applicant->identity_verified_by);
    }

    public function test_identity_check_is_rejected_for_a_walk_in_record(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);

        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/applicants/{$applicant->id}/verify-identity")
            ->assertStatus(400)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'does not need an identity check'));
    }

    /**
     * People reapply. Without this the agency ends up holding the same person
     * twice under two histories, and neither record is complete.
     */
    public function test_duplicate_name_and_birth_date_is_refused(): void
    {
        $this->postJson('/api/v1/register', $this->payload())->assertCreated();

        $this->postJson('/api/v1/register', $this->payload([
            // Different email and different capitalisation: still the same person.
            'first_name' => 'ANA',
            'last_name' => 'reyes',
            'email' => 'ana.reyes.2@example.test',
        ]))
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already show an application'));

        $this->assertSame(1, Applicant::where('source_channel', 'online')->count());
        $this->assertNull(User::firstWhere('email', 'ana.reyes.2@example.test'));
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        $hr = $this->hrUser();

        $this->postJson('/api/v1/register', $this->payload(['email' => $hr->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_applicants_below_the_minimum_working_age_are_refused(): void
    {
        $this->postJson('/api/v1/register', $this->payload([
            'birth_date' => now()->subYears(13)->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('birth_date');

        $this->assertSame(0, Applicant::count());
    }

    public function test_password_must_be_confirmed(): void
    {
        $this->postJson('/api/v1/register', $this->payload([
            'password_confirmation' => 'something-else',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_email_availability_can_be_checked_before_submitting(): void
    {
        $hr = $this->hrUser();

        $this->postJson('/api/v1/register/check-email', ['email' => 'nobody@example.test'])
            ->assertOk()
            ->assertJsonPath('data.available', true);

        $this->postJson('/api/v1/register/check-email', ['email' => $hr->email])
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }

    /**
     * Registration is an unauthenticated write, so volume has to be capped
     * somewhere. Five an hour from one address is far more than a genuine
     * applicant needs and far less than is useful to anyone else.
     */
    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/register', $this->payload([
                'first_name' => 'Person'.$i,
                'email' => "person{$i}@example.test",
            ]))->assertCreated();
        }

        $this->postJson('/api/v1/register', $this->payload([
            'first_name' => 'Person6',
            'email' => 'person6@example.test',
        ]))->assertStatus(429);
    }

    /**
     * The account is usable immediately: the applicant signs in and sees the
     * checklist, which is the whole reason to register before visiting.
     */
    public function test_a_new_registrant_can_use_the_portal_straight_away(): void
    {
        $token = $this->postJson('/api/v1/register', $this->payload())
            ->assertCreated()
            ->json('data.token');

        $overview = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.application.status', 'applied')
            // The portal has to say plainly that the applicant is waiting on
            // their own office visit, not on the agency.
            ->assertJsonPath('data.application.awaiting_identity_check', true)
            ->assertJsonPath('data.application.office.name', 'CDE Manpower Services');

        // And it has to name what to bring, or the visit is wasted anyway.
        $this->assertNotEmpty($overview->json('data.documents.bring_now'));

        // The medical requirements are not part of a first visit. Listing them
        // alongside the rest makes the checklist look impossible and turns away
        // people who would have qualified.
        $this->assertEmpty(
            array_intersect(
                $overview->json('data.documents.bring_now'),
                $overview->json('data.documents.needed_later')
            ),
            'The first-visit list must not repeat the requirements needed later.'
        );

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/portal/documents')
            ->assertOk();
    }

    /**
     * The office prompt disappears once the check is done, so a verified
     * applicant is never told to come in a second time.
     */
    public function test_the_office_prompt_clears_after_the_identity_check(): void
    {
        $token = $this->postJson('/api/v1/register', $this->payload())
            ->assertCreated()
            ->json('data.token');

        $applicant = Applicant::firstWhere('source_channel', 'online');

        // Both callers act through real tokens here. Sanctum::actingAs() pins
        // the guard for the remainder of the test, so the portal request below
        // would otherwise still be made as the HR officer.
        $hrToken = $this->hrUser()->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$hrToken)
            ->postJson("/api/v1/applicants/{$applicant->id}/verify-identity")
            ->assertOk();

        // Guards cache the resolved user, and every request in a test shares one
        // container. A real second request would not, so this only reproduces
        // what production already does.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.application.awaiting_identity_check', false)
            ->assertJsonPath('data.application.office', null);
    }

    /**
     * A portal account created this way is still only a portal account.
     */
    public function test_a_new_registrant_holds_no_staff_permissions(): void
    {
        $token = $this->postJson('/api/v1/register', $this->payload())
            ->assertCreated()
            ->json('data.token');

        foreach (['/api/v1/applicants', '/api/v1/users', '/api/v1/dashboard/summary'] as $route) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson($route)
                ->assertForbidden();
        }
    }

    public function test_hr_can_list_registrants_awaiting_an_identity_check(): void
    {
        $this->postJson('/api/v1/register', $this->payload())->assertCreated();

        $hr = $this->hrUser();
        $this->applicant($hr, ['first_name' => 'Walked', 'last_name' => 'In']);

        Sanctum::actingAs($hr);

        $response = $this->getJson('/api/v1/applicants?awaiting_identity_check=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Ana', $response->json('data.0.first_name'));
    }
}
