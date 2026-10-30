<?php

namespace Tests\Feature;

use App\Models\CompanyCriteria;
use App\Models\CriteriaCatalog;
use App\Models\JobRequest;
use App\Models\RequestCriteria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Creating, reading, editing, and removing matching requirements.
 *
 * Three places hold them: the catalogue an administrator maintains in Settings,
 * each client's standard requirements, and each request's own. Removing one
 * from the catalogue switches it off rather than deleting it, because existing
 * requests and their rankings still refer to it - so the other two places have
 * to keep working when a requirement they use has been switched off.
 */
class CriteriaCrudTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function newCriterion(array $overrides = []): array
    {
        return array_merge([
            'criteria_code' => 'typing_speed',
            'criteria_name' => 'Typing Speed',
            'criteria_type' => 'weighted_scale',
            'value_type' => 'number',
            'score_direction' => 'higher_better',
            'description' => 'Words per minute from the screening test.',
        ], $overrides);
    }

    private function coordinatorUser(): User
    {
        $user = User::factory()->create([
            'email' => 'coordinator.'.uniqid().'@cdemanpower.local',
            'user_type' => 'coordinator',
        ]);
        $user->assignRole('coordinator');

        return $user;
    }

    private function code(string $code): CriteriaCatalog
    {
        return CriteriaCatalog::where('criteria_code', $code)->firstOrFail();
    }

    private function requestFor(User $hr): JobRequest
    {
        $company = $this->clientCompany($hr);

        return $this->jobRequest($this->department($company, $hr), $hr);
    }

    // ------------------------------------------------------------- catalogue

    public function test_an_administrator_adds_a_requirement_and_it_persists(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->postJson('/api/v1/settings/criteria', $this->newCriterion())
            ->assertCreated()
            ->assertJsonPath('data.criteria_code', 'typing_speed')
            ->assertJsonPath('data.is_active', true);

        // Read back the way the screen does after a refresh.
        $listed = collect($this->getJson('/api/v1/settings')->assertOk()->json('data.criteria'))
            ->firstWhere('criteria_code', 'typing_speed');

        $this->assertSame('Typing Speed', $listed['criteria_name']);
        $this->assertSame(0, $listed['request_criteria_count']);
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'create', 'record_type' => 'CriteriaCatalog']);
    }

    public function test_invalid_or_duplicate_requirements_are_refused(): void
    {
        Sanctum::actingAs($this->adminUser());
        $before = CriteriaCatalog::count();

        $this->postJson('/api/v1/settings/criteria', $this->newCriterion(['criteria_code' => 'age']))
            ->assertUnprocessable()->assertJsonValidationErrors('criteria_code');
        $this->postJson('/api/v1/settings/criteria', $this->newCriterion(['criteria_name' => 'Age Range']))
            ->assertUnprocessable()->assertJsonValidationErrors('criteria_name');
        $this->postJson('/api/v1/settings/criteria', $this->newCriterion(['criteria_code' => 'Typing Speed 2']))
            ->assertUnprocessable()->assertJsonValidationErrors('criteria_code');
        $this->postJson('/api/v1/settings/criteria', $this->newCriterion(['description' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->postJson('/api/v1/settings/criteria', $this->newCriterion(['criteria_type' => 'bonus']))
            ->assertUnprocessable()->assertJsonValidationErrors('criteria_type');

        $this->assertSame($before, CriteriaCatalog::count());
    }

    public function test_an_administrator_edits_a_requirement_in_place(): void
    {
        Sanctum::actingAs($this->adminUser());
        $distance = $this->code('distance');
        $age = $this->code('age')->only(['criteria_name', 'criteria_type', 'score_direction', 'description']);
        $count = CriteriaCatalog::count();

        $this->patchJson("/api/v1/settings/criteria/{$distance->id}", [
            'criteria_name' => 'Distance to Worksite',
            'description' => 'Kilometres from home to the worksite.',
            'criteria_type' => 'weighted_binary',
            'score_direction' => 'lower_better',
            // Not editable: stored values and the applicant field depend on them.
            'criteria_code' => 'commute',
            'value_type' => 'text',
        ])->assertOk();

        $distance->refresh();
        $this->assertSame('Distance to Worksite', $distance->criteria_name);
        $this->assertSame('weighted_binary', $distance->criteria_type);
        $this->assertSame('distance', $distance->criteria_code);
        $this->assertSame('number', $distance->value_type);
        $this->assertSame($count, CriteriaCatalog::count());
        $this->assertSame($age, $this->code('age')->only(array_keys($age)));

        // Renaming onto another requirement's name is refused; keeping its own is not.
        $this->patchJson("/api/v1/settings/criteria/{$distance->id}", ['criteria_name' => 'Age Range'])
            ->assertUnprocessable()->assertJsonValidationErrors('criteria_name');
        $this->patchJson("/api/v1/settings/criteria/{$distance->id}", ['criteria_name' => 'Distance to Worksite'])
            ->assertOk();
        $this->patchJson("/api/v1/settings/criteria/{$distance->id}", ['criteria_name' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('criteria_name');
    }

    public function test_only_an_administrator_can_change_the_catalogue(): void
    {
        $distance = $this->code('distance');
        $count = CriteriaCatalog::count();

        foreach ([$this->hrUser(), $this->coordinatorUser()] as $user) {
            Sanctum::actingAs($user);

            $this->postJson('/api/v1/settings/criteria', $this->newCriterion())->assertForbidden();
            $this->patchJson("/api/v1/settings/criteria/{$distance->id}", ['criteria_name' => 'Changed'])->assertForbidden();
            $this->patchJson("/api/v1/settings/criteria/{$distance->id}", ['is_active' => false])->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/settings/criteria', $this->newCriterion())->assertUnauthorized();

        $this->assertSame($count, CriteriaCatalog::count());
        $this->assertSame('Distance from Worksite', $distance->fresh()->criteria_name);
        $this->assertTrue((bool) $distance->fresh()->is_active);
    }

    // --------------------------------------------------- removing (switch off)

    public function test_removing_a_requirement_switches_it_off_and_existing_requests_still_save(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestFor($hr);

        $criteria = [
            ['criteria_code' => 'distance', 'weight_score' => 20, 'min_value' => 5, 'max_value' => 30],
            ['criteria_code' => 'experience', 'weight_score' => 30, 'min_value' => 0, 'max_value' => 36],
        ];

        Sanctum::actingAs($hr);
        $this->postJson("/api/v1/job-requests/{$request->id}/criteria", ['criteria' => $criteria])->assertOk();

        Sanctum::actingAs($this->adminUser());
        $this->patchJson("/api/v1/settings/criteria/{$this->code('distance')->id}", ['is_active' => false])
            ->assertOk();
        $this->assertFalse((bool) $this->code('distance')->is_active);
        $this->assertSame(2, RequestCriteria::where('job_request_id', $request->id)->count());

        Sanctum::actingAs($hr);

        // The request that uses it still shows it, marked as no longer offered...
        $catalog = collect($this->getJson("/api/v1/job-requests/{$request->id}/criteria")->assertOk()->json('data.catalog'));
        $this->assertFalse((bool) $catalog->firstWhere('criteria_code', 'distance')['is_active']);

        // ...can be saved again unchanged...
        $this->postJson("/api/v1/job-requests/{$request->id}/criteria", ['criteria' => $criteria])->assertOk();
        $this->assertSame(2, RequestCriteria::where('job_request_id', $request->id)->count());

        // ...and can have it taken off.
        $this->postJson("/api/v1/job-requests/{$request->id}/criteria", ['criteria' => [$criteria[1]]])->assertOk();
        $this->assertSame(1, RequestCriteria::where('job_request_id', $request->id)->count());

        // A request that never used it is not offered it and cannot add it.
        $other = $this->requestFor($hr);
        $offered = collect($this->getJson("/api/v1/job-requests/{$other->id}/criteria")->json('data.catalog'));
        $this->assertNull($offered->firstWhere('criteria_code', 'distance'));
        $this->postJson("/api/v1/job-requests/{$other->id}/criteria", ['criteria' => $criteria])
            ->assertUnprocessable();
        $this->assertSame(0, RequestCriteria::where('job_request_id', $other->id)->count());

        // Switching it back on restores it everywhere.
        Sanctum::actingAs($this->adminUser());
        $this->patchJson("/api/v1/settings/criteria/{$this->code('distance')->id}", ['is_active' => true])->assertOk();
        Sanctum::actingAs($hr);
        $this->postJson("/api/v1/job-requests/{$other->id}/criteria", ['criteria' => $criteria])->assertOk();
    }

    public function test_a_client_keeps_seeing_a_switched_off_requirement_it_uses(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $distance = $this->code('distance');
        $height = $this->code('height');

        Sanctum::actingAs($hr);
        $this->putJson("/api/v1/clients/{$company->id}/criteria", ['criteria' => [
            ['criteria_id' => $distance->id, 'weight_score' => 20, 'min_value' => 5, 'max_value' => 30],
        ]])->assertOk();

        Sanctum::actingAs($this->adminUser());
        $this->patchJson("/api/v1/settings/criteria/{$distance->id}", ['is_active' => false])->assertOk();
        $this->patchJson("/api/v1/settings/criteria/{$height->id}", ['is_active' => false])->assertOk();

        Sanctum::actingAs($hr);
        $rows = collect($this->getJson("/api/v1/clients/{$company->id}/criteria")->assertOk()->json('data.criteria'));

        // Still listed, so it can be seen and taken off rather than dropped
        // silently on the next save; an unused switched-off one is not offered.
        $this->assertTrue($rows->firstWhere('code', 'distance')['in_use']);
        $this->assertFalse($rows->firstWhere('code', 'distance')['is_active']);
        $this->assertNull($rows->firstWhere('code', 'height'));

        $this->putJson("/api/v1/clients/{$company->id}/criteria", ['criteria' => [
            ['criteria_id' => $height->id, 'weight_score' => 0, 'min_value' => 150],
        ]])->assertUnprocessable();
        $this->assertSame(1, CompanyCriteria::where('client_company_id', $company->id)->count());

        // A new request for this client is not handed the switched-off one.
        $request = $this->jobRequest($this->department($company, $hr), $hr);
        $suggested = collect($this->getJson("/api/v1/job-requests/{$request->id}/criteria")->json('data.suggested'));
        $this->assertNull($suggested->firstWhere('criteria_code', 'distance'));
    }

    // -------------------------------------------------------------- duplicates

    public function test_a_requirement_listed_twice_is_refused_with_a_message(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestFor($hr);
        $company = $request->company;
        $experience = $this->code('experience');

        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/job-requests/{$request->id}/criteria", ['criteria' => [
            ['criteria_code' => 'experience', 'weight_score' => 30],
            ['criteria_code' => 'experience', 'weight_score' => 10],
        ]])->assertUnprocessable()->assertJsonValidationErrors('criteria.1.criteria_code');

        $this->putJson("/api/v1/clients/{$company->id}/criteria", ['criteria' => [
            ['criteria_id' => $experience->id, 'weight_score' => 30],
            ['criteria_id' => $experience->id, 'weight_score' => 10],
        ]])->assertUnprocessable()->assertJsonValidationErrors('criteria.1.criteria_id');

        $this->assertSame(0, RequestCriteria::where('job_request_id', $request->id)->count());
        $this->assertSame(0, CompanyCriteria::where('client_company_id', $company->id)->count());
    }

    // ------------------------------------------------------- per-request CRUD

    public function test_a_request_s_requirements_are_added_edited_and_removed_in_place(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestFor($hr);
        $untouched = $this->requestFor($hr);

        Sanctum::actingAs($hr);
        $this->postJson("/api/v1/job-requests/{$untouched->id}/criteria", ['criteria' => [
            ['criteria_code' => 'experience', 'weight_score' => 50, 'min_value' => 6],
        ]])->assertOk();

        $save = fn (array $criteria) => $this->postJson("/api/v1/job-requests/{$request->id}/criteria", ['criteria' => $criteria])->assertOk();
        $read = fn () => collect($this->getJson("/api/v1/job-requests/{$request->id}/criteria")->assertOk()->json('data.configured'))
            ->keyBy('criteria_code');

        $save([
            ['criteria_code' => 'experience', 'weight_score' => 30, 'min_value' => 0, 'max_value' => 36],
            ['criteria_code' => 'age', 'mandatory_flag' => true, 'min_value' => 18, 'max_value' => 35],
        ]);
        $this->assertEqualsCanonicalizing(['experience', 'age'], $read()->keys()->all());

        $save([
            ['criteria_code' => 'experience', 'weight_score' => 40, 'min_value' => 12, 'max_value' => 48],
            ['criteria_code' => 'age', 'mandatory_flag' => true, 'min_value' => 21, 'max_value' => 35],
        ]);
        $rows = $read();
        $this->assertEquals(40, $rows['experience']['weight_score']);
        $this->assertEquals(12, $rows['experience']['min_value']);
        $this->assertEquals(21, $rows['age']['min_value']);
        $this->assertSame(2, RequestCriteria::where('job_request_id', $request->id)->count());

        $save([['criteria_code' => 'experience', 'weight_score' => 40, 'min_value' => 12, 'max_value' => 48]]);
        $this->assertSame(['experience'], $read()->keys()->all());

        // The other request was never touched.
        $other = RequestCriteria::where('job_request_id', $untouched->id)->get();
        $this->assertCount(1, $other);
        $this->assertEquals(50, $other->first()->weight_score);
    }

    public function test_only_staff_with_the_permission_change_a_request_s_requirements(): void
    {
        $request = $this->requestFor($this->hrUser());

        Sanctum::actingAs($this->coordinatorUser());
        $this->postJson("/api/v1/job-requests/{$request->id}/criteria", ['criteria' => [
            ['criteria_code' => 'experience', 'weight_score' => 30],
        ]])->assertForbidden();

        $this->assertSame(0, RequestCriteria::where('job_request_id', $request->id)->count());
    }
}
