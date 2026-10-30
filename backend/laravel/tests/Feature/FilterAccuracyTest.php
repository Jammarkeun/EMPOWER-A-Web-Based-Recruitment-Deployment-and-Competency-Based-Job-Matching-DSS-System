<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Archive;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\User;
use App\Models\ViolationReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ThrowawayDatabase;
use Tests\Support\CoordinatorFixtures;
use Tests\TestCase;

/**
 * Filters return the records their name promises, together, page by page, and
 * in agreement with the figures that open them (system-wide filter audit,
 * 2026-10-30).
 *
 * The defect that started it: the applicant list's "Deployed" filter came back
 * empty while deployed workers existed, because deployment moves an applicant
 * through "deployed" and on to "active" in one transaction and the filter read
 * the status column. Expected results here are written out by hand from the
 * fixtures, never computed with the query under test.
 *
 * Runs on SQLite with the rest of the suite. It can also run against the
 * throwaway MariaDB database, where collation and tie ordering differ:
 * DB_CONNECTION=mysql MYSQL_DATABASE=empower_test. Any other MySQL database is
 * refused before anything is migrated.
 */
class FilterAccuracyTest extends TestCase
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

    // ------------------------------------------------------------- fixtures

    /** Completes a resignation through the real workflow, as HR would. */
    private function resign(Employee $employee): void
    {
        $this->actAs($this->hr);

        $id = $this->postJson("/api/v1/employees/{$employee->id}/resignations", [
            'reason' => 'Moving to another province',
            'filing_date' => now()->subDays(20)->toDateString(),
            'exit_date' => now()->subDays(2)->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/employees/{$employee->id}/resignations/{$id}", ['clearance_status' => 'cleared'])->assertOk();
        $this->postJson("/api/v1/employees/{$employee->id}/resignations/{$id}/complete", ['confirm' => true])->assertOk();
    }

    /**
     * The people the Deployed filter has to tell apart.
     *
     * @return array<string, Applicant>
     */
    private function deploymentCast(): array
    {
        $never = $this->applicant($this->hr, ['first_name' => 'Nora', 'last_name' => 'Never', 'current_status' => 'ready_for_deployment']);
        $incomplete = $this->applicant($this->hr, ['first_name' => 'Ivan', 'last_name' => 'Incomplete', 'current_status' => 'incomplete_requirements']);

        $active = $this->placeEmployee($this->deptA, 'Ana', 'Active')->applicant;
        $twice = $this->placeEmployee($this->deptB, 'Toni', 'Twice');
        $former = $this->placeEmployee($this->deptA, 'Fe', 'Former');
        $this->resign($former);

        // An earlier, ended placement for the same worker: two deployments,
        // one person.
        Deployment::create([
            'deployment_code' => 'DEP-TEST-OLD',
            'employee_id' => $twice->id,
            'job_request_id' => $twice->deployments()->value('job_request_id'),
            'client_company_id' => $this->clientA->id,
            'client_department_id' => $this->deptA->id,
            'position_title' => 'Packer',
            'deployment_date' => now()->subYear()->toDateString(),
            'end_date' => now()->subMonths(6)->toDateString(),
            'deployment_status' => 'ended',
            'created_by' => $this->hr->id,
        ]);

        // Records whose status disagrees with the employment record, as some
        // in the demo database do. Left as they are; the filter must still
        // say who is working.
        $stale = $this->placeEmployee($this->deptA, 'Stella', 'Stale')->applicant;
        $stale->forceFill(['current_status' => 'approved'])->save();
        $orphan = $this->applicant($this->hr, ['first_name' => 'Oscar', 'last_name' => 'Orphan', 'current_status' => 'active']);

        return [
            'never' => $never,
            'incomplete' => $incomplete,
            'active' => $active,
            'twice' => $twice->applicant,
            'former' => $former->applicant->fresh(),
            'stale' => $stale,
            'orphan' => $orphan,
        ];
    }

    private function ids(array $applicants): array
    {
        $ids = array_map(fn (Applicant $a) => $a->id, $applicants);
        sort($ids);

        return $ids;
    }

    private function listedIds($response): array
    {
        $ids = collect($response->json('data'))->pluck('id')->all();
        sort($ids);

        return $ids;
    }

    // ================================================ applicants: Deployed

    public function test_deployed_means_placed_today_by_the_employment_record(): void
    {
        $cast = $this->deploymentCast();
        $this->actAs($this->hr);

        $response = $this->getJson('/api/v1/applicants?current_status=deployed')->assertOk();

        $this->assertSame(
            $this->ids([$cast['active'], $cast['twice'], $cast['stale']]),
            $this->listedIds($response),
            'Only people with an active employee record and an active deployment.'
        );
        // The worker with two deployments is listed once, and counted once.
        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_nobody_rests_at_the_deployed_status_which_is_why_the_column_could_not_answer(): void
    {
        $cast = $this->deploymentCast();

        // Deployment walked them through "deployed" and on to "active".
        $this->assertSame('active', $cast['active']->fresh()->current_status);
        $this->assertSame(0, Applicant::where('current_status', 'deployed')->count());
        $this->assertSame('resigned', $cast['former']->current_status);
    }

    public function test_the_row_shows_placement_and_names_a_status_that_disagrees(): void
    {
        $cast = $this->deploymentCast();
        $this->actAs($this->hr);

        $rows = collect($this->getJson('/api/v1/applicants?per_page=100')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(
            ['deployed' => true, 'company' => $this->clientA->company_name, 'mismatch' => null],
            $rows[$cast['active']->id]['placement']
        );
        // Placed at client B today, though an earlier placement was at A.
        $this->assertSame($this->clientB->company_name, $rows[$cast['twice']->id]['placement']['company']);
        $this->assertSame('status_not_updated', $rows[$cast['stale']->id]['placement']['mismatch']);
        $this->assertSame('no_active_deployment', $rows[$cast['orphan']->id]['placement']['mismatch']);
        $this->assertFalse($rows[$cast['former']->id]['placement']['deployed']);
        $this->assertFalse($rows[$cast['never']->id]['placement']['deployed']);

        // Nothing was corrected to make the list agree.
        $this->assertSame('approved', $cast['stale']->fresh()->current_status);
        $this->assertSame('active', $cast['orphan']->fresh()->current_status);
    }

    public function test_every_other_status_still_reads_the_status_column(): void
    {
        $cast = $this->deploymentCast();
        $this->actAs($this->hr);

        $this->assertSame([$cast['incomplete']->id], $this->listedIds(
            $this->getJson('/api/v1/applicants?current_status=incomplete_requirements')->assertOk()
        ));
        $this->assertSame([$cast['stale']->id], $this->listedIds(
            $this->getJson('/api/v1/applicants?current_status=approved')->assertOk()
        ));
        $this->assertSame($this->ids([$cast['active'], $cast['twice'], $cast['orphan']]), $this->listedIds(
            $this->getJson('/api/v1/applicants?current_status=active')->assertOk()
        ));
        $this->assertSame([$cast['former']->id], $this->listedIds(
            $this->getJson('/api/v1/applicants?current_status=resigned')->assertOk()
        ));
    }

    public function test_deployed_combines_with_search_and_other_filters_as_and(): void
    {
        $cast = $this->deploymentCast();
        $this->actAs($this->hr);

        $this->assertSame([$cast['twice']->id], $this->listedIds(
            $this->getJson('/api/v1/applicants?current_status=deployed&search=Toni+Twice')->assertOk()
        ));
        // A deployed name and a filter it fails: nobody.
        $this->getJson('/api/v1/applicants?current_status=deployed&search=Nora')
            ->assertOk()->assertJsonPath('meta.total', 0);

        // Folders set outright: one deployed worker in Folder 1, everyone
        // else in Folder 3. Both conditions must hold.
        Applicant::query()->update(['folder_category' => 'folder_3']);
        $cast['twice']->forceFill(['folder_category' => 'folder_1'])->save();
        $cast['never']->forceFill(['folder_category' => 'folder_1'])->save();

        $this->assertSame([$cast['twice']->id], $this->listedIds(
            $this->getJson('/api/v1/applicants?current_status=deployed&folder_category=folder_1')->assertOk()
        ));
    }

    public function test_several_statuses_at_once_and_an_unknown_one_refused(): void
    {
        $cast = $this->deploymentCast();
        $this->actAs($this->hr);

        $this->assertSame($this->ids([$cast['never'], $cast['incomplete']]), $this->listedIds(
            $this->getJson('/api/v1/applicants?current_status=ready_for_deployment,incomplete_requirements')->assertOk()
        ));
        // "deployed" among several keeps its meaning.
        $this->assertSame($this->ids([$cast['active'], $cast['twice'], $cast['stale'], $cast['former']]), $this->listedIds(
            $this->getJson('/api/v1/applicants?current_status=deployed,resigned')->assertOk()
        ));

        $this->getJson('/api/v1/applicants?current_status=hired')->assertStatus(422);
        $this->getJson('/api/v1/applicants?current_status=deployed,hired')->assertStatus(422);
    }

    public function test_the_applicant_report_reads_deployed_the_same_way(): void
    {
        $this->deploymentCast();
        $this->actAs($this->hr);

        $list = $this->getJson('/api/v1/applicants?current_status=deployed')->assertOk()->json('meta.total');
        $report = $this->postJson('/api/v1/reports/preview', [
            'report_type' => 'applicants',
            'filters' => ['current_status' => 'deployed'],
        ])->assertOk();

        $this->assertSame(3, $list);
        $this->assertSame($list, $report->json('data.total_rows'));
    }

    // ===================================================== paging and order

    public function test_ties_are_broken_so_pages_neither_repeat_nor_skip_anybody(): void
    {
        $this->actAs($this->hr);
        // Same application date for all, as the demo seeder left fifty.
        $ids = collect(range(1, 25))
            ->map(fn ($i) => $this->applicant($this->hr, ['first_name' => "Same{$i}", 'application_date' => '2026-10-01'])->id)
            ->all();

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson("/api/v1/applicants?per_page=10&page={$page}")->assertOk();
            $this->assertSame(25, $response->json('meta.total'));
            $seen = [...$seen, ...collect($response->json('data'))->pluck('id')->all()];
        }

        rsort($ids);
        $this->assertSame($ids, $seen, 'Newest record first among equal dates, each exactly once.');
    }

    // ======================================= dashboard figures and their lists

    public function test_each_applicant_figure_opens_a_list_of_exactly_the_people_it_counted(): void
    {
        $cast = $this->deploymentCast();
        $folder = function (Applicant $a, string $f): Applicant {
            $a->forceFill(['folder_category' => $f])->save();

            return $a;
        };
        // Every folder stated outright, so the expected people do not depend
        // on what verifying the fixtures' documents happened to leave.
        Applicant::query()->update(['folder_category' => 'folder_3']);

        $ready = $folder($this->applicant($this->hr, ['current_status' => 'ready_for_deployment']), 'folder_1');
        $approved = $folder($this->applicant($this->hr, ['current_status' => 'approved']), 'folder_1');
        $folder($this->applicant($this->hr, ['current_status' => 'training_scheduled']), 'folder_1');
        $folder($this->applicant($this->hr, ['current_status' => 'approved']), 'folder_2');
        // An employee keeps Folder 1; the old link listed them as ready.
        $folder($cast['active'], 'folder_1');
        // Deployed, but the status was put back to "approved" by hand: working,
        // so not waiting for a placement either.
        $folder($cast['stale'], 'folder_1');
        $screening = $this->applicant($this->hr, ['current_status' => 'initial_screening']);

        $this->actAs($this->hr);
        $summary = $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data');

        $readyList = $this->getJson('/api/v1/applicants?'.http_build_query($summary['links']['ready_for_deployment'] + ['per_page' => 100]))->assertOk();
        $this->assertSame($summary['headline']['ready_for_deployment'], $readyList->json('meta.total'));
        $this->assertSame($this->ids([$ready, $approved]), $this->listedIds($readyList));

        $docsList = $this->getJson('/api/v1/applicants?'.http_build_query($summary['links']['incomplete_requirements'] + ['per_page' => 100]))->assertOk();
        $this->assertSame($summary['attention']['incomplete_requirements'], $docsList->json('meta.total'));
        $this->assertSame($this->ids([$cast['incomplete'], $screening]), $this->listedIds($docsList));
    }

    public function test_resignations_waiting_on_hr_and_on_the_employee_are_counted_apart(): void
    {
        $resignation = function (string $first, array $state) {
            $employee = $this->placeEmployee($this->deptA, $first, 'Leaving');
            $this->actAs($this->hr);
            $id = $this->postJson("/api/v1/employees/{$employee->id}/resignations", [
                'reason' => 'Family',
                'filing_date' => now()->toDateString(),
            ])->assertCreated()->json('data.id');
            DB::table('resignations')->where('id', $id)->update($state);

            return $id;
        };

        $filed = $resignation('Fely', ['status' => 'filed']);
        $waiting = $resignation('Wanda', ['status' => 'info_requested']);
        // Accepted and already cleared: nothing left for HR to clear.
        $resignation('Clara', ['status' => 'accepted', 'clearance_status' => 'cleared']);

        $summary = $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data');
        $this->assertSame(1, $summary['attention']['pending_clearance'], 'Only the one HR still has to clear.');
        $this->assertSame(1, $summary['attention']['resignations_awaiting_employee']);

        foreach ([['pending_clearance', $filed], ['resignations_awaiting_employee', $waiting]] as [$figure, $expected]) {
            $listed = collect($this->getJson('/api/v1/separations?'.http_build_query($summary['links'][$figure]))->assertOk()->json('data'))
                ->pluck('id')->all();
            $this->assertSame([$expected], $listed, "The {$figure} figure opens onto the case it counted.");
        }
    }

    // ======================================================= clients

    public function test_a_client_counts_as_deployed_only_the_people_placed_there_today(): void
    {
        $this->placeEmployee($this->deptA, 'Still', 'Here');
        $this->resign($this->placeEmployee($this->deptA, 'Gone', 'Away'));

        $this->actAs($this->hr);
        $row = collect($this->getJson('/api/v1/clients?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $this->clientA->id);
        $overview = $this->getJson("/api/v1/clients/{$this->clientA->id}/overview")->assertOk();

        $this->assertSame(1, $row['deployed_count']);
        $this->assertSame($row['deployed_count'], $overview->json('data.summary.deployed_staff'));
    }

    public function test_the_client_open_request_figure_matches_its_open_only_list(): void
    {
        foreach (['open', 'in_progress', 'partially_fulfilled', 'fulfilled', 'cancelled'] as $status) {
            $this->jobRequest($this->deptA, $this->hr, ['request_status' => $status]);
        }
        $this->jobRequest($this->deptB, $this->hr, ['request_status' => 'open']);

        $this->actAs($this->hr);
        $figure = $this->getJson("/api/v1/clients/{$this->clientA->id}/overview")->assertOk()->json('data.summary.open_requests');
        $list = $this->getJson("/api/v1/job-requests?client_company_id={$this->clientA->id}&only_open=1")->assertOk();

        $this->assertSame(3, $figure, '"In progress" still needs workers.');
        $this->assertSame($figure, $list->json('meta.total'));
        $this->assertEqualsCanonicalizing(
            ['open', 'in_progress', 'partially_fulfilled'],
            collect($list->json('data'))->pluck('request_status')->all()
        );
    }

    // ================================================ status values checked

    public function test_a_status_a_list_does_not_have_is_refused_rather_than_answered_with_nothing(): void
    {
        $this->actAs($this->hr);

        $this->getJson('/api/v1/employees?employment_status=fired')->assertStatus(422);
        $this->getJson('/api/v1/deployments?deployment_status=done')->assertStatus(422);
        $this->getJson('/api/v1/job-requests?request_status=opened')->assertStatus(422);
        $this->getJson('/api/v1/separations?status=rejected')->assertStatus(422);
        // Values the schema once listed but nothing ever wrote.
        $this->getJson('/api/v1/employees?employment_status=archived')->assertStatus(422);
        $this->getJson('/api/v1/deployments?deployment_status=completed')->assertStatus(422);
        $this->getJson('/api/v1/deployments?deployment_status=transferred')->assertStatus(422);
        // A boolean is 1 or 0 on the wire.
        $this->getJson('/api/v1/job-requests?only_open=1')->assertOk();
        // A period that ends before it starts.
        $this->getJson('/api/v1/attendance/exceptions?from=2026-10-20&to=2026-10-01')->assertStatus(422);
    }

    public function test_employee_and_deployment_filters_combine_and_include_their_date_boundaries(): void
    {
        $here = $this->placeEmployee($this->deptA, 'Ana', 'Here');
        $there = $this->placeEmployee($this->deptB, 'Ben', 'There');
        $gone = $this->placeEmployee($this->deptA, 'Cris', 'Gone');
        $this->resign($gone);
        $this->actAs($this->hr);

        $ids = fn ($response) => collect($response->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$here->id], $ids($this->getJson("/api/v1/employees?employment_status=active&client_company_id={$this->clientA->id}")));
        $this->assertSame([$gone->id], $ids($this->getJson('/api/v1/employees?employment_status=resigned')));
        $this->assertSame([$there->id], $ids($this->getJson('/api/v1/employees?search=Ben+There')));

        // Deployed exactly on the boundary days is inside the range.
        $day = $here->deployments()->first()->deployment_date->toDateString();
        $inRange = collect($this->getJson("/api/v1/deployments?date_from={$day}&date_to={$day}&client_company_id={$this->clientA->id}&deployment_status=active")
            ->assertOk()->json('data'))->pluck('employee_id')->all();
        $this->assertSame([$here->id], $inRange);
    }

    // ================================================= users

    public function test_every_account_can_be_reached_page_by_page(): void
    {
        foreach (range(1, 55) as $i) {
            User::factory()->create(['email' => "portal{$i}@example.test", 'user_type' => 'applicant']);
        }
        $coordinator = $this->coordinatorFor($this->clientA);
        $this->actAs($this->admin);

        $first = $this->getJson('/api/v1/users?per_page=50')->assertOk();
        $total = $first->json('meta.total');
        $seen = collect($first->json('data'))->pluck('id');
        for ($page = 2; $page <= $first->json('meta.last_page'); $page++) {
            $seen = $seen->merge(collect($this->getJson("/api/v1/users?per_page=50&page={$page}")->assertOk()->json('data'))->pluck('id'));
        }

        $this->assertSame($total, $seen->unique()->count());
        $this->assertSame($total, $seen->count());
        $this->assertContains($this->hr->id, $seen->all(), 'HR used to sort past the only page shown.');
        $this->assertContains($coordinator->id, $seen->all());
    }

    public function test_a_deployed_workers_registration_login_is_an_employee_portal_account(): void
    {
        $employee = $this->placeEmployee($this->deptA, 'Reg', 'Istered');
        $worker = User::factory()->create([
            'email' => 'worker@example.test', 'user_type' => 'applicant', 'applicant_id' => $employee->applicant_id,
        ]);
        $applicant = User::factory()->create([
            'email' => 'applicant@example.test', 'user_type' => 'applicant',
            'applicant_id' => $this->applicant($this->hr)->id,
        ]);
        $this->actAs($this->admin);

        $employees = collect($this->getJson('/api/v1/users?user_type=employee')->assertOk()->json('data'))->keyBy('id');
        $applicants = collect($this->getJson('/api/v1/users?user_type=applicant')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($employees->has($worker->id));
        $this->assertSame('employee', $employees[$worker->id]['portal_access']);
        $this->assertSame('applicant', $employees[$worker->id]['user_type'], 'The login itself is not rewritten.');
        $this->assertFalse($applicants->has($worker->id));
        $this->assertTrue($applicants->has($applicant->id));
        $this->assertSame('applicant', $applicants[$applicant->id]['portal_access']);
    }

    // ================================================= archives

    public function test_archive_search_ignores_letter_case(): void
    {
        Archive::create([
            'entity_type' => 'applicant',
            'entity_id' => 999,
            'archive_reason' => 'Archived applicant record',
            'snapshot_json' => ['person' => ['first_name' => 'Joshua', 'last_name' => 'Ramirez']],
            'archived_by' => $this->hr->id,
            'archived_at' => now(),
        ]);
        $this->actAs($this->admin);

        foreach (['ramirez', 'RAMIREZ', 'Ramirez'] as $term) {
            $this->getJson('/api/v1/archives?search='.$term)->assertOk()->assertJsonPath('meta.total', 1);
        }
        $this->getJson('/api/v1/archives?search=ramirez&entity_type=employee')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_archive_search_finds_accented_names_however_the_snapshot_stored_them(): void
    {
        // Written before 2026-10-30: every accented letter escaped, as the
        // old array cast stored it.
        DB::table('archives')->insert([
            'entity_type' => 'employee',
            'entity_id' => 998,
            'archive_reason' => 'Resignation completed',
            'snapshot_json' => json_encode(['person' => ['first_name' => 'Angelica', 'last_name' => 'Dela Peña']]),
            'archived_by' => $this->hr->id,
            'archived_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Written now: the letter kept as itself.
        $new = Archive::create([
            'entity_type' => 'employee',
            'entity_id' => 997,
            'archive_reason' => 'Termination finalised',
            'snapshot_json' => ['person' => ['first_name' => 'Ramon', 'last_name' => 'Muñoz']],
            'archived_by' => $this->hr->id,
            'archived_at' => now(),
        ]);
        $this->assertStringContainsString('Muñoz', DB::table('archives')->where('id', $new->id)->value('snapshot_json'));
        $this->actAs($this->admin);

        $found = fn (string $term) => collect($this->getJson('/api/v1/archives?search='.urlencode($term))->assertOk()->json('data'))
            ->pluck('entity_id')->all();

        foreach (['peña', 'PEÑA', 'Peña', 'dela peña', 'Dela Peña'] as $term) {
            $this->assertSame([998], $found($term), "'{$term}' finds the older snapshot.");
        }
        foreach (['muñoz', 'Muñoz'] as $term) {
            $this->assertSame([997], $found($term), "'{$term}' finds the newer snapshot.");
        }
        $this->assertSame([], $found('pe_a'), 'An underscore is a character, not a wildcard.');
        $this->assertSame([], $found('%'));
    }

    // ============================================ search boxes match as typed

    public function test_percent_and_underscore_are_searched_for_not_treated_as_wildcards(): void
    {
        $sale = $this->clientCompany($this->hr, ['company_name' => '50% Off Retail Corp']);
        $this->clientCompany($this->hr, ['company_name' => '500 Goods Retail Corp']);
        $joan = $this->applicant($this->hr, ['first_name' => 'Joan', 'email' => 'jo_an@example.test']);
        $this->applicant($this->hr, ['first_name' => 'Joxan', 'email' => 'joxan@example.test']);
        $account = User::factory()->create(['email' => 'ana_b@example.test', 'user_type' => 'applicant']);
        User::factory()->create(['email' => 'anaxb@example.test', 'user_type' => 'applicant']);

        $this->actAs($this->admin);
        $ids = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$sale->id], $ids('/api/v1/clients?search='.urlencode('50%')));
        $this->assertSame([$sale->id], $ids('/api/v1/clients?search='.urlencode('%')), 'A lone % used to return every client.');
        $this->assertSame([$joan->id], $ids('/api/v1/applicants?search='.urlencode('jo_an')));
        $this->assertSame([$account->id], $ids('/api/v1/users?search='.urlencode('ana_b')));

        $groups = collect($this->getJson('/api/v1/search?q='.urlencode('50%'))->assertOk()->json('data.groups'))->keyBy('type');
        $this->assertSame([$sale->id], collect($groups['clients']['results'])->pluck('id')->all());
    }

    public function test_sign_in_history_finds_a_mistyped_address_inside_the_audit_json(): void
    {
        // Failed attempts on unknown addresses: the email is only in the
        // entry's JSON, the one search that reads a JSON path.
        foreach (['typo_name@example.test', 'typoxname@example.test'] as $email) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Guess-123456'])->assertStatus(422);
        }
        $this->actAs($this->admin);

        $rows = collect($this->getJson('/api/v1/security/sign-ins?search='.urlencode('typo_name'))->assertOk()->json('data'));

        $this->assertSame(['typo_name@example.test'], $rows->pluck('attempted_email')->all());
    }

    // ================================================= coordinator scope

    public function test_a_coordinator_finds_a_full_name_but_only_at_their_own_client(): void
    {
        $mine = $this->placeEmployee($this->deptA, 'Maria', 'Santos');
        $theirs = $this->placeEmployee($this->deptB, 'Maria', 'Reyes');
        $coordinator = $this->coordinatorFor($this->clientA);
        $this->actAs($coordinator);

        $found = fn (string $q) => collect($this->getJson('/api/v1/coordinator/employees?search='.urlencode($q))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$mine->id], $found('Maria Santos'));
        $this->assertSame([$mine->id], $found('Maria'), "Client B's Maria is never in a client A list.");
        $this->assertSame([], $found('Maria Reyes'));
        $this->assertSame([], $found($theirs->employee_number));

        // Naming the other client's department is refused, not answered.
        $this->getJson("/api/v1/coordinator/employees?client_department_id={$this->deptB->id}")->assertForbidden();
        // Nor does a status filter widen the scope.
        $this->assertNotContains($theirs->id, collect($this->getJson('/api/v1/coordinator/employees?status=all')->json('data'))->pluck('id')->all());
    }

    public function test_the_coordinator_figures_open_onto_the_reports_they_count(): void
    {
        $employee = $this->placeEmployee($this->deptA, 'Rey', 'Ported');
        $coordinator = $this->coordinatorFor($this->clientA);
        $colleague = $this->coordinatorFor($this->clientA);
        $this->actAs($coordinator);

        $submit = fn () => $this->postJson('/api/v1/coordinator/violation-reports', [
            'employee_id' => $employee->id,
            'violation_type' => 'late',
            'incident_date' => now()->subDay()->toDateString(),
            'details' => 'Arrived after the start of the shift.',
            'submit' => true,
        ])->assertCreated()->json('data.id');

        $submitted = $submit();
        $reviewing = $submit();
        $validated = $submit();
        $returnedToMe = $submit();
        ViolationReport::whereKey($reviewing)->update(['status' => 'under_review']);
        ViolationReport::whereKey($validated)->update(['status' => 'validated']);
        ViolationReport::whereKey($returnedToMe)->update(['status' => 'returned']);

        $this->actAs($colleague);
        $returnedToColleague = $this->postJson('/api/v1/coordinator/violation-reports', [
            'employee_id' => $employee->id,
            'violation_type' => 'awol',
            'incident_date' => now()->subDay()->toDateString(),
            'details' => 'Did not report for the shift.',
            'submit' => true,
        ])->assertCreated()->json('data.id');
        ViolationReport::whereKey($returnedToColleague)->update(['status' => 'returned']);

        $this->actAs($coordinator);
        $summary = $this->getJson('/api/v1/coordinator/dashboard')->assertOk()->json('data.summary');
        $ids = fn (string $query) => collect($this->getJson('/api/v1/coordinator/violation-reports?'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $pending = $ids('status=pending');
        $this->assertSame([$submitted, $reviewing], $pending);
        $this->assertCount($summary['reports_pending'], $pending);

        $mineReturned = $ids('status=returned&mine=1');
        $this->assertSame([$returnedToMe], $mineReturned);
        $this->assertCount($summary['reports_returned'], $mineReturned);
    }
}
