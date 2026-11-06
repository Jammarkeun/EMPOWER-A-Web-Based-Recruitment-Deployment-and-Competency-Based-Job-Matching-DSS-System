<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The client company's pinned location: HR pins the establishment on a map,
 * and the latitude and longitude are saved with the company.
 *
 * A pin is optional, both coordinates or neither, never invented, and only
 * set by someone who may edit the company.
 */
class ClientCompanyLocationTest extends TestCase
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Best Tiwi Food Products Corporation',
            'business_type' => 'Food Manufacturing',
            'office_address' => 'Sta. Cruz, Laguna',
        ], $overrides);
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    public function test_a_company_is_created_with_the_pinned_location(): void
    {
        $id = $this->postJson('/api/v1/clients', $this->payload([
            'latitude' => 14.2814571,
            'longitude' => 121.4161847,
        ]))->assertCreated()
            ->assertJsonPath('data.latitude', 14.2814571)
            ->assertJsonPath('data.longitude', 121.4161847)
            ->json('data.id');

        $company = ClientCompany::find($id);
        $this->assertEqualsWithDelta(14.2814571, $company->latitude, 0.0000001);
        $this->assertEqualsWithDelta(121.4161847, $company->longitude, 0.0000001);
        $this->assertSame(1, ClientCompany::count());

        $this->getJson("/api/v1/clients/{$id}")->assertOk()
            ->assertJsonPath('data.latitude', 14.2814571)
            ->assertJsonPath('data.longitude', 121.4161847);
    }

    public function test_a_company_can_still_be_created_without_a_pin_and_nothing_is_guessed(): void
    {
        $id = $this->postJson('/api/v1/clients', $this->payload())->assertCreated()
            ->assertJsonPath('data.latitude', null)
            ->assertJsonPath('data.longitude', null)
            ->json('data.id');

        $this->assertFalse(ClientCompany::find($id)->hasPinnedLocation());
    }

    public function test_invalid_or_half_a_location_is_refused(): void
    {
        foreach ([
            ['latitude' => 91, 'longitude' => 121],
            ['latitude' => -90.5, 'longitude' => 121],
            ['latitude' => 14, 'longitude' => 180.01],
            ['latitude' => 14, 'longitude' => -181],
            ['latitude' => 'north', 'longitude' => 121],
            ['latitude' => 14.28],
            ['longitude' => 121.41],
        ] as $i => $location) {
            $this->postJson('/api/v1/clients', $this->payload($location + ['company_name' => "Company {$i}"]))
                ->assertUnprocessable();
        }

        $this->assertSame(0, ClientCompany::count());

        // The edges themselves are valid points.
        $this->postJson('/api/v1/clients', $this->payload(['latitude' => -90, 'longitude' => 180]))->assertCreated();
    }

    public function test_editing_moves_the_pin_on_the_same_record_and_is_audited(): void
    {
        $company = $this->clientCompany($this->hr, ['latitude' => 14.28, 'longitude' => 121.41]);

        $this->putJson("/api/v1/clients/{$company->id}", ['latitude' => 14.2799, 'longitude' => 121.4175])
            ->assertOk()
            ->assertJsonPath('data.id', $company->id)
            ->assertJsonPath('data.latitude', 14.2799);

        $this->assertSame(1, ClientCompany::count());
        $this->assertEqualsWithDelta(121.4175, $company->fresh()->longitude, 0.0000001);

        // Recorded like any other change to the company (types are stored by basename).
        $audit = AuditLog::where('record_type', 'ClientCompany')->where('record_id', $company->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('14.2799', json_encode($audit->new_values_json));
    }

    public function test_an_edit_that_does_not_send_the_pin_leaves_it_alone_and_two_nulls_remove_it(): void
    {
        $company = $this->clientCompany($this->hr, ['latitude' => 14.28, 'longitude' => 121.41]);

        $this->putJson("/api/v1/clients/{$company->id}", ['notes' => 'New gate on the east side'])->assertOk();
        $this->assertTrue($company->fresh()->hasPinnedLocation());

        // Half a removal is refused, so a pin is never left with one coordinate.
        $this->putJson("/api/v1/clients/{$company->id}", ['latitude' => null, 'longitude' => 121.41])->assertUnprocessable();
        $this->assertTrue($company->fresh()->hasPinnedLocation());

        $this->putJson("/api/v1/clients/{$company->id}", ['latitude' => null, 'longitude' => null])->assertOk();
        $this->assertFalse($company->fresh()->hasPinnedLocation());
    }

    public function test_a_company_without_a_pin_remains_readable_and_listed(): void
    {
        $company = $this->clientCompany($this->hr);

        $this->getJson("/api/v1/clients/{$company->id}")->assertOk()->assertJsonPath('data.latitude', null);
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonPath('data.0.id', $company->id);
    }

    public function test_only_staff_who_may_edit_companies_can_set_a_pin(): void
    {
        $company = $this->clientCompany($this->hr, ['latitude' => 14.28, 'longitude' => 121.41]);

        $coordinator = User::factory()->create(['email' => 'coord.'.uniqid().'@cdemanpower.local', 'user_type' => 'coordinator']);
        $coordinator->assignRole('coordinator');
        $portal = User::factory()->create([
            'email' => 'portal.'.uniqid().'@example.test', 'user_type' => 'applicant', 'password' => Hash::make('password'),
        ]);
        $portal->assignRole('portal');

        foreach ([$coordinator, $portal] as $user) {
            $this->actAs($user);
            $this->putJson("/api/v1/clients/{$company->id}", ['latitude' => 1, 'longitude' => 1])->assertForbidden();
            $this->postJson('/api/v1/clients', $this->payload(['company_name' => 'Sneaky', 'latitude' => 1, 'longitude' => 1]))
                ->assertForbidden();
        }

        $this->assertEqualsWithDelta(14.28, $company->fresh()->latitude, 0.0000001);
        $this->assertSame(1, ClientCompany::count());
    }

    public function test_an_unknown_company_id_changes_nothing(): void
    {
        $company = $this->clientCompany($this->hr);

        $this->putJson('/api/v1/clients/999999', ['latitude' => 14.28, 'longitude' => 121.41])->assertNotFound();
        $this->assertFalse($company->fresh()->hasPinnedLocation());
    }
}
