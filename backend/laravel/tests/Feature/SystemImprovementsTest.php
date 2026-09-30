<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\CriteriaCatalog;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Report filtering, notification counts, preferences, and the deployment card.
 *
 * These four are grouped because they share one failure mode: each is a number
 * or a message shown to somebody who will act on it, and each was previously
 * capable of being confidently wrong. A report that ignores the second filter,
 * a badge that shows the global total beside every section, a preference switch
 * that changes nothing, a congratulation for a job the applicant has not been
 * given - none of them look broken on screen.
 */
class SystemImprovementsTest extends TestCase
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

    // =====================================================  reports

    /**
     * The headline case from the brief: pick a department, get that department.
     */
    public function test_a_department_filter_excludes_every_other_department(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $chocolate = $this->department($company, $hr, ['department_code' => 'CHOCO', 'department_name' => 'Chocolate Department']);
        $packaging = $this->department($company, $hr, ['department_code' => 'PACK', 'department_name' => 'Packaging']);

        $this->deployInto($chocolate, $hr, ['first_name' => 'Andrea', 'last_name' => 'Santos']);
        $this->deployInto($packaging, $hr, ['first_name' => 'John', 'last_name' => 'Cruz']);

        Sanctum::actingAs($hr);

        $response = $this->postJson('/api/v1/reports/preview', [
            'report_type' => 'employees',
            'filters' => ['client_department_id' => $chocolate->id],
        ])->assertOk();

        $this->assertSame(1, $response->json('data.total_rows'));
        $this->assertSame('Chocolate Department', $response->json('data.rows.0.3'));
    }

    /**
     * Several filters have to narrow together. Applying only the first is the
     * failure this test exists to catch, and it looks entirely plausible on
     * screen because the report still renders.
     */
    public function test_filters_combine_rather_than_replacing_one_another(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $production = $this->department($company, $hr, ['department_code' => 'PROD', 'department_name' => 'Production']);

        $wanted = $this->deployInto($production, $hr, [
            'first_name' => 'Wanted',
            'last_name' => 'Match',
        ], 'Production Staff');

        // Right department, wrong position.
        $this->deployInto($production, $hr, ['first_name' => 'Wrong', 'last_name' => 'Position'], 'Warehouse Staff');

        // Right position, wrong department.
        $other = $this->department($company, $hr, ['department_code' => 'QC', 'department_name' => 'Quality Control']);
        $this->deployInto($other, $hr, ['first_name' => 'Wrong', 'last_name' => 'Department'], 'Production Staff');

        Sanctum::actingAs($hr);

        $response = $this->postJson('/api/v1/reports/preview', [
            'report_type' => 'employees',
            'filters' => [
                'client_company_id' => $company->id,
                'client_department_id' => $production->id,
                'position_title' => 'Production Staff',
                'employment_status' => 'active',
            ],
        ])->assertOk();

        $this->assertSame(1, $response->json('data.total_rows'));
        $this->assertSame($wanted->employee_number, $response->json('data.rows.0.0'));
    }

    public function test_filters_that_match_nothing_return_an_empty_report_rather_than_everything(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $this->deployInto($this->department($company, $hr), $hr);

        Sanctum::actingAs($hr);

        $response = $this->postJson('/api/v1/reports/preview', [
            'report_type' => 'employees',
            'filters' => ['position_title' => 'Astronaut'],
        ])->assertOk();

        $this->assertSame(0, $response->json('data.total_rows'));
        $this->assertSame([], $response->json('data.rows'));
    }

    /**
     * The preview is what the user reads before exporting, so the two have to be
     * the same query. A screen showing 1 record and a download containing 150 is
     * the specific outcome this guards against.
     */
    public function test_the_export_carries_the_same_rows_as_the_preview(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $production = $this->department($company, $hr, ['department_code' => 'PROD', 'department_name' => 'Production']);
        $packaging = $this->department($company, $hr, ['department_code' => 'PACK', 'department_name' => 'Packaging']);

        $this->deployInto($production, $hr, ['first_name' => 'Only', 'last_name' => 'This']);
        $this->deployInto($packaging, $hr, ['first_name' => 'Not', 'last_name' => 'This']);

        Sanctum::actingAs($hr);

        $filters = ['client_department_id' => $production->id];

        $preview = $this->postJson('/api/v1/reports/preview', [
            'report_type' => 'employees',
            'filters' => $filters,
        ])->assertOk();

        $this->postJson('/api/v1/reports/export', [
            'report_type' => 'employees',
            'format' => 'csv',
            'filters' => $filters,
        ])->assertOk();

        // The export writes its row count to the audit trail, which is the one
        // place both figures can be compared after the fact.
        $this->assertDatabaseHas('audit_logs', [
            'action_type' => 'export',
            'module_key' => 'reports',
        ]);

        $logged = \App\Models\AuditLog::where('action_type', 'export')->latest('id')->first();

        $this->assertSame(
            $preview->json('data.total_rows'),
            $logged->new_values_json['row_count'] ?? null,
            'The export must contain exactly the rows the preview showed.'
        );
    }

    public function test_a_report_with_no_filters_still_returns_everything(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $this->deployInto($this->department($company, $hr), $hr);
        $this->deployInto($this->department($company, $hr, ['department_code' => 'B', 'department_name' => 'Second']), $hr);

        Sanctum::actingAs($hr);

        $this->postJson('/api/v1/reports/preview', ['report_type' => 'employees'])
            ->assertOk()
            ->assertJsonPath('data.total_rows', 2);
    }

    // ===============================================  notification counts

    /**
     * Each section's badge has to be its own count. Showing the global total
     * beside every one of them is the failure being ruled out.
     */
    public function test_unread_counts_are_broken_down_by_category(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);

        // Two application notifications and nothing else.
        $applicant->notify(new ApplicationStatusChanged($applicant, 'initial_screening'));
        $applicant->notify(new ApplicationStatusChanged($applicant, 'incomplete_requirements'));

        Sanctum::actingAs($this->portalUserFor($applicant));

        $response = $this->getJson('/api/v1/notifications/unread-count')->assertOk();

        $this->assertSame(2, $response->json('data.unread_count'));
        $this->assertSame(2, $response->json('data.by_category.application'));
        $this->assertNull(
            $response->json('data.by_category.requirements'),
            'A category with nothing unread must not report the global total.'
        );
    }

    /**
     * The categories are a partition of the same number, which is what stops
     * the bell and the sidebar disagreeing.
     */
    public function test_the_categories_add_up_to_the_total(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);

        $applicant->notify(new ApplicationStatusChanged($applicant, 'initial_screening'));

        Sanctum::actingAs($this->portalUserFor($applicant));

        $response = $this->getJson('/api/v1/notifications/unread-count')->assertOk();

        $this->assertSame(
            $response->json('data.unread_count'),
            array_sum($response->json('data.by_category'))
        );
    }

    public function test_reading_a_notification_reduces_its_own_category(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);
        $applicant->notify(new ApplicationStatusChanged($applicant, 'initial_screening'));

        Sanctum::actingAs($this->portalUserFor($applicant));

        $id = $this->getJson('/api/v1/notifications')->json('data.notifications.0.id');

        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk();

        $response = $this->getJson('/api/v1/notifications/unread-count')->assertOk();

        $this->assertSame(0, $response->json('data.unread_count'));
        $this->assertSame([], $response->json('data.by_category'));
    }

    // ================================================  notification settings

    public function test_a_muted_category_stops_arriving(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);
        $user = $this->portalUserFor($applicant);

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/notification-preferences', [
            'preferences' => ['application' => false, 'requirements' => true],
        ])->assertOk();

        $applicant->notify(new ApplicationStatusChanged($applicant, 'initial_screening'));

        $this->assertSame(0, $applicant->fresh()->unreadNotifications()->count());
    }

    public function test_an_account_that_has_never_set_preferences_receives_everything(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);
        $this->portalUserFor($applicant);

        $applicant->notify(new ApplicationStatusChanged($applicant, 'initial_screening'));

        $this->assertSame(1, $applicant->fresh()->unreadNotifications()->count());
    }

    public function test_preferences_survive_a_new_session(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $user = $this->portalUserFor($applicant);

        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/auth/notification-preferences', [
            'preferences' => ['requirements' => false],
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());

        $categories = collect($this->getJson('/api/v1/auth/notification-preferences')->json('data.categories'))
            ->keyBy('key');

        $this->assertFalse($categories['requirements']['enabled']);
        $this->assertTrue($categories['application']['enabled']);
    }

    public function test_unknown_preference_keys_are_discarded(): void
    {
        $hr = $this->hrUser();
        $user = $this->portalUserFor($this->applicant($hr));

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/notification-preferences', [
            'preferences' => ['application' => false, 'made_up_category' => false],
        ])->assertOk();

        $this->assertSame(
            ['application' => false],
            $user->fresh()->notification_preferences
        );
    }

    // ==================================================  the deployment card

    /**
     * The whole point: nothing before an actual placement counts.
     *
     * @dataProvider notYetDeployed
     */
    public function test_no_congratulations_before_the_placement_exists(string $status): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => $status]);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.deployment', null);
    }

    public static function notYetDeployed(): array
    {
        return [
            'applied' => ['applied'],
            'screening' => ['initial_screening'],
            'documents complete' => ['primary_requirements_complete'],
            'ready for deployment' => ['ready_for_deployment'],
            'with the client' => ['client_evaluation'],
            'approved by the client' => ['approved'],
        ];
    }

    public function test_the_placement_appears_once_it_is_recorded(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr, ['department_code' => 'CHOCO', 'department_name' => 'Chocolate Department']);

        $employee = $this->deployInto($department, $hr, ['first_name' => 'Andrea', 'last_name' => 'Santos'], 'Production Staff');
        $applicant = $employee->applicant;

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.deployment.company', $company->company_name)
            ->assertJsonPath('data.deployment.department', 'Chocolate Department')
            ->assertJsonPath('data.deployment.position', 'Production Staff')
            ->assertJsonPath('data.deployment.employee_number', $employee->employee_number);
    }

    /**
     * It is read from the record every time, so nothing about it depends on the
     * browser having been told once.
     */
    public function test_the_placement_is_still_there_on_a_later_request(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $employee = $this->deployInto($this->department($company, $hr), $hr);
        $user = $this->portalUserFor($employee->applicant);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/portal')->assertOk()->assertJsonPath('data.deployment.company', $company->company_name);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.deployment.company', $company->company_name);
    }

    // =====================================================  criteria values

    /**
     * The form has to know what a criterion will accept, because an
     * unrecognised value is not rejected - it simply never matches.
     */
    public function test_the_catalogue_says_what_each_criterion_accepts(): void
    {
        $hr = $this->hrUser();
        $department = $this->department($this->clientCompany($hr), $hr);
        $request = $this->jobRequest($department, $hr);

        Sanctum::actingAs($hr);

        $catalog = collect($this->getJson("/api/v1/job-requests/{$request->id}/criteria")->json('data.catalog'))
            ->keyBy('criteria_code');

        $this->assertSame('list', $catalog['skills']['accepts']);
        $this->assertSame('list', $catalog['certifications']['accepts']);

        $this->assertSame('choice', $catalog['education']['accepts']);
        $this->assertContains('high_school', $catalog['education']['options']);

        $this->assertSame('none', $catalog['experience']['accepts']);
        $this->assertNull($catalog['experience']['options']);
    }

    public function test_client_specific_competencies_are_saved_and_read_back(): void
    {
        $hr = $this->hrUser();
        $department = $this->department($this->clientCompany($hr), $hr);
        $request = $this->jobRequest($department, $hr);

        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/job-requests/{$request->id}/criteria", [
            'criteria' => [[
                'criteria_code' => 'skills',
                'weight_score' => 40,
                'expected_value' => 'Welding, Machine Operation, Safety Procedures',
            ]],
        ])->assertOk();

        $configured = collect($this->getJson("/api/v1/job-requests/{$request->id}/criteria")->json('data.configured'))
            ->firstWhere('criteria_code', 'skills');

        $this->assertSame('Welding, Machine Operation, Safety Procedures', $configured['expected_value']);
    }

    /**
     * Two clients, two different lists, no code change between them.
     */
    public function test_two_clients_keep_different_competencies(): void
    {
        $hr = $this->hrUser();

        $factory = $this->clientCompany($hr, ['company_code' => 'CLI-A-'.uniqid()]);
        $hospital = $this->clientCompany($hr, [
            'company_code' => 'CLI-B-'.uniqid(),
            'company_name' => 'Laguna Medical Center',
        ]);

        $education = CriteriaCatalog::where('criteria_code', 'skills')->firstOrFail();

        Sanctum::actingAs($hr);

        $this->putJson("/api/v1/clients/{$factory->id}/criteria", [
            'criteria' => [[
                'criteria_id' => $education->id,
                'weight_score' => 30,
                'expected_value' => 'Welding, Machine Operation',
            ]],
        ])->assertOk();

        $this->putJson("/api/v1/clients/{$hospital->id}/criteria", [
            'criteria' => [[
                'criteria_id' => $education->id,
                'weight_score' => 30,
                'expected_value' => 'Customer Service, Teamwork',
            ]],
        ])->assertOk();

        $read = fn ($company) => collect(
            $this->getJson("/api/v1/clients/{$company->id}/criteria")->json('data.criteria')
        )->firstWhere('code', 'skills')['expected_value'];

        $this->assertSame('Welding, Machine Operation', $read($factory));
        $this->assertSame('Customer Service, Teamwork', $read($hospital));
    }

    public function test_an_applicant_cannot_change_a_clients_competency_requirements(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);
        $request = $this->jobRequest($department, $hr);

        Sanctum::actingAs($this->portalUserFor($this->applicant($hr)));

        $this->postJson("/api/v1/job-requests/{$request->id}/criteria", [
            'criteria' => [['criteria_code' => 'skills', 'weight_score' => 10, 'expected_value' => 'Anything']],
        ])->assertForbidden();

        $this->putJson("/api/v1/clients/{$company->id}/criteria", ['criteria' => []])
            ->assertForbidden();
    }

    // ------------------------------------------------------------- helpers

    private function deployInto($department, User $hr, array $overrides = [], string $position = 'Production Staff')
    {
        $request = $this->jobRequest($department, $hr, ['workers_needed' => 20]);

        $applicant = $this->applicant($hr, array_merge([
            'current_status' => 'ready_for_deployment',
            'applicant_code' => 'APP-TEST-'.uniqid(),
        ], $overrides));

        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
            'position_title' => $position,
        ], $hr)->employee;
    }
}
