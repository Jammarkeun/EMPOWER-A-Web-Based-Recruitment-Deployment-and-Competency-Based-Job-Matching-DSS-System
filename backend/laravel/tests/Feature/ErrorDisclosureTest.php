<?php

namespace Tests\Feature;

use App\Services\ReferenceCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use League\Flysystem\UnableToWriteFile;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Audit R-03: a database or library failure never shows its internals.
 *
 * QueryException is a RuntimeException, and the error renderer used to return
 * any RuntimeException's message - so a constraint violation sent the browser
 * the SQL statement with every bound value, whatever APP_DEBUG said. These
 * tests provoke a real constraint violation through a real endpoint.
 */
class ErrorDisclosureTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    /**
     * Makes the next manpower request collide with an existing request code,
     * so the insert fails on the unique index exactly as a race would.
     */
    private function provokeDuplicateRequestCode(): array
    {
        $hr = $this->hrUser();
        $department = $this->department($this->clientCompany($hr), $hr);
        $existing = $this->jobRequest($department, $hr, ['request_code' => 'JR-SECRET-CODE-0001']);
        $position = $this->jobPosition($department->company, $hr);

        $this->mock(ReferenceCodeService::class, function ($mock) use ($existing) {
            $mock->shouldReceive('jobRequest')->andReturn($existing->request_code);
        });

        Sanctum::actingAs($hr);

        return [$department, $position, $existing];
    }

    private function postRequest($department, $position)
    {
        return $this->postJson('/api/v1/job-requests', [
            'client_company_id' => $department->client_company_id,
            'client_department_id' => $department->id,
            'job_position_id' => $position->id,
            'workers_needed' => 3,
            'date_requested' => now()->toDateString(),
            'remarks' => 'Applicant Maria Santos, 09171234567',
        ]);
    }

    private function assertNothingInternal(string $body, array $secrets = []): void
    {
        foreach (['SQLSTATE', 'insert into', 'job_requests', 'Integrity constraint', 'UNIQUE constraint', 'Connection:', ...$secrets] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body, "Response leaked: {$needle}");
        }
    }

    public function test_a_database_error_returns_a_generic_message(): void
    {
        config(['app.debug' => false]);
        [$department, $position, $existing] = $this->provokeDuplicateRequestCode();

        $response = $this->postRequest($department, $position)
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('Something went wrong on our side', $response->json('message'));
        $this->assertNothingInternal($response->getContent(), [$existing->request_code, 'Maria Santos', '09171234567']);
    }

    public function test_a_database_error_stays_hidden_in_debug_mode_too(): void
    {
        // Demos run with debug on, so this case matters as much as production.
        config(['app.debug' => true]);
        [$department, $position, $existing] = $this->provokeDuplicateRequestCode();

        $response = $this->postRequest($department, $position)->assertStatus(500);

        $this->assertNothingInternal($response->getContent(), [$existing->request_code, 'Maria Santos']);
        $this->assertArrayNotHasKey('trace', $response->json());
        $this->assertArrayNotHasKey('exception', $response->json());
    }

    public function test_the_log_keeps_the_statement_but_not_the_personal_values(): void
    {
        Log::spy();
        [$department, $position, $existing] = $this->provokeDuplicateRequestCode();

        $this->postRequest($department, $position)->assertStatus(500);

        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context = []) use ($existing) {
                $encoded = json_encode($context);

                return $message === 'Database query failed'
                    && str_contains((string) ($context['sql'] ?? ''), 'insert into')
                    && str_contains((string) ($context['sql'] ?? ''), '?')
                    && ! empty($context['sqlstate'])
                    && ! str_contains($encoded, $existing->request_code)
                    && ! str_contains($encoded, 'Maria Santos')
                    && ! str_contains($encoded, '09171234567');
            })
            ->once();
    }

    public function test_a_library_failure_does_not_reveal_storage_details(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/v1/__test/storage-failure', function () {
            throw UnableToWriteFile::atLocation(
                'requirements/42/0b7c-medical-result.pdf',
                'https://secret-project.storage.supabase.co/storage/v1/s3 refused the request'
            );
        });

        $response = $this->getJson('/api/v1/__test/storage-failure')->assertStatus(500);

        $this->assertNothingInternal($response->getContent(), ['requirements/42', 'medical-result', 'supabase', 'storage/v1']);
    }

    public function test_an_unexpected_error_outside_debug_mode_uses_the_envelope(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('/api/v1/__test/unexpected', function () {
            throw new \LogicException('Internal invariant broken at C:\\secret\\path\\File.php');
        });

        $response = $this->getJson('/api/v1/__test/unexpected')
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_business_rule_messages_written_by_empower_still_reach_the_user(): void
    {
        $hr = $this->hrUser();
        $department = $this->department($this->clientCompany($hr), $hr);
        $request = $this->jobRequest($department, $hr);
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);

        Sanctum::actingAs($hr);

        // A refusal from DeploymentService: specific, safe, and still shown.
        $this->postJson('/api/v1/deployments', [
            'applicant_id' => $applicant->id,
            'job_request_id' => $request->id,
            'deployment_date' => now()->toDateString(),
        ])->assertStatus(400)
            ->assertJsonPath('message', 'The applicant must be approved or ready for deployment. Current status is "initial screening".');
    }
}
