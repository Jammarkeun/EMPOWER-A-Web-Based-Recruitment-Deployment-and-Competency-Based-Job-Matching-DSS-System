<?php

namespace Tests\Feature;

use App\Models\Training;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concurrency\ThrowawayDatabase;
use Tests\Support\CoordinatorFixtures;
use Tests\TestCase;

/**
 * Sorting and searching on the server, over the whole list rather than the
 * page on screen (usability audit, 2 November 2026: no list could be sorted).
 *
 * Each sort ends on a unique key, so the second page continues the first
 * instead of repeating or skipping anyone. Expected orders are written out
 * from the fixtures. Runs on SQLite with the suite and against the throwaway
 * MariaDB database (DB_CONNECTION=mysql MYSQL_DATABASE=empower_test), where
 * NULL ordering and collation differ.
 */
class ListSortingTest extends TestCase
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
        $this->actAs($this->hr);
    }

    /** One field of every row, across as many pages as there are. */
    private function column(string $path, array $query, string $field, int $perPage = 2): array
    {
        $values = [];
        $page = 1;
        do {
            $response = $this->getJson('/api/v1/'.$path.'?'.http_build_query($query + ['per_page' => $perPage, 'page' => $page]))->assertOk();
            array_push($values, ...array_column($response->json('data'), $field));
        } while ($page++ < $response->json('meta.last_page'));

        return $values;
    }

    public function test_employees_sort_by_name_with_first_names_settling_a_shared_surname(): void
    {
        $this->placeEmployee($this->deptA, 'Carlo', 'Reyes')->forceFill(['hire_date' => '2026-03-01'])->save();
        $this->placeEmployee($this->deptA, 'Ana', 'Reyes')->forceFill(['hire_date' => '2026-01-15'])->save();
        $this->placeEmployee($this->deptB, 'Bea', 'Abad')->forceFill(['hire_date' => '2026-02-10'])->save();

        $this->assertSame(['Bea Abad', 'Ana Reyes', 'Carlo Reyes'], $this->column('employees', ['sort_by' => 'name'], 'full_name'));
        $this->assertSame(['Carlo Reyes', 'Ana Reyes', 'Bea Abad'], $this->column('employees', ['sort_by' => 'name', 'sort_dir' => 'desc'], 'full_name'));

        // Unchanged default: the most recent hire first.
        $this->assertSame(['Carlo Reyes', 'Bea Abad', 'Ana Reyes'], $this->column('employees', [], 'full_name'));
        $this->assertSame(['Ana Reyes', 'Bea Abad', 'Carlo Reyes'], $this->column('employees', ['sort_by' => 'hire_date', 'sort_dir' => 'asc'], 'full_name'));

        // Sorting keeps the filters it is combined with.
        $this->assertSame(['Ana Reyes', 'Carlo Reyes'], $this->column('employees', ['sort_by' => 'name', 'client_company_id' => $this->clientA->id], 'full_name'));
    }

    public function test_applicants_sort_by_surname_then_first_name(): void
    {
        foreach ([['Lito', 'Santos'], ['Jun', 'Bautista'], ['Ella', 'Santos']] as [$first, $last]) {
            $this->applicant($this->hr, ['first_name' => $first, 'last_name' => $last]);
        }

        $names = $this->column('applicants', ['sort_by' => 'last_name', 'sort_dir' => 'asc'], 'full_name');
        $this->assertSame(['Jun Bautista', 'Ella Santos', 'Lito Santos'], $names);
    }

    public function test_deployments_can_be_searched_and_read_oldest_first(): void
    {
        $older = $this->placeEmployee($this->deptA, 'Mara', 'Lopez');
        $newer = $this->placeEmployee($this->deptB, 'Nilo', 'Cruz');
        $older->deployments()->update(['deployment_date' => now()->subMonths(3)->toDateString()]);

        $this->assertSame([$older->id, $newer->id], $this->column('deployments', ['sort_dir' => 'asc'], 'employee_id'));
        $this->assertSame([$newer->id, $older->id], $this->column('deployments', [], 'employee_id'));

        $this->assertSame([$older->id], $this->column('deployments', ['search' => 'lopez'], 'employee_id'));
        $this->assertSame([$newer->id], $this->column('deployments', ['search' => $newer->employee_number], 'employee_id'));
        $this->assertSame([], $this->column('deployments', ['search' => 'nobody by this name'], 'employee_id'));
    }

    public function test_training_search_keeps_the_alphabetical_order(): void
    {
        foreach (['Safety Orientation', 'Food Handling', 'safety refresher'] as $title) {
            Training::create([
                'training_code' => 'TRN-'.uniqid(), 'training_title' => $title, 'training_date' => now()->toDateString(),
                'location' => 'CDE Office', 'trainer_name' => 'Ms. Santos', 'status' => 'scheduled', 'created_by' => $this->hr->id,
            ]);
        }

        $this->assertSame(['Food Handling', 'Safety Orientation', 'safety refresher'], $this->column('trainings', [], 'training_title'));
        $this->assertSame(['Safety Orientation', 'safety refresher'], $this->column('trainings', ['search' => 'SAFETY'], 'training_title'));
        $this->assertCount(3, $this->column('trainings', ['search' => 'cde office'], 'training_title'));
    }

    public function test_requests_by_deadline_put_those_without_one_last_either_way(): void
    {
        $late = $this->jobRequest($this->deptA, $this->hr, ['deployment_deadline' => '2026-12-20']);
        $none = $this->jobRequest($this->deptA, $this->hr, ['deployment_deadline' => null]);
        $soon = $this->jobRequest($this->deptB, $this->hr, ['deployment_deadline' => '2026-11-10']);

        $this->assertSame([$soon->id, $late->id, $none->id], $this->column('job-requests', ['sort_by' => 'deployment_deadline'], 'id'));
        $this->assertSame([$late->id, $soon->id, $none->id], $this->column('job-requests', ['sort_by' => 'deployment_deadline', 'sort_dir' => 'desc'], 'id'));
    }

    public function test_an_unknown_sort_is_refused_and_search_stays_behind_the_list_permission(): void
    {
        $this->getJson('/api/v1/employees?sort_by=salary')->assertStatus(422);
        $this->getJson('/api/v1/job-requests?sort_by=client_secret')->assertStatus(422);
        $this->getJson('/api/v1/deployments?sort_dir=sideways')->assertStatus(422);

        $this->actAs($this->coordinatorFor($this->clientA));
        $this->getJson('/api/v1/deployments?search=a')->assertForbidden();
        $this->getJson('/api/v1/employees?sort_by=name')->assertForbidden();
    }
}
