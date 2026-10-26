<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\SessionLifetime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Security audit V-02 (and audit R-08): a sign-in ends on the server.
 *
 * Two limits, both measured from the token: a lifetime from signing in, and an
 * idle limit since the account last made a request of its own. Nothing here
 * depends on the browser's timer - the screen only warns - so each test talks
 * to the API directly and moves the clock.
 *
 * Between requests the guards are forgotten, because within one test the auth
 * manager otherwise remembers the user from the previous request and the token
 * is never looked at again.
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

    private function signIn(?User $user = null): array
    {
        $user ??= $this->hrUser();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        return [$user, $token];
    }

    private function me(string $token, array $headers = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/auth/me', $headers);
    }

    public function test_both_limits_are_on_by_default(): void
    {
        // Provisional values until client question 20.4 is answered.
        $this->assertSame(720, config('sanctum.expiration'));
        $this->assertSame(60, config('sanctum.idle_timeout'));
    }

    public function test_a_sign_in_ends_after_its_lifetime_however_active_it_is(): void
    {
        config(['sanctum.expiration' => 120, 'sanctum.idle_timeout' => 60]);
        [, $token] = $this->signIn();

        // Busy all the way through: never idle for long.
        foreach (range(1, 3) as $step) {
            $this->travel(30)->minutes();
            $this->me($token)->assertOk();
        }

        $this->travel(31)->minutes();

        $this->me($token)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_expired')
            ->assertJsonPath('message', 'Your session has ended. Please sign in again.');

        $this->assertSame(0, PersonalAccessToken::count(), 'An expired token is removed when it is presented.');
    }

    public function test_a_sign_in_left_unused_past_the_idle_limit_is_ended_and_says_why(): void
    {
        [$user, $token] = $this->signIn();

        $this->travel(61)->minutes();

        $this->me($token)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_idle')
            ->assertJsonPath('message', 'You were signed out after 60 minutes without activity. Please sign in again.');

        $this->assertSame(0, PersonalAccessToken::count());

        $entry = AuditLog::where('action_type', 'logout')->where('record_id', $user->id)->latest('id')->first();
        $this->assertNotNull($entry, 'The sign-out is in the audit trail.');
        $this->assertSame('session_ended', $entry->new_values_json['result']);
        $this->assertSame('idle_timeout', $entry->new_values_json['reason']);
        $this->assertSame($user->id, $entry->actor_user_id);

        // The token is gone, so asking again gets the plain refusal.
        $this->me($token)->assertUnauthorized()->assertJsonMissingPath('code');
    }

    public function test_activity_keeps_the_sign_in_alive(): void
    {
        [, $token] = $this->signIn();

        $this->travel(50)->minutes();
        $this->me($token)->assertOk();

        // 100 minutes since signing in, but only 50 since the last request.
        $this->travel(50)->minutes();
        $this->me($token)->assertOk();
    }

    public function test_background_requests_do_not_count_as_activity(): void
    {
        [, $token] = $this->signIn();
        $background = [SessionLifetime::BACKGROUND_HEADER => '1'];

        // The unread-count poll is still answered while the sign-in is live...
        $this->travel(50)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/notifications/unread-count', $background)
            ->assertOk();

        // ...but it does not move the idle clock, so 61 minutes after the last
        // request of the person's own, the sign-in ends.
        $this->travel(11)->minutes();
        $this->me($token, $background)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_idle');
    }

    public function test_zero_turns_each_limit_off(): void
    {
        config(['sanctum.expiration' => 0, 'sanctum.idle_timeout' => 0]);
        [, $token] = $this->signIn();

        $this->travel(3)->days();

        $this->me($token)
            ->assertOk()
            ->assertJsonPath('data.session.idle_timeout_minutes', null)
            ->assertJsonPath('data.session.expires_at', null);
    }

    public function test_the_screen_is_told_when_the_sign_in_ends(): void
    {
        $this->freezeSecond();
        $user = $this->hrUser();

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $endsAt = now()->addMinutes(720)->toIso8601String();
        $login->assertJsonPath('data.user.session.idle_timeout_minutes', 60)
            ->assertJsonPath('data.user.session.expires_at', $endsAt);

        $this->app['auth']->forgetGuards();
        $this->withToken($login->json('data.token'))
            ->getJson('/api/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('data.idle_timeout_minutes', 60)
            ->assertJsonPath('data.expires_at', $endsAt);
    }

    public function test_stay_signed_in_restarts_the_idle_limit_but_not_the_lifetime(): void
    {
        config(['sanctum.expiration' => 90]);
        [, $token] = $this->signIn();

        $this->travel(58)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/session')->assertOk();

        // Idle limit restarted at minute 58, so minute 89 is fine...
        $this->travel(31)->minutes();
        $this->me($token)->assertOk();

        // ...but the 90-minute lifetime still ends it.
        $this->travel(2)->minutes();
        $this->me($token)->assertUnauthorized()->assertJsonPath('code', 'session_expired');
    }

    public function test_an_account_on_a_temporary_password_can_still_be_kept_signed_in(): void
    {
        $user = $this->hrUser();
        $user->forceFill(['must_change_password' => true])->save();
        [, $token] = $this->signIn($user);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/session')->assertOk();
    }

    public function test_an_unknown_token_is_refused_without_a_reason(): void
    {
        $this->withToken('1|not-a-real-token')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Authentication required.')
            ->assertJsonMissingPath('code');
    }

    public function test_tokens_nobody_presents_again_are_pruned_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('sanctum:prune-expired --hours=24')
            ->assertSuccessful();
    }
}
