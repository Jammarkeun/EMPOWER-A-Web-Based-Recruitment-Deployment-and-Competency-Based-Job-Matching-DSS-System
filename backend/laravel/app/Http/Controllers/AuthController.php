<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Rules\PasswordPolicy;
use App\Services\AuditService;
use App\Services\ProfilePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Throttle by email and IP together. Keying on IP alone would let an
        // attacker spread attempts across addresses; keying on email alone would
        // let them lock a known user out of their own account.
        $throttleKey = strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return ApiResponse::error(
                "Too many login attempts. Please try again in {$seconds} seconds.",
                429
            );
        }

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 300);

            $this->audit->record(
                action: 'login',
                module: 'auth',
                newValues: ['email' => $credentials['email'], 'result' => 'failed'],
            );

            // One message for both cases, so the response cannot be used to
            // discover which email addresses are registered.
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->isActive()) {
            $this->audit->record(
                action: 'login',
                module: 'auth',
                recordType: User::class,
                recordId: $user->id,
                newValues: ['result' => 'failed', 'reason' => 'inactive_account'],
            );

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        // Any previously issued token is discarded, so a login elsewhere ends
        // older sessions rather than accumulating live tokens indefinitely.
        $user->tokens()->delete();
        $token = $user->createToken('empower-api')->plainTextToken;

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record(
            action: 'login',
            module: 'auth',
            recordType: User::class,
            recordId: $user->id,
            newValues: ['result' => 'success'],
            // Nobody is signed in yet at this point, so the actor is named
            // explicitly - which also records their role and, for a
            // coordinator, the client they cover.
            actor: $user,
        );

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->profile($user),
        ], 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        $this->audit->record(
            action: 'logout',
            module: 'auth',
            recordType: User::class,
            recordId: $user->id,
        );

        return ApiResponse::success(null, 'Logged out');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success($this->profile($request->user()));
    }

    /**
     * Let a user maintain their own contact details.
     *
     * Email and role are deliberately excluded. Changing an email address is how
     * an account gets quietly taken over, and a user granting themselves a role
     * would defeat the whole permission model — both stay with the
     * administrator.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'mobile_number' => ['nullable', 'string', 'max:30'],
        ]);

        $user = $request->user();
        $before = $user->only(array_keys($data));
        $user->update($data);

        $this->audit->recordUpdate('auth', User::class, $user->id, $before, $data);

        return ApiResponse::success($this->profile($user->fresh()), 'Your details have been updated');
    }

    /**
     * Which kinds of notification this account wants to receive.
     *
     * Available to every signed-in user, staff and portal alike: the categories
     * are the same, and so is the reason for offering them.
     */
    public function notificationPreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'categories' => collect(User::NOTIFICATION_CATEGORIES)
                ->map(fn (string $label, string $key) => [
                    'key' => $key,
                    'label' => $label,
                    'enabled' => $user->wantsNotification($key),
                ])
                ->values(),
        ]);
    }

    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['boolean'],
        ]);

        // Only recognised categories are stored, so a stale or hand-made
        // request cannot write keys that nothing will ever read.
        $preferences = collect($data['preferences'])
            ->only(array_keys(User::NOTIFICATION_CATEGORIES))
            ->map(fn ($enabled) => (bool) $enabled)
            ->all();

        $user->forceFill(['notification_preferences' => $preferences])->save();

        $this->audit->record(
            action: 'update',
            module: 'settings',
            recordType: User::class,
            recordId: $user->id,
            newValues: ['notification_preferences' => $preferences],
        );

        return ApiResponse::success(
            ['preferences' => $preferences],
            'Notification preferences saved'
        );
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy()],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // Re-using the current password would leave a temporary one the office
        // knows in use, and the forced change would mean nothing.
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Choose a password different from your current one.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            // The account holder chose this one: the office no longer knows it.
            'must_change_password' => false,
        ])->save();

        // Every other session is invalidated, which is the point of a password
        // change if the old one may have been compromised.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        $this->audit->record(
            action: 'update',
            module: 'auth',
            recordType: User::class,
            recordId: $user->id,
            newValues: ['password' => '[redacted]'],
        );

        $user->notify(new PasswordChanged());

        return ApiResponse::success(null, 'Password updated');
    }

    /**
     * The permission list travels with the profile so the client can hide
     * actions the user cannot perform. The server still enforces every one of
     * them; this only prevents offering buttons that would fail.
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'mobile_number' => $user->mobile_number,
            'user_type' => $user->user_type,
            'applicant_id' => $user->applicant_id,
            'employee_id' => $user->employee_id,
            // A portal account's own picture, from its own applicant record.
            // Staff accounts are not applicants and have none.
            'photo_url' => $user->applicant_id
                ? app(ProfilePhotoService::class)->url($user->applicant?->profile_photo_path)
                : null,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            // Still on a password the office issued: the screen shows only the
            // change-password page, and the API refuses everything else.
            'must_change_password' => (bool) $user->must_change_password,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            // The client a coordinator covers, so the screen can name it. The
            // server never takes this back from the browser: every coordinator
            // request is scoped from the assignment again.
            'coordinator' => $user->isCoordinator() ? $this->coordinatorProfile($user) : null,
        ];
    }

    private function coordinatorProfile(User $user): array
    {
        $assignment = $user->activeCoordinatorAssignment()->with('company')->first();

        return [
            'client_company_id' => $assignment?->client_company_id,
            'company_name' => $assignment?->company?->company_name,
            'assigned_at' => $assignment?->assigned_at?->toIso8601String(),
        ];
    }
}
