<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The administrator sees every device signed in (CDE, 26 October 2026: "kita
 * niya lang yung lahat ng device na pumapasok sa system ... para iwas po sa
 * hack"), and the history of attempts - without any token or password ever
 * leaving the server.
 */
class SessionMonitoringTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private const WINDOWS_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private User $admin;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $this->admin = $this->adminUser();
        $this->admin->forceFill(['password' => Hash::make('Admin-Pass-2026!')])->save();
        $this->hr = $this->hrUser();
        $this->hr->forceFill(['password' => Hash::make('Hr-Officer-Pass-2026!')])->save();
    }

    /** Signs in through the real endpoint from a given browser; returns the token. */
    private function signIn(User $user, string $password, string $agent): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('User-Agent', $agent)
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])
            ->assertOk()
            ->json('data.token');
    }

    private function as(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_the_administrator_sees_who_is_signed_in_and_on_what_device(): void
    {
        $hrToken = $this->signIn($this->hr, 'Hr-Officer-Pass-2026!', self::IPHONE_SAFARI);
        $adminToken = $this->signIn($this->admin, 'Admin-Pass-2026!', self::WINDOWS_CHROME);

        $response = $this->as($adminToken)->getJson('/api/v1/security/sessions')->assertOk();
        $sessions = collect($response->json('data'));

        $hr = $sessions->firstWhere('user.id', $this->hr->id);
        $this->assertSame('Safari on iPhone', $hr['device']);
        $this->assertTrue($hr['is_mobile']);
        $this->assertSame('127.0.0.1', $hr['ip_address']);
        $this->assertFalse($hr['is_current']);
        $this->assertNotNull($hr['ends_at']);
        $this->assertTrue($sessions->firstWhere('user.id', $this->admin->id)['is_current']);

        // Nothing that could be replayed is in the answer.
        foreach ([$hrToken, $adminToken] as $plain) {
            $this->assertStringNotContainsString(explode('|', $plain)[1], $response->getContent());
        }
        $this->assertStringNotContainsString(PersonalAccessToken::first()->token, $response->getContent());
        $this->assertArrayNotHasKey('token', $hr);
    }

    public function test_only_the_administrator_can_see_or_end_sessions(): void
    {
        $hrToken = $this->signIn($this->hr, 'Hr-Officer-Pass-2026!', self::WINDOWS_CHROME);

        $this->as($hrToken)->getJson('/api/v1/security/sessions')->assertForbidden();
        $this->as($hrToken)->getJson('/api/v1/security/sign-ins')->assertForbidden();
        $this->as($hrToken)->deleteJson('/api/v1/security/sessions/'.PersonalAccessToken::first()->id)->assertForbidden();

        $coordinator = User::factory()->create(['email' => 'coord.'.uniqid().'@cde.test', 'user_type' => 'coordinator']);
        $coordinator->assignRole('coordinator');
        $this->app['auth']->forgetGuards();
        \Laravel\Sanctum\Sanctum::actingAs($coordinator);
        $this->getJson('/api/v1/security/sessions')->assertForbidden();
    }

    public function test_failed_attempts_are_in_the_history_without_the_password(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('User-Agent', self::WINDOWS_CHROME)
            ->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'Guess-123456'])
            ->assertStatus(422);
        $this->withHeader('User-Agent', self::WINDOWS_CHROME)
            ->postJson('/api/v1/auth/login', ['email' => $this->hr->email, 'password' => 'Wrong-Pass-999'])
            ->assertStatus(422);

        $adminToken = $this->signIn($this->admin, 'Admin-Pass-2026!', self::WINDOWS_CHROME);

        $response = $this->as($adminToken)->getJson('/api/v1/security/sign-ins?result=failed')->assertOk();
        $rows = collect($response->json('data'));

        $this->assertCount(2, $rows);
        $this->assertSame('nobody@example.test', $rows->firstWhere('attempted_email', 'nobody@example.test')['attempted_email']);
        $this->assertTrue($rows->every(fn ($r) => $r['event'] === 'failed' && $r['device'] === 'Chrome on Windows'));
        $this->assertStringNotContainsString('Guess-123456', $response->getContent());
        $this->assertStringNotContainsString('Wrong-Pass-999', $response->getContent());

        $signedIn = collect($this->as($adminToken)->getJson('/api/v1/security/sign-ins?result=success')->json('data'));
        $this->assertSame($this->admin->id, $signedIn->first()['account']['id']);
    }

    public function test_the_administrator_signs_a_device_out_and_it_is_recorded(): void
    {
        $hrToken = $this->signIn($this->hr, 'Hr-Officer-Pass-2026!', self::IPHONE_SAFARI);
        $adminToken = $this->signIn($this->admin, 'Admin-Pass-2026!', self::WINDOWS_CHROME);
        $this->as($hrToken)->getJson('/api/v1/auth/me')->assertOk();

        $hrSession = PersonalAccessToken::where('tokenable_id', $this->hr->id)->firstOrFail();
        $ownSession = PersonalAccessToken::where('tokenable_id', $this->admin->id)->firstOrFail();

        $this->as($adminToken)->deleteJson("/api/v1/security/sessions/{$ownSession->id}")->assertStatus(422);
        $this->as($adminToken)->deleteJson("/api/v1/security/sessions/{$hrSession->id}")->assertOk();

        // The device is out; the account is not otherwise touched.
        $this->as($hrToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertTrue($this->hr->fresh()->is_active);

        $ended = collect($this->as($adminToken)->getJson('/api/v1/security/sign-ins?result=ended')->json('data'))->first();
        $this->assertSame($this->hr->id, $ended['account']['id']);
        $this->assertSame('signed_out_by_administrator', $ended['reason']);
        $this->assertSame($this->admin->full_name, $ended['by']);
        // The device that was signed out, not the administrator's own browser.
        $this->assertSame('Safari on iPhone', $ended['device']);
    }

    public function test_a_session_past_its_idle_limit_is_not_listed_as_signed_in(): void
    {
        $this->signIn($this->hr, 'Hr-Officer-Pass-2026!', self::WINDOWS_CHROME);
        $this->travel((int) config('sanctum.idle_timeout') + 5)->minutes();

        $adminToken = $this->signIn($this->admin, 'Admin-Pass-2026!', self::WINDOWS_CHROME);
        $ids = collect($this->as($adminToken)->getJson('/api/v1/security/sessions')->json('data'))->pluck('user.id');

        $this->assertNotContains($this->hr->id, $ids);
        $this->assertContains($this->admin->id, $ids);
    }
}
