<?php

require __DIR__ . '/MatchingEngine.php';

use Empower\Matching\MatchingEngine;

// Simple CLI runner: php run_matching.php [job_request.json] [applicants.json]
$jobFile = $argv[1] ?? __DIR__ . '/sample_job_request.json';
$appsFile = $argv[2] ?? __DIR__ . '/sample_applicants.json';

if (!file_exists($jobFile) || !file_exists($appsFile)) {
    echo "Usage: php run_matching.php [job_request.json] [applicants.json]\n";
    exit(1);
}

$job = json_decode(file_get_contents($jobFile), true);
$applicants = json_decode(file_get_contents($appsFile), true);

$engine = new MatchingEngine();
try {
    $results = $engine->evaluate($job['criteria'], $applicants, ['hard_pass_first' => true, 'tie_breaker' => 'earliest_application']);
    echo json_encode(['success' => true, 'job_request' => $job['id'] ?? null, 'ranked' => $results], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_PRETTY_PRINT);
}

