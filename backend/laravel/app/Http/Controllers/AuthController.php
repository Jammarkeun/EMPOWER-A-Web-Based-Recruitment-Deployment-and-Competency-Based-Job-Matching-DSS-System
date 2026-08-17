<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuditService;
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
            return ApiResponse::error('This account has been deactivated.', 403);
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

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

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
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];
    }
}
