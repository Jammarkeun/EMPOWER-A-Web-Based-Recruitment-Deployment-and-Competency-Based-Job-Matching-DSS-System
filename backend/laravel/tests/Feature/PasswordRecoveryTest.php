<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Getting back into an account (audit FN-03, SEC-07, UX-09; decided
 * 2026-10-19): an office-issued temporary password that must be replaced, and
 * an emailed reset link that stays off until a real mailer is configured.
 */
class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private const NEW_PASSWORD = 'Harvest-Moon-Lantern-42';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function login(string $email, string $password)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function enableEmailReset(): void
    {
        config(['empower.password_reset.email_enabled' => true, 'mail.default' => 'smtp']);
    }

    // ---------------------------------------------------- office reset

    public function test_an_office_reset_issues_a_temporary_password_that_only_allows_choosing_a_new_one(): void
    {
        $target = $this->hrUser();
        $target->createToken('old-session');

        Sanctum::actingAs($this->adminUser());
        $temporary = $this->postJson("/api/v1/users/{$target->id}/reset-password")
            ->assertOk()
            ->json('data.temporary_password');

        $this->assertNotEmpty($temporary);
        $this->assertSame(0, $target->tokens()->count(), 'Every old session ends.');

        $token = $this->login($target->email, $temporary)
            ->assertOk()
            ->assertJsonPath('data.user.must_change_password', true)
            ->json('data.token');

        $auth = ['Authorization' => "Bearer {$token}"];
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/applicants', $auth)
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
        $this->getJson('/api/v1/notifications/unread-count', $auth)->assertForbidden();
        $this->getJson('/api/v1/auth/me', $auth)->assertOk();

        // The temporary password cannot be kept.
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => $temporary,
            'password' => $temporary,
            'password_confirmation' => $temporary,
        ], $auth)->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => $temporary,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $auth)->assertOk();

        $this->assertFalse($target->fresh()->must_change_password);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/applicants', $auth)->assertOk();
    }

    public function test_the_administrator_no_longer_chooses_the_password(): void
    {
        $target = $this->hrUser();
        Sanctum::actingAs($this->adminUser());

        $this->postJson("/api/v1/users/{$target->id}/reset-password", [
            'password' => 'Chosen-By-The-Admin-1',
            'password_confirmation' => 'Chosen-By-The-Admin-1',
        ])->assertOk();

        $this->assertFalse(Hash::check('Chosen-By-The-Admin-1', $target->fresh()->password));
    }

    public function test_portal_access_and_new_staff_accounts_start_on_a_temporary_password(): void
    {
        Sanctum::actingAs($this->adminUser());
        $applicant = $this->applicant($this->hrUser());

        $this->postJson('/api/v1/users/portal-access', ['applicant_id' => $applicant->id, 'email' => 'maria.'.uniqid().'@example.test'])
            ->assertCreated();
        $this->assertTrue(User::where('applicant_id', $applicant->id)->sole()->must_change_password);

        $this->postJson('/api/v1/users', [
            'first_name' => 'New', 'last_name' => 'Officer', 'email' => 'officer.'.uniqid().'@cdemanpower.local',
            'user_type' => 'hr', 'role' => 'hr',
            'password' => 'Typed-By-The-Admin-12', 'password_confirmation' => 'Typed-By-The-Admin-12',
        ])->assertCreated();
        $this->assertTrue(User::where('first_name', 'New')->sole()->must_change_password);
    }

    // ---------------------------------------------------- email reset

    public function test_email_reset_stays_off_without_a_real_mailer_and_says_where_to_go(): void
    {
        Notification::fake();
        $user = $this->hrUser();

        // The suite's mailer is "array"; switching the feature on is not enough.
        config(['empower.password_reset.email_enabled' => true]);

        $this->getJson('/api/v1/auth/password-recovery')
            ->assertOk()
            ->assertJsonPath('data.email_reset_available', false)
            ->assertJsonStructure(['data' => ['office' => ['name', 'address']]]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(503)
            ->assertJsonPath('code', 'email_reset_unavailable');

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_the_answer_is_the_same_whether_or_not_an_account_exists(): void
    {
        Notification::fake();
        $this->enableEmailReset();
        $user = $this->hrUser();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])->assertOk()->json();

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPasswordLink::class);
        Notification::assertSentTimes(ResetPasswordLink::class, 1);
    }

    public function test_a_deactivated_account_is_sent_nothing(): void
    {
        Notification::fake();
        $this->enableEmailReset();
        $user = $this->hrUser();
        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_reset_link_works_once_ends_every_session_and_clears_a_temporary_password(): void
    {
        Notification::fake();
        $this->enableEmailReset();
        $user = $this->hrUser();
        $user->forceFill(['must_change_password' => true])->save();
        $user->createToken('old-session');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $n) use (&$token) {
            $token = $n->token();

            return true;
        });

        // The database holds only a hash of the token.
        $this->assertNotSame($token, DB::table('password_reset_tokens')->value('token'));

        $reset = fn () => $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $reset()->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertSame(0, $user->tokens()->count());

        $reset()->assertStatus(422);
    }

    public function test_an_expired_or_mismatched_link_is_refused_and_the_policy_still_applies(): void
    {
        Notification::fake();
        $this->enableEmailReset();
        $user = $this->hrUser();
        $other = $this->hrUser();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $token = null;
        Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $n) use (&$token) {
            $token = $n->token();

            return true;
        });

        $attempt = fn (string $email, string $password = self::NEW_PASSWORD) => $this->postJson('/api/v1/auth/reset-password', [
            'email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password,
        ]);

        $attempt($user->email, 'short')->assertStatus(422)->assertJsonValidationErrors('password');
        $attempt($other->email)->assertStatus(422);

        $this->travel(61)->minutes();
        $attempt($user->email)->assertStatus(422);
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_repeated_requests_for_one_address_stop_sending(): void
    {
        Notification::fake();
        $this->enableEmailReset();
        config(['auth.passwords.users.throttle' => 0]);
        $user = $this->hrUser();

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        }

        $this->assertLessThanOrEqual(3, Notification::sent($user, ResetPasswordLink::class)->count());
    }
}
