<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Notifications\ResetPasswordLink;
use App\Rules\PasswordPolicy;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Getting back into an account without an administrator (audit FN-03, UX-09).
 *
 * Two routes back in, decided 2026-10-19:
 *
 * 1. At the office. An officer who has confirmed who the person is resets the
 *    password; the system generates a temporary one that only lets the holder
 *    choose their own (UserController::resetPassword, EnsurePasswordIsCurrent).
 *    This works today and needs no mail.
 *
 * 2. By email, below - built, and switched off until it can actually deliver.
 *    It needs PASSWORD_RESET_EMAIL=true and a real mailer. With the "log" or
 *    "array" mailer it stays off on purpose: the "email" would be written into
 *    the application log, reset link and all.
 *
 * Every answer is the same whether or not an account exists, so neither route
 * can be used to find out who has an account with the agency.
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public static function emailResetAvailable(): bool
    {
        return (bool) config('empower.password_reset.email_enabled', false)
            && ! in_array(config('mail.default'), ['log', 'array', null, ''], true);
    }

    /**
     * How a locked-out person can get back in. Public, and about the system
     * rather than any account.
     */
    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'email_reset_available' => self::emailResetAvailable(),
            'link_expires_minutes' => (int) config('auth.passwords.users.expire', 60),
            'office' => [
                'name' => config('empower.organisation.name'),
                'address' => config('empower.organisation.address'),
                'contact' => config('empower.organisation.contact'),
            ],
        ]);
    }

    public function sendLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);

        if (! self::emailResetAvailable()) {
            return $this->unavailable();
        }

        $email = strtolower(trim($data['email']));
        $key = 'password-reset:'.sha1($email);

        // Three requests an hour per address, on top of the route's limit per
        // network address. Past it the answer does not change; nothing is sent.
        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 3600);
            $this->sendIfAccountExists($email);
        }

        $minutes = (int) config('auth.passwords.users.expire', 60);

        // Future tense on purpose: the link is sent after this response, so
        // nothing here can claim it was delivered.
        return ApiResponse::success(null, "If an account uses that address, a reset link will be sent to it. The link works once and expires in {$minutes} minutes. If nothing arrives, contact the office.");
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy()],
        ]);

        if (! self::emailResetAvailable()) {
            return $this->unavailable();
        }

        $invalid = fn () => ApiResponse::error('This reset link is invalid or has expired. Ask for a new one, or contact the office.', 422);

        // A deactivated account is not reopened by its own reset link.
        $user = User::where('email', $data['email'])->first();
        if ($user && ! $user->isActive()) {
            return $invalid();
        }

        $status = Password::broker()->reset(
            [
                'email' => $data['email'],
                'token' => $data['token'],
                'password' => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'must_change_password' => false,
                    'remember_token' => Str::random(60),
                ])->save();

                // Every session ends: whoever had the account should not keep it.
                $user->tokens()->delete();

                $this->audit->record('update', 'auth', User::class, $user->id, null, [
                    'password' => '[redacted]',
                    'method' => 'email_reset_link',
                    'sessions_revoked' => true,
                ], actor: $user);

                $user->notify(new PasswordChanged());
            }
        );

        // The broker deletes the token once it is used, so a link works once.
        if ($status !== Password::PASSWORD_RESET) {
            return $invalid();
        }

        return ApiResponse::success(null, 'Your password has been changed. Sign in with your new password.');
    }

    /**
     * Creates the token and sends the link once this response has gone.
     *
     * After the response, so an address with an account takes no longer to
     * answer than one without; and as a terminating callback in this process
     * rather than a job, so the plain token is never serialised or written to
     * the queue table. Terminating callbacks live as long as the application,
     * so a long-running process would run this one again on every later
     * request - hence the once-only guard. A failure to send is logged
     * without the token.
     */
    private function sendIfAccountExists(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->isActive()) {
            return;
        }

        $tokens = Password::broker()->getRepository();
        if ($tokens->recentlyCreatedToken($user)) {
            return;
        }

        $token = $tokens->create($user);
        $sent = false;

        app()->terminating(function () use ($user, $token, &$sent) {
            if ($sent) {
                return;
            }
            $sent = true;

            try {
                $user->notify(new ResetPasswordLink($token));

                app(AuditService::class)->record('update', 'auth', User::class, $user->id, null, [
                    'reset_link' => 'sent',
                ], actor: $user);
            } catch (Throwable $e) {
                Log::warning('A password reset email could not be sent.', [
                    'user_id' => $user->id,
                    'exception' => get_class($e),
                ]);
            }
        });
    }

    private function unavailable(): JsonResponse
    {
        return ApiResponse::error(
            'Password reset by email is not available. Contact the office: an officer will confirm who you are and give you a temporary password to replace when you sign in.',
            503,
            code: 'email_reset_unavailable',
        );
    }
}
