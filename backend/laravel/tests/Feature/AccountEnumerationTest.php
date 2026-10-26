<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Security audit V-03: the public endpoints do not say who has an account.
 *
 * Response time cannot be measured reliably in a test, so the login test
 * checks the cause instead: a password check is made whether or not the
 * address belongs to anyone, which is what made the two cases take different
 * times before.
 */
class AccountEnumerationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_an_unknown_email_still_costs_a_full_password_check(): void
    {
        Hash::partialMock()
            ->shouldReceive('check')
            ->once()
            ->withArgs(fn ($value, $hash) => $value === 'a-guess' && str_starts_with((string) $hash, '$2y$12$'))
            ->andReturnFalse();

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@cdemanpower.local', 'password' => 'a-guess'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    public function test_a_known_email_with_a_wrong_password_gets_the_same_answer(): void
    {
        $hr = $this->hrUser();

        $known = $this->postJson('/api/v1/auth/login', ['email' => $hr->email, 'password' => 'a-guess']);
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@cdemanpower.local', 'password' => 'a-guess']);

        $known->assertStatus(422);
        $unknown->assertStatus(422);
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_the_stand_in_hash_never_lets_anyone_in(): void
    {
        // An account with no password stored is checked against the stand-in,
        // which matches nothing anybody can type. Written past the model so the
        // `hashed` cast cannot turn the empty value into a hash of "".
        $hr = $this->hrUser();
        DB::table('users')->where('id', $hr->id)->update(['password' => '']);

        $this->postJson('/api/v1/auth/login', ['email' => $hr->email, 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonMissingPath('data.token');
    }

    public function test_there_is_no_public_lookup_of_whether_an_email_is_registered(): void
    {
        $hr = $this->hrUser();

        $this->postJson('/api/v1/register/check-email', ['email' => $hr->email])->assertNotFound();
        $this->postJson('/api/v1/register/check-email', ['email' => 'nobody@example.test'])->assertNotFound();
    }
}
