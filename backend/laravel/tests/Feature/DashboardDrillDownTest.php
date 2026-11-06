<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\EmployeeViolation;
use App\Models\IncidentReport;
use App\Models\ManpowerRequisition;
use App\Models\RequirementType;
use App\Models\Termination;
use App\Models\User;
use App\Models\ViolationReport;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Concurrency\ThrowawayDatabase;
use Tests\Support\CoordinatorFixtures;
use Tests\TestCase;

/**
 * Every "Needs attention" figure opens a list of exactly what it counted
 * (usability audit, 2 November 2026).
 *
 * Five figures used to open an unfiltered list - "Unresolved violations" and
 * "Employee renewals overdue" opened every employee - so the person had to
 * find the counted records by hand. Three of them count documents or
 * violations while their list shows people, so the summary also says how many
 * people the list will hold. Expected values are written out from the
 * fixtures, never computed with the queries under test.
 *
 * Runs on SQLite with the suite, and against the throwaway MariaDB database
 * (DB_CONNECTION=mysql MYSQL_DATABASE=empower_test); any other MySQL
 * database is refused before anything is migrated.
 */
class DashboardDrillDownTest extends TestCase
{
    use CoordinatorFixtures;
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            ThrowawayDatabase::assert();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoClients();
    }

    /** Makes one of the person's documents a verified one that has lapsed. */
    private function lapse(Applicant $applicant, string $code, string $status = 'verified'): void
    {
        ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', RequirementType::where('requirement_code', $code)->value('id'))
            ->firstOrFail()
            ->forceFill(['status' => $status, 'expiry_date' => now()->subDays(3)->toDateString()])
            ->save();
    }

    private function summary(): array
    {
        Cache::flush();
        $this->actAs($this->admin);

        return $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data');
    }

    /** The ids a list returns when opened with a figure's link. */
    private function listed(string $path, array $filters): array
    {
        $ids = collect($this->getJson('/api/v1/'.$path.'?'.http_build_query($filters + ['per_page' => 100]))->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();

        return $ids;
    }

    private function violation(int $employeeId, string $status): void
    {
        (new EmployeeViolation())->forceFill([
            'employee_id' => $employeeId,
            'violation_date' => now()->subWeek()->toDateString(),
            'violation_type' => 'tardiness',
            'description' => 'Late three times in one week.',
            'issued_by' => $this->hr->id,
            'status' => $status,
        ])->save();
    }

    public function test_each_figure_opens_exactly_the_records_it_counted(): void
    {
        // Waiting for a posting: two lapsed documents, one person.
        $waiting = $this->applicant($this->hr, ['first_name' => 'Wena', 'current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($waiting, $this->hr, 'all');
        $this->lapse($waiting, 'police_clearance');
        $this->lapse($waiting, 'barangay_clearance', 'expired');

        // Nothing lapsed, and a closed record whose lapse no longer matters.
        $fresh = $this->applicant($this->hr, ['first_name' => 'Fe', 'current_status' => 'initial_screening']);
        $this->verifyRequirements($fresh, $this->hr, 'all');
        $closed = $this->applicant($this->hr, ['first_name' => 'Cleo', 'current_status' => 'resigned']);
        $this->verifyRequirements($closed, $this->hr, 'all');
        $this->lapse($closed, 'police_clearance');

        // Employed: a lapsed medical (renewed while employed) and a lapsed
        // police clearance (no rule after deployment, so not counted).
        $ana = $this->placeEmployee($this->deptA, 'Ana', 'Reyes');
        $this->lapse($ana->applicant, 'medical_result');
        $this->lapse($ana->applicant, 'police_clearance');
        // Two documents to renew, one worker.
        $ben = $this->placeEmployee($this->deptB, 'Ben', 'Cruz');
        $this->lapse($ben->applicant, 'health_card', 'expired');
        $this->lapse($ben->applicant, 'medical_result');
        $carla = $this->placeEmployee($this->deptA, 'Carla', 'Diaz');

        // Ana: two unresolved violations. Ben: a resolved one.
        $this->violation($ana->id, 'open');
        $this->violation($ana->id, 'under_review');
        $this->violation($ben->id, 'resolved');

        // One request past its deadline and still short of workers.
        $overdue = $this->jobRequest($this->deptA, $this->hr, ['deployment_deadline' => now()->subDay()->toDateString()]);
        $this->jobRequest($this->deptA, $this->hr, ['deployment_deadline' => now()->addWeek()->toDateString()]);
        $this->jobRequest($this->deptB, $this->hr, ['deployment_deadline' => now()->subDay()->toDateString(), 'request_status' => 'fulfilled']);

        // One termination awaiting an administrator, one already withdrawn.
        $forReview = (new Termination())->forceFill([
            'employee_id' => $carla->id, 'reason' => 'Repeated absences', 'termination_date' => now()->toDateString(),
            'status' => 'for_review', 'created_by' => $this->hr->id,
        ]);
        $forReview->save();
        (new Termination())->forceFill([
            'employee_id' => $ben->id, 'reason' => 'Filed in error', 'termination_date' => now()->toDateString(),
            'status' => 'withdrawn', 'created_by' => $this->hr->id,
        ])->save();

        $summary = $this->summary();
        $attention = $summary['attention'];
        $links = $summary['links'];

        $this->assertSame(2, $attention['expired_documents']);
        $this->assertSame(1, $attention['applicants_with_expired_documents']);
        $this->assertSame([$waiting->id], $this->listed('applicants', $links['expired_documents']));

        $this->assertSame(3, $attention['employee_renewals_overdue']);
        $this->assertSame(2, $attention['employees_with_overdue_renewals']);
        $this->assertSame([$ana->id, $ben->id], $this->listed('employees', $links['employee_renewals_overdue']));

        $this->assertSame(2, $attention['open_violations']);
        $this->assertSame(1, $attention['employees_with_open_violations']);
        $this->assertSame([$ana->id], $this->listed('employees', $links['open_violations']));

        $this->assertSame(1, $attention['overdue_requests']);
        $this->assertSame([$overdue->id], $this->listed('job-requests', $links['overdue_requests']));

        $this->assertSame(1, $attention['terminations_for_review']);
        $separations = $this->getJson('/api/v1/separations?'.http_build_query($links['terminations_for_review']))->assertOk()->json('data');
        $this->assertSame([['termination', $forReview->id]], array_map(fn ($row) => [$row['type'], $row['id']], $separations));
    }

    public function test_hrs_review_inboxes_count_what_is_still_waiting_and_open_onto_it(): void
    {
        // Documents: two waiting for one person (one nobody has opened), one
        // for another; a verified one and a checklist row with no file are
        // not HR's to review.
        $first = $this->applicant($this->hr, ['first_name' => 'Dina', 'current_status' => 'initial_screening']);
        $second = $this->applicant($this->hr, ['first_name' => 'Elma', 'current_status' => 'initial_screening']);
        $this->verifyRequirements($first, $this->hr, 'none');
        $this->verifyRequirements($second, $this->hr, 'all');
        $file = fn (Applicant $a, string $code, string $status, $viewed = null) => ApplicantRequirement::where('applicant_id', $a->id)
            ->where('requirement_type_id', RequirementType::where('requirement_code', $code)->value('id'))
            ->firstOrFail()
            ->forceFill(['status' => $status, 'file_path' => "requirements/{$a->id}/{$code}.pdf", 'first_viewed_at' => $viewed])
            ->save();
        $file($first, 'resume', 'submitted');
        $file($first, 'bio_data', 'pending', now());
        $file($second, 'police_clearance', 'submitted', now());

        // Registered online and not yet seen in person; and one who has been.
        $online = $this->applicant($this->hr, ['first_name' => 'Gina']);
        $online->forceFill(['self_registered_at' => now()->subDay()])->save();
        $this->applicant($this->hr, ['first_name' => 'Hana'])
            ->forceFill(['self_registered_at' => now()->subWeek(), 'identity_verified_at' => now()->subDays(6)])->save();

        // Coordinator paperwork, through the coordinator's own endpoints.
        $employee = $this->placeEmployee($this->deptA, 'Rey', 'Ported');
        $this->actAs($this->coordinatorFor($this->clientA));
        $report = fn () => $this->postJson('/api/v1/coordinator/violation-reports', [
            'employee_id' => $employee->id, 'violation_type' => 'late', 'incident_date' => now()->subDay()->toDateString(),
            'details' => 'Arrived after the start of the shift.', 'submit' => true,
        ])->assertCreated()->json('data.id');
        $requisition = fn () => $this->postJson('/api/v1/coordinator/requisitions', [
            'date_requested' => now()->toDateString(), 'required_by' => now()->addWeek()->toDateString(),
            'workers_needed' => 2, 'position_title' => 'Packer',
            'request_reason' => 'replacement', 'request_reason_details' => 'Two packers resigned.',
            'client_department_id' => $this->deptA->id, 'submit' => true,
        ])->assertCreated()->json('data.id');
        $incident = fn () => $this->postJson('/api/v1/coordinator/incident-reports', [
            'title' => 'Line stopped', 'incident_date' => now()->toDateString(), 'incident_time' => '12:30',
            'client_department_id' => $this->deptA->id, 'details' => 'The conveyor stopped for two hours.', 'submit' => true,
        ])->assertCreated()->json('data.id');

        [$newReport, $reviewing, $validated] = [$report(), $report(), $report()];
        ViolationReport::whereKey($reviewing)->update(['status' => 'under_review']);
        ViolationReport::whereKey($validated)->update(['status' => 'validated']);
        [$newRequisition, $returned] = [$requisition(), $requisition()];
        ManpowerRequisition::whereKey($returned)->update(['status' => 'returned']);
        [$newIncident, $resolved] = [$incident(), $incident()];
        IncidentReport::whereKey($resolved)->update(['status' => 'resolved']);

        $summary = $this->summary();
        $attention = $summary['attention'];
        $links = $summary['links'];

        $this->assertSame(3, $attention['documents_awaiting_review']);
        $this->assertSame(2, $attention['applicants_with_documents_awaiting_review']);
        $this->assertSame(1, $attention['documents_unopened']);
        $this->assertSame([$first->id, $second->id], $this->listed('documents/applicants', ['needs_review' => 1]));
        $this->assertSame(['filter' => 'needs_review'], $links['documents_awaiting_review']);

        $this->assertSame(2, $attention['violation_reports_awaiting_review']);
        $this->assertSame(1, $attention['violation_reports_new']);
        $this->assertSame([$newReport, $reviewing], $this->listed('violation-reports', $links['violation_reports_awaiting_review']));

        $this->assertSame(1, $attention['requisitions_awaiting_review']);
        $this->assertSame(1, $attention['requisitions_new']);
        $this->assertSame([$newRequisition], $this->listed('requisitions', $links['requisitions_awaiting_review']));

        $this->assertSame(1, $attention['incident_reports_open']);
        $this->assertSame([$newIncident], $this->listed('incident-reports', $links['incident_reports_open']));

        $this->assertSame(1, $attention['identity_checks']);
        $this->assertSame([$online->id], $this->listed('applicants', $links['identity_checks']));
    }

    public function test_a_figure_is_sent_only_to_someone_who_may_open_and_act_on_it(): void
    {
        $clerk = User::factory()->create(['email' => 'clerk.'.uniqid().'@cdemanpower.local', 'user_type' => 'hr']);
        $clerk->assignRole(Role::create(['name' => 'records-clerk', 'guard_name' => 'web'])
            ->givePermissionTo(['dashboard.view', 'applicants.view', 'requirements.view', 'violation_reports.view']));

        Cache::flush();
        $this->actAs($clerk);
        $summary = $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data');

        // Lists the clerk can open stay.
        $this->assertArrayHasKey('incomplete_requirements', $summary['attention']);
        $this->assertArrayHasKey('expired_documents', $summary['links']);
        // Lists they cannot open, and review work they cannot do, are gone,
        // figures and links alike: viewing reports is not reviewing them.
        foreach (['open_violations', 'terminations_for_review', 'overdue_requests', 'documents_awaiting_review',
            'violation_reports_awaiting_review', 'requisitions_awaiting_review', 'identity_checks'] as $key) {
            $this->assertArrayNotHasKey($key, $summary['attention'], $key);
            $this->assertArrayNotHasKey($key, $summary['links'], $key);
        }

        // HR, from the same cached summary, still sees all of it.
        $this->actAs($this->hr);
        $hr = $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.attention');
        $this->assertArrayHasKey('requisitions_awaiting_review', $hr);
        $this->assertArrayHasKey('identity_checks', $hr);
    }

    public function test_the_new_filters_combine_with_the_others_and_stay_behind_the_same_permissions(): void
    {
        $ana = $this->placeEmployee($this->deptA, 'Ana', 'Reyes');
        $ben = $this->placeEmployee($this->deptB, 'Ben', 'Cruz');
        $this->violation($ana->id, 'open');
        $this->violation($ben->id, 'escalated');

        $this->actAs($this->hr);
        // AND, not OR: an unresolved violation at one client only.
        $this->assertSame([$ben->id], $this->listed('employees', ['open_violations' => 1, 'client_company_id' => $this->clientB->id]));

        // A coordinator is still refused the staff list, filter or not.
        $this->actAs($this->coordinatorFor($this->clientA));
        $this->getJson('/api/v1/employees?open_violations=1')->assertForbidden();
        $this->getJson('/api/v1/applicants?expired_documents=1')->assertForbidden();

        // And a filter value that is not a yes/no is refused, not ignored.
        $this->actAs($this->hr);
        $this->getJson('/api/v1/job-requests?overdue=soon')->assertStatus(422);
    }
}
