<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Audit R-08: token lifetime is enforced by the server once a policy is set.
 *
 * The lifetime itself (the audit proposes 12 hours) still awaits approval, so
 * the default stays "no expiry". These tests show that setting
 * SANCTUM_EXPIRATION is all the policy needs - no client-side timer involved.
 */
class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_by_default_the_existing_no_expiry_behaviour_is_unchanged(): void
    {
        $this->assertNull(config('sanctum.expiration'));
    }

    public function test_a_token_older_than_the_configured_lifetime_is_refused(): void
    {
        config(['sanctum.expiration' => 60]);
        $hr = $this->hrUser();
        $token = $hr->createToken('empower-api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(61)->minutes();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
