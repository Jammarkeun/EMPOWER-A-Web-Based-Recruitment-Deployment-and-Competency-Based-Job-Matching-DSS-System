<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\JobRequest;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Concurrency\ThrowawayDatabase;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Two officers recording deployments at the same moment (audit S-04).
 *
 * Each deployment runs in a separate PHP process against a real database
 * server, which is the only way to test what two simultaneous requests do:
 * inside one process every statement is already one after another. The test
 * holds a row lock on a connection of its own, waits until every worker is
 * about to reach it, and releases it, so the workers arrive together.
 *
 * Opt-in. It commits real rows and rebuilds the schema, so it runs only with
 * RUN_CONCURRENCY_TESTS=1 and only against a database named `empower_test`
 * on this machine (see Tests\Support\Concurrency\ThrowawayDatabase). On the
 * XAMPP MariaDB server, create it once beside `empower`:
 *
 *   CREATE DATABASE empower_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 *
 * then, from backend/laravel:
 *
 *   DB_CONNECTION=mysql MYSQL_HOST=127.0.0.1 MYSQL_PORT=3306 \
 *     MYSQL_DATABASE=empower_test RUN_CONCURRENCY_TESTS=1 \
 *     php vendor/bin/phpunit --group concurrency
 *
 * (MYSQL_USERNAME and MYSQL_PASSWORD come from .env, as for the demo
 * database.) The demo database `empower`, every other database on the server,
 * and Supabase are refused before a single statement runs.
 */
#[Group('concurrency')]
class ConcurrentDeploymentTest extends TestCase
{
    use SeedsDomainData;

    private const WORKER = __DIR__.'/../Support/Concurrency/deploy_worker.php';

