<?php

namespace Tests\Feature;

use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\CoordinatorAssignment;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\User;
use App\Models\ViolationReport;
use App\Notifications\CoordinatorEmployeeFlagged;
use App\Notifications\ViolationReportReviewed;
use App\Notifications\ViolationReportSubmitted;
use App\Services\CoordinatorAssignmentService;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The CDE coordinator: an agency employee assigned to one client company.
 *
 * Two clients, A and B, each with workers and a coordinator. What these tests
 * hold the system to is that a coordinator sees their own client and nothing
 * else - whatever they put in the URL or the query string - and that their
 * one real power, reporting an incident, ends with HR. A coordinator cannot
 * record, finalise, or terminate anything.
 */
class CoordinatorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private User $admin;
    private User $hr;
    private ClientCompany $clientA;
    private ClientCompany $clientB;
    private ClientDepartment $deptA;
    private ClientDepartment $deptB;
    private Employee $employeeA1;
    private Employee $employeeA2;
    private Employee $employeeB1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $this->admin = $this->adminUser();
        $this->hr = $this->hrUser();

        $this->clientA = $this->clientCompany($this->hr, ['company_name' => 'Best Tiwi Food Products Corporation']);
        $this->clientB = $this->clientCompany($this->hr, ['company_name' => 'Client B Corporation']);
        $this->deptA = $this->department($this->clientA, $this->hr, ['department_name' => 'Chocolate Department', 'department_code' => 'CHOCO']);
        $this->deptB = $this->department($this->clientB, $this->hr, ['department_name' => 'Assembly', 'department_code' => 'ASSY']);

        $this->employeeA1 = $this->place($this->deptA, 'Juan', 'Dela Cruz');
        $this->employeeA2 = $this->place($this->deptA, 'Maria', 'Santos');
        $this->employeeB1 = $this->place($this->deptB, 'Pedro', 'Penduko');
    }

    private function place(ClientDepartment $department, string $first, string $last): Employee
    {
        $request = $this->jobRequest($department, $this->hr, ['workers_needed' => 10]);
        $applicant = $this->applicant($this->hr, [
            'first_name' => $first,
            'last_name' => $last,
            'current_status' => 'ready_for_deployment',
        ]);
        $this->verifyRequirements($applicant, $this->hr, 'all');
        $this->completeTraining($applicant, $this->hr);

        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->subMonth()->toDateString(),
            'supervisor_name' => 'Line Supervisor',
        ], $this->hr)->employee;
    }

    private function coordinator(ClientCompany $company, string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? 'coordinator.'.uniqid().'@cdemanpower.local',
            'user_type' => 'coordinator',
            'password' => Hash::make('Coordinator-Pass-2026!'),
        ]);
        $user->assignRole('coordinator');
        app(CoordinatorAssignmentService::class)->assign($user, $company, $this->admin);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function report(User $coordinator, Employee $employee, array $overrides = []): int
    {
        $this->actAs($coordinator);

        return $this->postJson('/api/v1/coordinator/violation-reports', array_merge([
            'employee_id' => $employee->id,
            'violation_type' => 'awol',
            'incident_date' => now()->subDay()->toDateString(),
            'details' => 'Did not report for the morning shift.',
        ], $overrides))->assertCreated()->json('data.id');
    }

    // ============================================================ accounts

    /** 1. */
    public function test_an_administrator_creates_a_coordinator_assigned_to_a_client(): void
    {
        $this->actAs($this->admin);

        $response = $this->postJson('/api/v1/users', [
            'first_name' => 'Juan',
            'last_name' => 'Coordinator',
            'email' => 'juan.coordinator@cdemanpower.local',
            'user_type' => 'coordinator',
            'client_company_id' => $this->clientA->id,
            'password' => 'Coordinator-Pass-2026!',
            'password_confirmation' => 'Coordinator-Pass-2026!',
        ])->assertCreated();

        $this->assertSame(['coordinator'], $response->json('data.roles'));
        $this->assertSame($this->clientA->company_name, $response->json('data.coordinator_assignment.company_name'));

        $user = User::where('email', 'juan.coordinator@cdemanpower.local')->first();
        $this->assertDatabaseHas('coordinator_assignments', [
            'coordinator_user_id' => $user->id,
            'client_company_id' => $this->clientA->id,
            'status' => 'active',
            'assigned_by' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'assign', 'client_company_id' => $this->clientA->id]);

        // A coordinator cannot exist without a client.
        $this->postJson('/api/v1/users', [
            'first_name' => 'No', 'last_name' => 'Client', 'email' => 'no.client@cdemanpower.local',
            'user_type' => 'coordinator',
            'password' => 'Coordinator-Pass-2026!', 'password_confirmation' => 'Coordinator-Pass-2026!',
        ])->assertStatus(422)->assertJsonValidationErrors('client_company_id');
    }

    public function test_only_an_administrator_manages_coordinators_and_roles_cannot_be_mixed(): void
    {
        $this->actAs($this->hr);
        $this->postJson('/api/v1/users', [
            'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@cdemanpower.local', 'user_type' => 'coordinator',
            'client_company_id' => $this->clientA->id,
            'password' => 'Coordinator-Pass-2026!', 'password_confirmation' => 'Coordinator-Pass-2026!',
        ])->assertForbidden();

        $coordinator = $this->coordinator($this->clientA);
        $this->actAs($this->admin);

        $this->patchJson("/api/v1/users/{$coordinator->id}", ['role' => 'admin'])->assertStatus(422);
        $this->patchJson("/api/v1/users/{$this->hr->id}", ['role' => 'coordinator'])->assertStatus(422);
        $this->assertTrue($coordinator->fresh()->hasRole('coordinator'));
        $this->assertTrue($this->hr->fresh()->hasRole('hr'));
    }

    /** 2-4. */
    public function test_a_coordinator_logs_in_to_a_dashboard_for_their_client_only(): void
    {
        $coordinator = $this->coordinator($this->clientA, 'coordinator.a@cdemanpower.local');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'coordinator.a@cdemanpower.local',
            'password' => 'Coordinator-Pass-2026!',
        ])->assertOk();

        $this->assertSame('coordinator', $login->json('data.user.user_type'));
        $this->assertSame($this->clientA->company_name, $login->json('data.user.coordinator.company_name'));
        $this->assertSame(['coordination.access'], $login->json('data.user.permissions'));
        $this->assertDatabaseHas('audit_logs', [
            'action_type' => 'login', 'actor_role' => 'coordinator', 'client_company_id' => $this->clientA->id,
        ]);

        $this->actAs($coordinator);

        $dashboard = $this->getJson('/api/v1/coordinator/dashboard')->assertOk();
        $this->assertSame($this->clientA->id, $dashboard->json('data.company.id'));
        $this->assertSame(2, $dashboard->json('data.summary.active_employees'));
        $this->assertSame(2, $dashboard->json('data.summary.current_deployments'));

        $company = $this->getJson('/api/v1/coordinator/company')->assertOk();
        $this->assertSame($this->clientA->company_name, $company->json('data.company_name'));
        $this->assertSame(['Chocolate Department'], array_column($company->json('data.departments'), 'department_name'));
        $this->assertSame($coordinator->full_name, $company->json('data.coordinators.0.name'));

        // The HR dashboard is not theirs.
        $this->getJson('/api/v1/dashboard/summary')->assertForbidden();
    }

    // ============================================================ isolation

    /** 5, 6, 15, 16, 30. */
    public function test_a_coordinator_sees_only_their_clients_employees_whatever_they_send(): void
    {
        $this->actAs($this->coordinator($this->clientA));

        $ids = array_column($this->getJson('/api/v1/coordinator/employees')->assertOk()->json('data'), 'id');
        sort($ids);
        $this->assertSame([$this->employeeA1->id, $this->employeeA2->id], $ids);

        // Asking for client B in the query string changes nothing.
        $ids = array_column(
            $this->getJson("/api/v1/coordinator/employees?client_company_id={$this->clientB->id}")->json('data'),
            'id'
        );
        $this->assertNotContains($this->employeeB1->id, $ids);

        // Client B's department and employee are refused outright.
        $this->getJson("/api/v1/coordinator/employees?client_department_id={$this->deptB->id}")->assertForbidden();
        $this->getJson("/api/v1/coordinator/employees/{$this->employeeB1->id}")->assertForbidden();

        // The staff endpoints that would take a client id are closed to them.
        $this->getJson("/api/v1/employees?client_company_id={$this->clientB->id}")->assertForbidden();
        $this->getJson("/api/v1/clients/{$this->clientB->id}")->assertForbidden();
        $this->getJson("/api/v1/clients/{$this->clientB->id}/departments/{$this->deptB->id}/employees")->assertForbidden();
    }

    public function test_an_employee_record_is_scoped_and_carries_no_unnecessary_personal_data(): void
    {
        $this->actAs($this->coordinator($this->clientA));

        $data = $this->getJson("/api/v1/coordinator/employees/{$this->employeeA1->id}")->assertOk()->json('data');

        $this->assertSame('Chocolate Department', $data['employee']['department']);
        $this->assertSame('Line Supervisor', $data['employee']['supervisor']);
        $this->assertNotEmpty($data['deployments']);
        $this->assertArrayHasKey('summary', $data['attendance']);

        $flat = json_encode($data);
        foreach (['birth_date', 'present_address', 'contact_number', 'sss', 'salary', 'payroll'] as $field) {
            $this->assertStringNotContainsString($field, $flat);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action_type' => 'view', 'actor_role' => 'coordinator',
            'record_id' => $this->employeeA1->id, 'client_company_id' => $this->clientA->id,
        ]);
    }

    /** 6, 8, 30. */
    public function test_deployments_and_manpower_requests_are_scoped(): void
    {
        $this->actAs($this->coordinator($this->clientA));

        $deployments = $this->getJson("/api/v1/coordinator/deployments?client_company_id={$this->clientB->id}")->assertOk()->json('data');
        $this->assertCount(2, $deployments);
        $this->assertNotContains($this->employeeB1->id, array_column(array_column($deployments, 'employee'), 'id'));

        $requests = $this->getJson("/api/v1/coordinator/manpower-requests?client_company_id={$this->clientB->id}")->assertOk()->json('data');
        $this->assertCount(2, $requests);

        $this->getJson("/api/v1/job-requests?client_company_id={$this->clientB->id}")->assertForbidden();
        $this->getJson('/api/v1/deployments')->assertForbidden();
    }

    /** 13, 14, 15, 30: every staff screen is closed, not just hidden. */
    public function test_no_staff_endpoint_answers_a_coordinator(): void
    {
        $this->actAs($this->coordinator($this->clientA));

        // Reference data and the user's own account are the only open ones.
        $open = [
            'api/v1/auth/', 'api/v1/notifications', 'api/v1/register/', 'api/v1/requirement-types',
            'api/v1/document-scan/status', 'api/v1/coordinator/', 'api/v1/portal',
        ];

        $answered = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            if (collect($open)->contains(fn ($prefix) => str_starts_with($uri, $prefix))) {
                continue;
            }

            // Fill every parameter with a record that exists in client B.
            $path = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => match ($m[1]) {
                'client' => $this->clientB->id,
                'department' => $this->deptB->id,
                'employee' => $this->employeeB1->id,
                default => 1,
            }, $uri);

            $status = $this->getJson('/'.$path)->status();
            $checked++;

            if ($status === 200) {
                $answered[] = $uri;
            }
        }

        // A sweep that matched nothing would pass too, so it must have covered
        // the staff API, not a handful of routes.
        $this->assertGreaterThan(50, $checked);
        $this->assertSame([], $answered, 'Staff endpoints answered a coordinator: '.implode(', ', $answered));
    }

    public function test_staff_cannot_use_the_coordinator_workspace(): void
    {
        foreach ([$this->hr, $this->admin] as $staff) {
            $this->actAs($staff);
            $this->getJson('/api/v1/coordinator/dashboard')->assertForbidden();
        }
    }

    public function test_a_coordinator_without_an_assignment_reaches_nothing(): void
    {
        $user = User::factory()->create(['email' => 'unassigned@cdemanpower.local', 'user_type' => 'coordinator']);
        $user->assignRole('coordinator');
        $this->actAs($user);

        $this->getJson('/api/v1/coordinator/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is not assigned to a client company yet. Please contact the administrator.');
    }

    // ============================================================ reporting

    /** 7-10. */
    public function test_a_coordinator_drafts_then_submits_a_report_and_hr_is_notified(): void
    {
        $coordinator = $this->coordinator($this->clientA);

        // The client is stamped from the assignment; a client id in the body is ignored.
        $id = $this->report($coordinator, $this->employeeA1, ['client_company_id' => $this->clientB->id]);

        $report = ViolationReport::find($id);
        $this->assertSame('draft', $report->status);
        $this->assertSame($this->clientA->id, $report->client_company_id);
        $this->assertSame($this->deptA->id, $report->client_department_id);

        // HR does not see an unsent draft.
        $this->actAs($this->hr);
        $this->assertCount(0, $this->getJson('/api/v1/violation-reports?status=all')->json('data'));
        $this->getJson("/api/v1/violation-reports/{$id}")->assertForbidden();

        $this->actAs($coordinator);
        $this->postJson("/api/v1/coordinator/violation-reports/{$id}", ['details' => 'Absent without notice; confirmed by the supervisor.'])
            ->assertOk();
        $this->postJson("/api/v1/coordinator/violation-reports/{$id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertSame(1, $this->hr->notifications()->where('type', ViolationReportSubmitted::class)->count());
        $this->assertSame(1, $this->admin->notifications()->where('type', ViolationReportSubmitted::class)->count());
        $this->assertSame(
            '/violation-reports/'.$id,
            $this->hr->notifications()->first()->data['link']
        );

        // Once sent, it is HR's: the coordinator can no longer change it.
        $this->postJson("/api/v1/coordinator/violation-reports/{$id}", ['details' => 'Changed'])->assertStatus(409);

        $this->assertDatabaseHas('audit_logs', ['action_type' => 'submit', 'actor_role' => 'coordinator', 'client_company_id' => $this->clientA->id]);
    }

    public function test_a_coordinator_cannot_report_on_another_clients_employee(): void
    {
        $coordinator = $this->coordinator($this->clientA);
        $this->actAs($coordinator);

        $this->postJson('/api/v1/coordinator/violation-reports', [
            'employee_id' => $this->employeeB1->id,
            'violation_type' => 'awol',
            'incident_date' => now()->toDateString(),
            'details' => 'x',
        ])->assertForbidden();

        $this->assertDatabaseCount('violation_reports', 0);
    }

    /** 11, 16. */
    public function test_hr_returns_then_validates_a_report_into_an_official_linked_violation(): void
    {
        $coordinator = $this->coordinator($this->clientA);
        $id = $this->report($coordinator, $this->employeeA1, ['submit' => true]);

        $this->actAs($this->hr);
        $this->postJson("/api/v1/violation-reports/{$id}/review", ['action' => 'start_review'])->assertOk();
        $this->postJson("/api/v1/violation-reports/{$id}/review", ['action' => 'return'])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/violation-reports/{$id}/review", ['action' => 'return', 'note' => 'Add the time of the shift.'])
            ->assertOk()->assertJsonPath('data.status', 'returned');

        $this->assertSame(2, $coordinator->notifications()->where('type', ViolationReportReviewed::class)->count());

        // Corrected and sent back.
        $this->actAs($coordinator);
        $this->postJson("/api/v1/coordinator/violation-reports/{$id}", [
            'details' => 'Absent for the 6:00 AM shift without notice.',
            'submit' => true,
        ])->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->actAs($this->hr);
        $this->postJson("/api/v1/violation-reports/{$id}/review", ['action' => 'validate', 'penalty' => 'Written warning'])
            ->assertOk()->assertJsonPath('data.status', 'validated');

        $violation = EmployeeViolation::where('violation_report_id', $id)->first();
        $this->assertNotNull($violation);
        $this->assertSame($this->employeeA1->id, $violation->employee_id);
        $this->assertSame($this->clientA->id, $violation->client_company_id);
        $this->assertSame($this->deptA->id, $violation->client_department_id);
        $this->assertSame($coordinator->id, $violation->reported_by);
        $this->assertSame($this->hr->id, $violation->issued_by);
        $this->assertSame('awol', $violation->violation_type);
        $this->assertSame('Written warning', $violation->penalty);

        // It is part of the employee's history, with its source.
        $history = $this->getJson("/api/v1/employees/{$this->employeeA1->id}/violations")->json('data.violations');
        $this->assertSame($coordinator->full_name, $history[0]['reported_by']);

        // A validated report cannot be reviewed again.
        $this->postJson("/api/v1/violation-reports/{$id}/review", ['action' => 'dismiss', 'note' => 'x'])->assertStatus(409);
    }

    public function test_a_dismissed_report_records_nothing(): void
    {
        $id = $this->report($this->coordinator($this->clientA), $this->employeeA1, ['submit' => true]);

        $this->actAs($this->hr);
        $this->postJson("/api/v1/violation-reports/{$id}/review", ['action' => 'dismiss', 'note' => 'Excused absence.'])
            ->assertOk();

        $this->assertDatabaseCount('employee_violations', 0);
    }

    /** 12, 13, 14. */
    public function test_a_coordinator_cannot_record_violations_terminate_or_administer(): void
    {
        $coordinator = $this->coordinator($this->clientA);

        $this->actAs($this->hr);
        $termination = $this->postJson("/api/v1/employees/{$this->employeeA1->id}/terminations", [
            'reason' => 'Fourth AWOL', 'termination_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->actAs($coordinator);

        $this->postJson("/api/v1/employees/{$this->employeeA1->id}/terminations/{$termination}/finalise", ['confirm' => true])
            ->assertForbidden();
        $this->postJson("/api/v1/employees/{$this->employeeA1->id}/terminations", [
            'reason' => 'x', 'termination_date' => now()->toDateString(),
        ])->assertForbidden();
        $this->postJson("/api/v1/employees/{$this->employeeA1->id}/violations", [
            'violation_date' => now()->toDateString(), 'violation_type' => 'awol', 'description' => 'x',
        ])->assertForbidden();
        $this->postJson("/api/v1/employees/{$this->employeeA1->id}/attendance", ['entries' => [
            ['work_date' => now()->subDay()->toDateString(), 'time_in' => '08:00'],
        ]])->assertForbidden();

        $this->putJson('/api/v1/settings', ['settings' => ['empower.violations.termination_threshold' => 1]])->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', [])->assertForbidden();
        $this->getJson('/api/v1/audit-logs')->assertForbidden();

        $this->assertSame('active', $this->employeeA1->fresh()->employment_status);
        $this->assertDatabaseCount('employee_violations', 0);
    }

    // ============================================================ lifecycle

    /** 17, 22. */
    public function test_a_deactivated_coordinator_is_locked_out_and_their_reports_remain(): void
    {
        $coordinator = $this->coordinator($this->clientA, 'leaving@cdemanpower.local');
        $id = $this->report($coordinator, $this->employeeA1, ['submit' => true]);

        $this->actAs($this->admin);
        $this->patchJson("/api/v1/users/{$coordinator->id}/status", ['is_active' => false])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'leaving@cdemanpower.local', 'password' => 'Coordinator-Pass-2026!',
        ])->assertStatus(422);

        $this->actAs($coordinator->fresh());
        $this->getJson('/api/v1/coordinator/dashboard')->assertForbidden();

        $this->actAs($this->hr);
        $this->getJson("/api/v1/violation-reports/{$id}")->assertOk()
            ->assertJsonPath('data.reported_by.name', $coordinator->full_name);
    }

    /** 18, 19, 23. */
    public function test_a_reassigned_coordinator_sees_only_the_new_client_and_history_is_kept(): void
    {
        $coordinator = $this->coordinator($this->clientA);
        $oldReport = $this->report($coordinator, $this->employeeA1, ['submit' => true]);

        $this->actAs($this->admin);
        $this->postJson("/api/v1/users/{$coordinator->id}/coordinator-assignment", [
            'client_company_id' => $this->clientA->id,
        ])->assertStatus(422);
        $this->postJson("/api/v1/users/{$coordinator->id}/coordinator-assignment", [
            'client_company_id' => $this->clientB->id,
            'remarks' => 'Moved to cover Client B.',
        ])->assertOk()->assertJsonPath('data.coordinator_assignment.company_name', 'Client B Corporation');

        $history = $this->getJson("/api/v1/users/{$coordinator->id}/coordinator-assignments")->assertOk()->json('data');
        $this->assertCount(2, $history);
        $this->assertSame(['active', 'ended'], array_column($history, 'status'));
        $this->assertNotNull($history[1]['unassigned_at']);
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'reassign', 'client_company_id' => $this->clientB->id]);

        $this->actAs($coordinator->fresh());
        $this->assertSame(
            [$this->employeeB1->id],
            array_column($this->getJson('/api/v1/coordinator/employees')->json('data'), 'id')
        );
        $this->getJson("/api/v1/coordinator/employees/{$this->employeeA1->id}")->assertForbidden();
        $this->getJson("/api/v1/coordinator/violation-reports/{$oldReport}")->assertForbidden();

        // The report made for client A is untouched, still client A's.
        $this->assertSame($this->clientA->id, ViolationReport::find($oldReport)->client_company_id);
        $this->actAs($this->hr);
        $this->getJson("/api/v1/violation-reports/{$oldReport}")->assertOk();
        $this->assertSame(1, CoordinatorAssignment::where('coordinator_user_id', $coordinator->id)->active()->count());
    }

    public function test_coordinators_hear_about_their_own_client_only(): void
    {
        $coordinatorA = $this->coordinator($this->clientA);
        $coordinatorB = $this->coordinator($this->clientB);
        config(['empower.violations.termination_threshold' => 1]);

        // HR records directly; the threshold is reached at client A.
        $this->actAs($this->hr);
        $this->postJson("/api/v1/employees/{$this->employeeA1->id}/violations", [
            'violation_date' => now()->toDateString(), 'violation_type' => 'awol', 'description' => 'AWOL',
        ])->assertCreated()->assertJsonPath('data.threshold_reached', true);

        $this->assertSame(1, $coordinatorA->notifications()->where('type', CoordinatorEmployeeFlagged::class)->count());
        $this->assertSame(0, $coordinatorB->notifications()->count());

        // HR's direct entry is stamped with the client too.
        $this->assertDatabaseHas('employee_violations', [
            'employee_id' => $this->employeeA1->id, 'client_company_id' => $this->clientA->id, 'reported_by' => null,
        ]);

        // And the coordinator's dashboard flags the employee, without changing anything.
        $this->actAs($coordinatorA);
        $flagged = $this->getJson('/api/v1/coordinator/dashboard')->json('data.flagged_employees');
        $this->assertSame([$this->employeeA1->id], array_column($flagged, 'id'));
        $this->assertSame('active', $this->employeeA1->fresh()->employment_status);
    }

    public function test_another_coordinators_draft_stays_private_and_only_drafts_can_be_discarded(): void
    {
        $first = $this->coordinator($this->clientA);
        $second = $this->coordinator($this->clientA);

        $draft = $this->report($first, $this->employeeA1);
        $sent = $this->report($first, $this->employeeA2, ['submit' => true]);

        $this->actAs($second);
        $this->getJson("/api/v1/coordinator/violation-reports/{$draft}")->assertForbidden();
        $this->assertSame([$sent], array_column($this->getJson('/api/v1/coordinator/violation-reports')->json('data'), 'id'));

        $this->actAs($first);
        $this->deleteJson("/api/v1/coordinator/violation-reports/{$sent}")->assertForbidden();
        $this->deleteJson("/api/v1/coordinator/violation-reports/{$draft}")->assertOk();

        $this->assertDatabaseMissing('violation_reports', ['id' => $draft]);
        $this->assertDatabaseHas('violation_reports', ['id' => $sent]);
    }
}
