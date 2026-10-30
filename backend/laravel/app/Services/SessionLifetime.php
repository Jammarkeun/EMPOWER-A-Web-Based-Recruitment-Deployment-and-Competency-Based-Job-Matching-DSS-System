<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * How long a sign-in lasts (security audit V-02).
 *
 * Two limits, both enforced here on the server and both measured from the
 * token itself, so nothing the browser does or claims can stretch them:
 *
 *  - the lifetime (`sanctum.expiration`): minutes from signing in, after which
 *    the person signs in again however busy they are. Sanctum checks this one;
 *  - the idle limit (`sanctum.idle_timeout`): minutes since the account last
 *    made a request of its own. This is what signs out a computer left on in
 *    the office.
 *
 * The values in config are provisional - 12 hours from the earlier audit, one
 * hour from client question 20.4 - until CDE says how long a shared office
 * computer may stay signed in. Zero turns either limit off.
 *
 * A token past either limit is deleted on the spot. It could never be used
 * again anyway, and deleting it means a copied token is not left lying in the
 * database waiting for the scheduled prune.
 */
class SessionLifetime
{
    /** Request attribute naming why a token was refused, read by the 401 response. */
    public const ENDED = 'empower.session_ended';

    /**
     * Sent by requests the screen makes on its own, such as the unread-count
     * poll. They are answered as usual but do not count as activity: a tab left
     * open would otherwise poll its way past the idle limit for ever.
     */
    public const BACKGROUND_HEADER = 'X-Empower-Background';

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function idleMinutes(): ?int
    {
        $minutes = (int) config('sanctum.idle_timeout');

        return $minutes > 0 ? $minutes : null;
    }

    public function lifetimeMinutes(): ?int
    {
        $minutes = (int) config('sanctum.expiration');

        return $minutes > 0 ? $minutes : null;
    }

    public function isBackground(Request $request): bool
    {
        return $request->headers->has(self::BACKGROUND_HEADER);
    }

    /**
     * The check Sanctum runs on every token it is shown.
     *
     * `$isValid` is Sanctum's own verdict, which already covers the lifetime;
     * the idle limit is added here. A token refused for either reason is
     * removed, and the reason is left on the request for the 401 to explain.
     */
    public function allows(PersonalAccessToken $token, bool $isValid): bool
    {
        $reason = match (true) {
            ! $isValid && $this->pastLifetime($token) => 'session_expired',
            $isValid && $this->pastIdleLimit($token) => 'session_idle',
            default => null,
        };

        if ($reason === null) {
            return $isValid;
        }

        $this->end($token, $reason);

        return false;
    }

    /**
     * What the screen needs to warn before the server signs someone out.
     *
     * Only for a real token. Tests that sign in with Sanctum::actingAs carry a
     * stand-in with no creation time, and a cookie session has no token at all;
     * both get the idle limit alone.
     */
    public function describe(mixed $token): array
    {
        $endsAt = null;

        if ($token instanceof PersonalAccessToken && $token->exists && $token->created_at instanceof CarbonInterface) {
            $limits = array_filter([
                $this->lifetimeMinutes() ? $token->created_at->copy()->addMinutes($this->lifetimeMinutes()) : null,
                $token->expires_at,
            ]);

            $endsAt = $limits ? min($limits) : null;
        }

        return [
            'idle_timeout_minutes' => $this->idleMinutes(),
            'expires_at' => $endsAt?->toIso8601String(),
        ];
    }

    /**
     * Whether the token would still be accepted right now. A lapsed token is
     * only deleted when it is next presented, so the table alone overstates
     * who is signed in; the administrator's list asks this instead.
     */
    public function isLive(PersonalAccessToken $token): bool
    {
        return ! $this->pastLifetime($token) && ! $this->pastIdleLimit($token);
    }

    /** The sentence the 401 carries, so the sign-in page can say what happened. */
    public function message(string $reason): string
    {
        return $reason === 'session_idle'
            ? sprintf(
                'You were signed out after %d minutes without activity. Please sign in again.',
                $this->idleMinutes() ?? 0
            )
            : 'Your session has ended. Please sign in again.';
    }

    private function pastLifetime(PersonalAccessToken $token): bool
    {
        $minutes = $this->lifetimeMinutes();

        return ($minutes && $token->created_at?->lte(now()->subMinutes($minutes)))
            || ($token->expires_at && $token->expires_at->isPast());
    }

    private function pastIdleLimit(PersonalAccessToken $token): bool
    {
        $minutes = $this->idleMinutes();
        $lastActive = $token->last_used_at ?? $token->created_at;

        return $minutes !== null && $lastActive !== null && $lastActive->lte(now()->subMinutes($minutes));
    }

    private function end(PersonalAccessToken $token, string $reason): void
    {
        $user = $token->tokenable;

        $token->delete();

        request()->attributes->set(self::ENDED, $reason);

        if ($user instanceof User) {
            $this->audit->record(
                action: 'logout',
                module: 'auth',
                recordType: User::class,
                recordId: $user->id,
                newValues: [
                    'result' => 'session_ended',
                    'reason' => $reason === 'session_idle' ? 'idle_timeout' : 'session_lifetime',
                ],
                actor: $user,
            );
        }
    }
}