    private string $readyDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped('Opt-in: set RUN_CONCURRENCY_TESTS=1 against a local database named empower_test.');
        }

        ThrowawayDatabase::assert();

        // Committed, so the worker processes can see it.
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->seedReferenceData();

        $this->readyDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'empower-concurrency-'.uniqid();
        mkdir($this->readyDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->readyDir)) {
            array_map('unlink', glob($this->readyDir.DIRECTORY_SEPARATOR.'*') ?: []);
            @rmdir($this->readyDir);

            // Leaves an empty, migrated schema for whatever runs next.
            $this->artisan('migrate:fresh');
        }

        parent::tearDown();
    }

    public function test_the_same_applicant_deployed_twice_at_once_is_deployed_once(): void
    {
        $hr = $this->hrUser();
        $request = $this->openRequest($hr, 5);
        $applicant = $this->deployableApplicant($hr);

        $results = $this->race(
            fn (Connection $c) => $c->table('applicants')->where('id', $applicant->id)->lockForUpdate()->get(),
            [[$applicant, $request], [$applicant, $request]],
            $hr,
        );

        $this->assertOutcomes(['deployed', 'refused'], $results);
        $this->assertSame(409, $this->refusal($results)['code']);

        $this->assertSame(1, Employee::where('applicant_id', $applicant->id)->count());
        $this->assertSame(1, Deployment::count());
        $this->assertSame(1, (int) $request->fresh()->workers_fulfilled);
        $this->assertSame('active', $applicant->fresh()->current_status);
        $this->assertSame(1, $applicant->statusHistory()->where('to_status', 'deployed')->count());
    }

    public function test_two_officers_cannot_both_fill_the_last_position(): void
    {
        $hr = $this->hrUser();
        $request = $this->openRequest($hr, 1);
        $first = $this->deployableApplicant($hr);
        $second = $this->deployableApplicant($hr);

        $results = $this->race(
            fn (Connection $c) => $c->table('job_requests')->where('id', $request->id)->lockForUpdate()->get(),
            [[$first, $request], [$second, $request]],
            $hr,
        );

        $this->assertOutcomes(['deployed', 'refused'], $results);
        $this->assertSame(409, $this->refusal($results)['code']);

        $request->refresh();
        $this->assertSame(1, (int) $request->workers_fulfilled);
        $this->assertSame('fulfilled', $request->request_status);
        $this->assertSame(1, Deployment::where('job_request_id', $request->id)->count());

        // Whoever lost the race is untouched, still waiting to be placed.
        $loser = collect([$first, $second])->first(fn (Applicant $a) => ! $a->fresh()->employee);
        $this->assertNotNull($loser);
        $this->assertSame('ready_for_deployment', $loser->fresh()->current_status);
    }

    public function test_one_applicant_sent_to_two_requests_at_once_is_deployed_once(): void
    {
        $hr = $this->hrUser();
        $requestA = $this->openRequest($hr, 3);
        $requestB = $this->openRequest($hr, 3);
        $applicant = $this->deployableApplicant($hr);

        $results = $this->race(
            fn (Connection $c) => $c->table('applicants')->where('id', $applicant->id)->lockForUpdate()->get(),
            [[$applicant, $requestA], [$applicant, $requestB]],
            $hr,
        );

        $this->assertOutcomes(['deployed', 'refused'], $results);
        $this->assertSame(409, $this->refusal($results)['code']);

        $this->assertSame(1, Deployment::count());
        $this->assertSame(1, (int) $requestA->fresh()->workers_fulfilled + (int) $requestB->fresh()->workers_fulfilled);
    }

    /**
     * Separate requests and separate applicants share no lock, so all of
     * these must succeed - and the codes handed out at the same moment must
     * still all differ.
     */
    public function test_unrelated_deployments_at_once_all_succeed_with_distinct_codes(): void
    {
        $hr = $this->hrUser();

        // Codes already issued this year, as on any working day.
        $this->deployNow($this->deployableApplicant($hr), $this->openRequest($hr, 1), $hr);

        $pairs = [];
        for ($i = 0; $i < 4; $i++) {
            $pairs[] = [$this->deployableApplicant($hr), $this->openRequest($hr, 2)];
        }

        $applicantIds = array_map(fn (array $pair) => $pair[0]->id, $pairs);

        $results = $this->race(
            fn (Connection $c) => $c->table('applicants')->whereIn('id', $applicantIds)->orderBy('id')->lockForUpdate()->get(),
            $pairs,
            $hr,
        );

        $this->assertOutcomes(['deployed', 'deployed', 'deployed', 'deployed'], $results);

        $codes = array_column($results, 'deployment_code');
        $numbers = array_column($results, 'employee_number');
        $this->assertCount(4, array_unique($codes), 'Two deployments were given the same code.');
        $this->assertCount(4, array_unique($numbers), 'Two employees were given the same number.');
        $this->assertSame(5, Deployment::count());
    }

    // ---------------------------------------------------------------- helpers

    private function openRequest(User $hr, int $workersNeeded): JobRequest
    {
        $company = $this->clientCompany($hr);

        return $this->jobRequest($this->department($company, $hr), $hr, ['workers_needed' => $workersNeeded]);
    }

    private function deployableApplicant(User $hr): Applicant
    {
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return $applicant;
    }

    private function deployNow(Applicant $applicant, JobRequest $request, User $hr): void
    {
        app(\App\Services\DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
        ], $hr);
    }

    /**
     * Starts one worker per [applicant, request] pair while $lock is held on a
     * separate connection, waits until each has signalled it is about to
     * deploy, then releases the lock and collects what each reports.
     *
     * @param  array<int, array{0: Applicant, 1: JobRequest}>  $pairs
     * @return list<array<string, mixed>>
     */
    private function race(callable $lock, array $pairs, User $actor): array
    {
        $name = 'concurrency_holder';
        config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);
        DB::purge($name);

        $holder = DB::connection($name);
        $holder->beginTransaction();
        $lock($holder);

        $workers = [];
        foreach ($pairs as $index => [$applicant, $request]) {
            $ready = $this->readyDir.DIRECTORY_SEPARATOR."worker-{$index}.ready";

            $process = proc_open(
                [PHP_BINARY, self::WORKER, (string) $applicant->id, (string) $request->id, (string) $actor->id, $ready],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
            );

            $this->assertIsResource($process, 'Could not start a worker process.');
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'ready' => $ready];
        }

        try {
            // Every worker has booted and is about to deploy...
            $deadline = microtime(true) + 60;
            while (array_filter($workers, fn ($w) => ! file_exists($w['ready']))) {
                $this->assertLessThan($deadline, microtime(true), 'Workers did not start in time.');
                usleep(20_000);
            }

            // ...and has had time to reach the lock and queue behind it.
            usleep(750_000);
        } finally {
            $holder->commit();
            DB::purge($name);
        }

        $results = [];
        foreach ($workers as $worker) {
            $output = stream_get_contents($worker['pipes'][1]);
            $errors = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);

            $lines = array_values(array_filter(explode(PHP_EOL, trim((string) $output))));
            $result = json_decode((string) end($lines), true);

            $this->assertIsArray($result, "A worker reported nothing usable.\nstdout: {$output}\nstderr: {$errors}");
            $results[] = $result;
        }

        return $results;
    }

    /** @param list<array<string, mixed>> $results */
    private function assertOutcomes(array $expected, array $results): void
    {
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        sort($expected);

        $this->assertSame($expected, $outcomes, 'Worker results: '.json_encode($results));
    }

    /** @param list<array<string, mixed>> $results */
    private function refusal(array $results): array
    {
        return collect($results)->firstWhere('outcome', 'refused');
    }
}
