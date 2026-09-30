<?php

namespace Tests\Feature;

use App\Models\ClientDepartment;
use App\Models\Employee;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Seeing who is placed in a client's department.
 *
 * The client page listed departments as names and nothing else, so the question
 * a client actually asks first - "who have you given us in Packaging?" - could
 * only be answered by opening the employee list and filtering it by hand.
 *
 * The tests that matter most here are the ones about scope. A department is
 * addressed through its company, so one client's department cannot be reached
 * from another's URL, and reading the names and employment status of the people
 * placed somewhere needs its own permission rather than riding along with
 * whatever lets you see the company.
 */
class DepartmentEmployeesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    /**
     * Deploys somebody into a department, which is the only way an employee is
     * created - there is no back door that writes the row directly.
     */
    private function deployInto(ClientDepartment $department, User $hr, array $applicantOverrides = []): Employee
    {
        $request = $this->jobRequest($department, $hr, ['workers_needed' => 20]);

        $applicant = $this->applicant($hr, array_merge([
            'current_status' => 'ready_for_deployment',
            'applicant_code' => 'APP-TEST-'.uniqid(),
        ], $applicantOverrides));

        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
            'position_title' => 'Production Staff',
        ], $hr)->employee;
    }

    // ------------------------------------------------------------- the list

    public function test_a_department_lists_the_people_placed_in_it(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);

        $this->deployInto($department, $hr, ['first_name' => 'Andrea', 'last_name' => 'Santos']);
        $this->deployInto($department, $hr, ['first_name' => 'John', 'last_name' => 'Cruz']);

        Sanctum::actingAs($hr);

        $response = $this
            ->getJson("/api/v1/clients/{$company->id}/departments/{$department->id}/employees")
            ->assertOk()
            ->assertJsonPath('data.department.department_name', $department->department_name)
            ->assertJsonPath('data.department.employees_count', 2);

        $names = collect($response->json('data.employees'))->pluck('full_name')->all();

        // Ordered by surname, so a list somebody is scanning for a name behaves.
        $this->assertSame(['John Cruz', 'Andrea Santos'], $names);
    }

    /**
     * The whole point of reading the relationship rather than keeping a list per
     * department: moving somebody has to move them, with no second place to
     * update.
     */
    public function test_an_employee_moved_between_departments_follows_the_move(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $chocolate = $this->department($company, $hr, [
            'department_code' => 'CHOCO',
            'department_name' => 'Chocolate Department',
        ]);
        $packaging = $this->department($company, $hr, [
            'department_code' => 'PACK',
            'department_name' => 'Packaging',
        ]);

        $employee = $this->deployInto($chocolate, $hr, ['first_name' => 'Mark', 'last_name' => 'Dela Pena']);

        Sanctum::actingAs($hr);

        $url = fn (ClientDepartment $d) => "/api/v1/clients/{$company->id}/departments/{$d->id}/employees";

        $this->assertCount(1, $this->getJson($url($chocolate))->json('data.employees'));
        $this->assertCount(0, $this->getJson($url($packaging))->json('data.employees'));

        $deployment = $employee->activeDeployment();

        $this->postJson("/api/v1/deployments/{$deployment->id}/reassign", [
            'client_company_id' => $company->id,
            'client_department_id' => $packaging->id,
            'change_type' => 'transfer',
            'effective_date' => now()->toDateString(),
        ])->assertOk();

        $this->assertCount(0, $this->getJson($url($chocolate))->json('data.employees'));
        $this->assertCount(1, $this->getJson($url($packaging))->json('data.employees'));
    }

    public function test_an_empty_department_says_so_rather_than_failing(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);

        Sanctum::actingAs($hr);

        $this->getJson("/api/v1/clients/{$company->id}/departments/{$department->id}/employees")
            ->assertOk()
            ->assertJsonPath('data.department.employees_count', 0)
            ->assertJsonPath('data.employees', []);
    }

    /**
     * "12 employees" on a client's screen has to mean twelve people who turn up.
     */
    public function test_the_count_is_of_people_currently_working_there(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);

        $staying = $this->deployInto($department, $hr, ['first_name' => 'Sarah', 'last_name' => 'Reyes']);
        $leaving = $this->deployInto($department, $hr, ['first_name' => 'Paolo', 'last_name' => 'Lim']);

        $leaving->forceFill(['employment_status' => 'resigned'])->save();

        Sanctum::actingAs($hr);

        $response = $this
            ->getJson("/api/v1/clients/{$company->id}/departments/{$department->id}/employees")
            ->assertOk()
            ->assertJsonPath('data.department.employees_count', 1)
            ->assertJsonPath('data.department.former_employees_count', 1);

        // The former employee is still listed - their placement is a matter of
        // record - but sorted below the people who are actually there.
        $employees = $response->json('data.employees');
        $this->assertCount(2, $employees);
        $this->assertSame($staying->id, $employees[0]['id']);
        $this->assertSame('resigned', $employees[1]['employment_status']);
    }

    public function test_the_department_list_carries_its_own_counts(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $busy = $this->department($company, $hr, ['department_code' => 'PROD', 'department_name' => 'Production']);
        $this->department($company, $hr, ['department_code' => 'QC', 'department_name' => 'Quality Control']);

        $this->deployInto($busy, $hr);
        $this->deployInto($busy, $hr);

        Sanctum::actingAs($hr);

        $departments = collect($this->getJson("/api/v1/clients/{$company->id}/departments")->json('data'))
            ->keyBy('department_name');

        $this->assertSame(2, $departments['Production']['employees_count']);
        $this->assertSame(0, $departments['Quality Control']['employees_count']);
    }

    // ------------------------------------------------------------ the scope

    /**
     * The central guarantee. One client's department is not reachable through
     * another client's URL, and the answer is 404 rather than 403 - the
     * department does not exist at that address, and saying "forbidden" would
     * confirm that it exists somewhere.
     */
    public function test_a_department_cannot_be_read_through_another_company(): void
    {
        $hr = $this->hrUser();

        $tiwi = $this->clientCompany($hr, ['company_code' => 'CLI-A-'.uniqid()]);
        $rival = $this->clientCompany($hr, [
            'company_code' => 'CLI-B-'.uniqid(),
            'company_name' => 'Rival Manufacturing',
        ]);

        $tiwiDepartment = $this->department($tiwi, $hr);
        $this->deployInto($tiwiDepartment, $hr, ['first_name' => 'Private', 'last_name' => 'Person']);

        Sanctum::actingAs($hr);

        $this->getJson("/api/v1/clients/{$rival->id}/departments/{$tiwiDepartment->id}/employees")
            ->assertNotFound();
    }

    public function test_each_company_sees_only_its_own_people(): void
    {
        $hr = $this->hrUser();

        $first = $this->clientCompany($hr, ['company_code' => 'CLI-A-'.uniqid()]);
        $second = $this->clientCompany($hr, [
            'company_code' => 'CLI-B-'.uniqid(),
            'company_name' => 'Second Client Corp.',
        ]);

        // Same department name at both companies, which is legitimate and is
        // exactly the case a name-based lookup would get wrong.
        $firstPacking = $this->department($first, $hr, ['department_code' => 'PACK', 'department_name' => 'Packaging']);
        $secondPacking = $this->department($second, $hr, ['department_code' => 'PACK', 'department_name' => 'Packaging']);

        $this->deployInto($firstPacking, $hr, ['first_name' => 'Ours', 'last_name' => 'Worker']);
        $this->deployInto($secondPacking, $hr, ['first_name' => 'Theirs', 'last_name' => 'Worker']);

        Sanctum::actingAs($hr);

        $ours = $this->getJson("/api/v1/clients/{$first->id}/departments/{$firstPacking->id}/employees")
            ->assertOk()
            ->json('data.employees');

        $this->assertCount(1, $ours);
        $this->assertSame('Ours Worker', $ours[0]['full_name']);
    }

    // ----------------------------------------------------- the permissions

    /**
     * Seeing a company is not the same as seeing its payroll, so the two
     * permissions are required together rather than either alone.
     */
    public function test_reading_the_staff_list_needs_the_employee_permission(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);
        $this->deployInto($department, $hr);

        /*
         * A bespoke role rather than stripping a permission off the user.
         *
         * spatie/laravel-permission resolves a permission through the role as
         * well as directly, so revoking it from the user leaves the role still
         * granting it - the account would look restricted and behave exactly as
         * before, which is worse than not restricting it at all.
         */
        $role = Role::create(['name' => 'client-liaison-'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions(['clients.view']);

        $limited = User::factory()->create([
            'email' => 'limited.'.uniqid().'@cdemanpower.local',
            'user_type' => 'hr',
        ]);
        $limited->syncRoles([$role->name]);

        Sanctum::actingAs($limited);

        // The company page still opens.
        $this->getJson("/api/v1/clients/{$company->id}/departments")->assertOk();

        // Its staff do not.
        $this->getJson("/api/v1/clients/{$company->id}/departments/{$department->id}/employees")
            ->assertForbidden();
    }

    public function test_a_portal_user_cannot_read_a_departments_staff(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);

        $applicant = $this->applicant($hr);
        $portalUser = User::factory()->create([
            'email' => 'portal.'.uniqid().'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]);
        $portalUser->syncRoles(['portal']);

        Sanctum::actingAs($portalUser);

        $this->getJson("/api/v1/clients/{$company->id}/departments/{$department->id}/employees")
            ->assertForbidden();
    }

    public function test_an_unauthenticated_caller_gets_nothing(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);

        $this->getJson("/api/v1/clients/{$company->id}/departments/{$department->id}/employees")
            ->assertUnauthorized();
    }

    /**
     * The employee rows carry what the department page shows, so a change to the
     * resource that dropped one of them would be caught here rather than as an
     * empty column on screen.
     */
    public function test_the_rows_carry_the_fields_the_page_displays(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);
        $this->deployInto($department, $hr, ['first_name' => 'Andrea', 'last_name' => 'Santos']);

        Sanctum::actingAs($hr);

        $this->getJson("/api/v1/clients/{$company->id}/departments/{$department->id}/employees")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'department' => ['id', 'department_name', 'department_code', 'employees_count'],
                    'company' => ['id', 'company_name'],
                    'employees' => [
                        ['id', 'employee_number', 'full_name', 'current_position_title', 'employment_status', 'hire_date'],
                    ],
                ],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }
}
