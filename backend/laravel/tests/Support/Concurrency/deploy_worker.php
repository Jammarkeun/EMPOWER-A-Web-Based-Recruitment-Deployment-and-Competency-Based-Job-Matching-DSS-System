<?php

/*
 * One deployment, in a process of its own, for ConcurrentDeploymentTest.
 *
 *   php deploy_worker.php <applicant id> <job request id> <actor id> <ready file>
 *
 * Writes the ready file just before deploying, so the test knows the worker
 * is about to reach the lock it is holding, then prints one line of JSON
 * saying what happened. Refuses to start against anything but a throwaway
 * test container.
 */

use App\Models\Applicant;
use App\Models\JobRequest;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Tests\Support\Concurrency\ThrowawayDatabase;

$base = dirname(__DIR__, 3);

require $base.'/vendor/autoload.php';

$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $applicantId, $jobRequestId, $actorId, $readyFile] = $argv;

$report = function (array $result): void {
    fwrite(STDOUT, json_encode($result).PHP_EOL);
};

if ($problem = ThrowawayDatabase::problem()) {
    $report(['outcome' => 'not_run', 'message' => $problem]);
    exit(1);
}

$applicant = Applicant::findOrFail($applicantId);
$jobRequest = JobRequest::findOrFail($jobRequestId);
$actor = User::findOrFail($actorId);

file_put_contents($readyFile, (string) getmypid());

try {
    $deployment = app(DeploymentService::class)->deploy($applicant, $jobRequest, [
        'deployment_date' => now()->toDateString(),
    ], $actor);

    $report([
        'outcome' => 'deployed',
        'deployment_code' => $deployment->deployment_code,
        'employee_number' => $deployment->employee->employee_number,
    ]);
} catch (QueryException $e) {
    // Caught first: a database error is a RuntimeException too, and must not
    // pass for a refusal the service decided on.
    $report([
        'outcome' => 'error',
        'class' => get_class($e),
        'sqlstate' => $e->errorInfo[0] ?? null,
        'message' => mb_substr($e->getMessage(), 0, 300),
    ]);
} catch (RuntimeException $e) {
    $report(['outcome' => 'refused', 'code' => $e->getCode(), 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    $report(['outcome' => 'error', 'class' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);
}
