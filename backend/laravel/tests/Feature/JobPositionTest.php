<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\JobPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The positions an applicant may apply for.
 *
 * Applicants used to type the job they wanted into a text box, which produced
 * applications for work the agency does not place and three spellings of the
 * same role, none of which could be matched against a client's request.
 *
 * The list now comes from the database, and the tests here are mostly about the
 * consequences of that: what is on the list, what is deliberately kept off it,
 * and the fact that a second client company's roles appear without anyone
 * touching the front end. That last point is the one that matters for the
 * agency's plans, since it intends to sign more clients and the design has to
 * survive the second one.
 */
class JobPositionTest extends TestCase
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
            'email' => 'ana.reyes@example.test',
            'password' => 'sekurangpassword',
            'password_confirmation' => 'sekurangpassword',
        ], $overrides);
    }

    // ------------------------------------------------------------ the list

    public function test_the_list_is_public_so_it_can_be_shown_before_signing_up(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $this->jobPosition($company, $hr, ['position_title' => 'Packaging Staff']);

        $this->getJson('/api/v1/register/positions')
            ->assertOk()
            ->assertJsonPath('data.positions.0.position_title', 'Packaging Staff')
            ->assertJsonPath('data.positions.0.company', $company->company_name);
    }

    /**
     * A role the client has stopped hiring for is switched off rather than
     * deleted, because the requests and deployments referencing it are history.
     */
    public function test_a_withdrawn_position_is_not_offered(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $this->jobPosition($company, $hr, ['position_title' => 'Welder', 'status' => 'inactive']);
        $this->jobPosition($company, $hr, ['position_title' => 'Machine Operator']);

        $titles = collect($this->getJson('/api/v1/register/positions')->json('data.positions'))
            ->pluck('position_title')->all();

        $this->assertSame(['Machine Operator'], $titles);
    }

    /**
     * The condition that is easy to forget: a client the agency no longer
     * supplies would otherwise keep offering its jobs to new applicants.
     */
    public function test_an_inactive_companys_positions_are_not_offered(): void
    {
        $hr = $this->hrUser();
        $former = $this->clientCompany($hr, [
            'company_code' => 'CLI-OLD-'.uniqid(),
            'company_name' => 'Former Client Inc.',
            'status' => 'inactive',
        ]);
        $this->jobPosition($former, $hr, ['position_title' => 'Caregiver']);

        $this->assertSame([], $this->getJson('/api/v1/register/positions')->json('data.positions'));
    }

    public function test_an_agency_wide_position_needs_no_company(): void
    {
        $hr = $this->hrUser();
        $this->jobPosition(null, $hr, ['position_title' => 'General Worker']);

        $this->getJson('/api/v1/register/positions')
            ->assertOk()
            ->assertJsonPath('data.positions.0.position_title', 'General Worker')
            ->assertJsonPath('data.positions.0.company', null);
    }

    /**
     * The whole reason the design is company-scoped rather than a fixed list.
     */
    public function test_a_new_client_companys_positions_appear_without_a_code_change(): void
    {
        $hr = $this->hrUser();
        $tiwi = $this->clientCompany($hr, ['company_code' => 'CLI-A-'.uniqid()]);
        $this->jobPosition($tiwi, $hr, ['position_title' => 'Production Helper']);

        $this->assertCount(1, $this->getJson('/api/v1/register/positions')->json('data.positions'));

        $hospital = $this->clientCompany($hr, [
            'company_code' => 'CLI-B-'.uniqid(),
            'company_name' => 'Laguna Medical Center',
        ]);
        $this->jobPosition($hospital, $hr, ['position_title' => 'Housekeeping Staff']);

        $titles = collect($this->getJson('/api/v1/register/positions')->json('data.positions'))
            ->pluck('position_title')->all();

        $this->assertSame(['Housekeeping Staff', 'Production Helper'], $titles);
    }

    // ------------------------------------------------------- choosing one

    public function test_registration_stores_the_reference_and_not_only_the_name(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $position = $this->jobPosition($company, $hr, ['position_title' => 'Warehouse Staff']);

        $this->postJson('/api/v1/register', $this->payload([
            'preferred_position_id' => $position->id,
        ]))->assertCreated();

        $applicant = Applicant::where('email', 'ana.reyes@example.test')->firstOrFail();

        $this->assertSame($position->id, $applicant->preferred_position_id);
        // The wording is kept too, as a record of what they were shown.
        $this->assertSame('Warehouse Staff', $applicant->preferred_position);
    }

    /**
     * The point of storing a reference rather than a string: the agency can
     * correct a job title without every applicant who chose it being left
     * pointing at a name that no longer exists.
     */
    public function test_renaming_a_position_does_not_orphan_the_applicant(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $position = $this->jobPosition($company, $hr, ['position_title' => 'Prod. Helper']);

        $this->postJson('/api/v1/register', $this->payload([
            'preferred_position_id' => $position->id,
        ]))->assertCreated();

        $position->update(['position_title' => 'Production Helper']);

        $applicant = Applicant::where('email', 'ana.reyes@example.test')
            ->with('preferredPosition')
            ->firstOrFail();

        $this->assertSame('Production Helper', $applicant->preferredPosition->position_title);
    }

    public function test_a_position_must_be_chosen_when_any_are_on_offer(): void
    {
        $hr = $this->hrUser();
        $this->jobPosition($this->clientCompany($hr), $hr);

        $this->postJson('/api/v1/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_position_id');
    }

    /**
     * An agency between contracts still takes applicants. A form that could not
     * be submitted because no client is currently hiring would turn away the
     * very people the pool exists to hold.
     */
    public function test_registration_still_works_when_nothing_is_on_offer(): void
    {
        $this->assertSame([], $this->getJson('/api/v1/register/positions')->json('data.positions'));

        $this->postJson('/api/v1/register', $this->payload())->assertCreated();

        $applicant = Applicant::where('email', 'ana.reyes@example.test')->firstOrFail();
        $this->assertNull($applicant->preferred_position_id);
    }

    /**
     * Vacancies close. Somebody who loaded the form an hour ago and submits it
     * now deserves an explanation rather than a validation error implying they
     * did something wrong.
     */
    public function test_a_position_withdrawn_while_the_form_was_open_is_refused_kindly(): void
    {
        $hr = $this->hrUser();
        $position = $this->jobPosition($this->clientCompany($hr), $hr);

        $position->update(['status' => 'inactive']);

        $response = $this->postJson('/api/v1/register', $this->payload([
            'preferred_position_id' => $position->id,
        ]))->assertStatus(422);

        $this->assertStringContainsString('no longer being offered', $response->json('message'));
        $this->assertNull(Applicant::where('email', 'ana.reyes@example.test')->first());
    }

    public function test_an_invented_position_id_is_rejected(): void
    {
        $this->postJson('/api/v1/register', $this->payload(['preferred_position_id' => 9999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_position_id');
    }

    // -------------------------------------------------------- maintaining

    public function test_hr_can_add_a_position_to_a_company(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);

        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/clients/{$company->id}/positions", [
            'position_title' => 'Quality Control Inspector',
            'description' => 'Checking output against product standards.',
        ])->assertCreated();

        $this->assertDatabaseHas('job_positions', [
            'client_company_id' => $company->id,
            'position_title' => 'Quality Control Inspector',
            'status' => 'active',
        ]);

        // And it is on the application form from that moment, with no deploy.
        $this->assertSame(
            'Quality Control Inspector',
            $this->getJson('/api/v1/register/positions')->json('data.positions.0.position_title')
        );
    }

    /**
     * Two clients may both hire welders, and refusing the second would be wrong.
     */
    public function test_the_same_title_is_allowed_at_two_companies_but_not_twice_at_one(): void
    {
        $hr = $this->hrUser();
        $first = $this->clientCompany($hr, ['company_code' => 'CLI-A-'.uniqid()]);
        $second = $this->clientCompany($hr, [
            'company_code' => 'CLI-B-'.uniqid(),
            'company_name' => 'ABC Manufacturing',
        ]);

        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/clients/{$first->id}/positions", ['position_title' => 'Welder'])
            ->assertCreated();
        $this->postJson("/api/v1/clients/{$second->id}/positions", ['position_title' => 'Welder'])
            ->assertCreated();
        $this->postJson("/api/v1/clients/{$first->id}/positions", ['position_title' => 'Welder'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('position_title');
    }

    public function test_withdrawing_a_position_takes_it_off_the_form(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $position = $this->jobPosition($company, $hr);

        Sanctum::actingAs($hr);

        $this->putJson("/api/v1/clients/{$company->id}/positions/{$position->id}", [
            'status' => 'inactive',
        ])->assertOk();

        $this->assertSame([], $this->getJson('/api/v1/register/positions')->json('data.positions'));
    }

    public function test_a_position_cannot_be_edited_through_a_different_company(): void
    {
        $hr = $this->hrUser();
        $owner = $this->clientCompany($hr, ['company_code' => 'CLI-A-'.uniqid()]);
        $other = $this->clientCompany($hr, [
            'company_code' => 'CLI-B-'.uniqid(),
            'company_name' => 'Someone Else Corp.',
        ]);
        $position = $this->jobPosition($owner, $hr);

        Sanctum::actingAs($hr);

        $this->putJson("/api/v1/clients/{$other->id}/positions/{$position->id}", [
            'position_title' => 'Hijacked',
        ])->assertNotFound();
    }

    public function test_an_applicant_cannot_create_positions(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $applicant = $this->applicant($hr);

        $portalUser = User::factory()->create([
            'email' => 'portal.'.uniqid().'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]);
        $portalUser->syncRoles(['portal']);

        Sanctum::actingAs($portalUser);

        $this->postJson("/api/v1/clients/{$company->id}/positions", [
            'position_title' => 'Chief Executive',
        ])->assertForbidden();
    }

    // ------------------------------------------- requests create positions

    /**
     * The mechanism that keeps the list honest without anyone maintaining it as
     * a separate chore: a client asks for a role, HR records the request in the
     * ordinary way, and the role is on the application form.
     */
    public function test_raising_a_request_for_a_new_role_creates_the_position(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);

        Sanctum::actingAs($hr);

        $this->postJson('/api/v1/job-requests', [
            'client_company_id' => $company->id,
            'client_department_id' => $department->id,
            'position_title' => 'Forklift Operator',
            'workers_needed' => 3,
            'date_requested' => now()->toDateString(),
        ])->assertCreated();

        $this->assertDatabaseHas('job_positions', [
            'client_company_id' => $company->id,
            'position_title' => 'Forklift Operator',
        ]);
    }

    public function test_the_same_role_asked_for_twice_stays_one_position(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);

        Sanctum::actingAs($hr);

        foreach (['Forklift Operator', 'forklift operator'] as $title) {
            $this->postJson('/api/v1/job-requests', [
                'client_company_id' => $company->id,
                'client_department_id' => $department->id,
                'position_title' => $title,
                'workers_needed' => 3,
                'date_requested' => now()->toDateString(),
            ])->assertCreated();
        }

        $this->assertSame(1, JobPosition::where('client_company_id', $company->id)->count());
    }

    public function test_a_request_cannot_borrow_another_companys_position(): void
    {
        $hr = $this->hrUser();
        $owner = $this->clientCompany($hr, ['company_code' => 'CLI-A-'.uniqid()]);
        $other = $this->clientCompany($hr, [
            'company_code' => 'CLI-B-'.uniqid(),
            'company_name' => 'Different Corp.',
        ]);
        $department = $this->department($other, $hr);
        $position = $this->jobPosition($owner, $hr);

        Sanctum::actingAs($hr);

        $this->postJson('/api/v1/job-requests', [
            'client_company_id' => $other->id,
            'client_department_id' => $department->id,
            'job_position_id' => $position->id,
            'workers_needed' => 1,
            'date_requested' => now()->toDateString(),
        ])->assertStatus(422);
    }
}
