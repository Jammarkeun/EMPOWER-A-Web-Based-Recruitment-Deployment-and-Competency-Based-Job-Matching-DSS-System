<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Each public endpoint's request limit has its own counter.
 *
 * They used to share one per network address: plain `throttle:N,M` keys every
 * limit on the address alone. Opening documents (120 a minute) and testing the
 * sign-up form (5 an hour) added to the same number sign-in (10 a minute) was
 * checked against, and the sign-up limit made the window an hour long - so a
 * whole office on one address could be refused sign-in for most of an hour,
 * told only to "wait a moment".
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function signUp(array $overrides = [])
    {
        return $this->postJson('/api/v1/register', array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'sex' => 'female',
            'birth_date' => now()->subYears(24)->toDateString(),
            'contact_number' => '09171234567',
            'present_address' => 'Brgy. Pagsawitan, Sta. Cruz, Laguna',
            'email' => 'ana.reyes@example.test',
            'password' => 'sekurangpassword',
            'password_confirmation' => 'sekurangpassword',
        ], $overrides));
    }

    /** A sign-up the server refuses for a mistake in the form. */
    private function mistakenSignUp()
    {
        return $this->signUp(['password_confirmation' => 'something-else']);
    }

    private function signIn(string $email)
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password']);
    }

    public function test_the_defaults(): void
    {
        $this->assertSame([
            'login_per_minute' => 30,
            'registrations_per_hour' => 10,
            'registration_attempts_per_hour' => 30,
            'reset_link_requests_per_hour' => 5,
            'password_resets_per_hour' => 10,
            'document_files_per_minute' => 300,
        ], config('empower.rate_limits'));
    }

    public function test_testing_the_sign_up_form_does_not_block_signing_in(): void
    {
        config([
            'empower.rate_limits.login_per_minute' => 2,
            'empower.rate_limits.registration_attempts_per_hour' => 3,
        ]);
        $hr = $this->hrUser();

        foreach (range(1, 3) as $attempt) {
            $this->mistakenSignUp()->assertStatus(422);
        }
        $this->mistakenSignUp()->assertStatus(429);

        $this->signIn($hr->email)->assertOk();
    }

    public function test_opening_documents_does_not_block_signing_in(): void
    {
        config(['empower.rate_limits.login_per_minute' => 2]);
        $hr = $this->hrUser();

        $link = URL::temporarySignedRoute('documents.file', now()->addMinutes(10), ['path' => 'requirements/1/missing.pdf'], absolute: false);

        foreach (range(1, 5) as $view) {
            // Missing on purpose: the limit is counted before the file is looked for.
            $this->get($link)->assertNotFound();
        }

        $this->signIn($hr->email)->assertOk();
    }

    public function test_correcting_a_mistake_in_the_sign_up_form_is_not_counted_as_a_new_account(): void
    {
        config(['empower.rate_limits.registrations_per_hour' => 1]);

        foreach (range(1, 3) as $attempt) {
            $this->mistakenSignUp()->assertStatus(422);
        }

        $this->signUp()->assertCreated();

        // The one account allowed this hour has been created.
        $this->signUp(['first_name' => 'Bea', 'email' => 'bea@example.test'])->assertStatus(429);
    }

    public function test_every_submission_counts_towards_the_overall_sign_up_cap(): void
    {
        config(['empower.rate_limits.registration_attempts_per_hour' => 3]);

        foreach (range(1, 3) as $attempt) {
            $this->mistakenSignUp()->assertStatus(422);
        }

        $this->signUp()->assertStatus(429);
    }

    public function test_the_refusal_says_how_long_to_wait(): void
    {
        config(['empower.rate_limits.registrations_per_hour' => 1]);
        $this->signUp()->assertCreated();

        $refused = $this->signUp(['first_name' => 'Bea', 'email' => 'bea@example.test'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertMatchesRegularExpression(
            '/^Too many attempts\. Please try again in \d+ (minutes|seconds)\.$/',
            $refused->json('message')
        );
        $this->assertStringContainsString('60 minutes', $refused->json('message'));
    }

    public function test_a_short_wait_is_given_in_seconds(): void
    {
        config(['empower.rate_limits.login_per_minute' => 1]);
        $hr = $this->hrUser();

        $this->signIn($hr->email)->assertOk();

        $this->assertMatchesRegularExpression(
            '/^Too many attempts\. Please try again in \d+ seconds\.$/',
            $this->signIn($hr->email)->assertStatus(429)->json('message')
        );
    }
}
