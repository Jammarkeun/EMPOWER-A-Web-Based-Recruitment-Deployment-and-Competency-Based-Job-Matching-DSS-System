<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SessionLifetime;
use App\Support\DeviceLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Every device signed in to EMPOWER, for the administrator.
 *
 * CDE, 26 October 2026: the administrator sees "lahat ng device na pumapasok
 * sa system" - every device that signs in - to guard against intrusion. Two
 * views: who is signed in right now, and the history of attempts (successful
 * and failed) from the audit trail, which already recorded each one.
 *
 * Nothing secret is shown. A session is identified by its row, never by its
 * token, which exists only as a hash; passwords never reach the audit trail.
 * Administrator only, through permissions HR does not hold.
 */
class SessionMonitorController extends Controller
{
    public function __construct(
        private readonly SessionLifetime $sessions,
        private readonly AuditService $audit,
    ) {
    }

    /** Signed in now: one live session per account, with where it came from. */
    public function sessions(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('sessions.view'), 403, 'Only an administrator can see who is signed in.');

        $currentId = $request->user()->currentAccessToken()?->getKey();

        $live = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (PersonalAccessToken $token) => $this->sessions->isLive($token));

        $users = User::query()->with('roles')->whereIn('id', $live->pluck('tokenable_id'))->get()->keyBy('id');

        return ApiResponse::success($live->map(function (PersonalAccessToken $token) use ($users, $currentId) {
            $user = $users->get($token->tokenable_id);

            return [
                'id' => $token->id,
                'user' => [
                    'id' => $user?->id,
                    'full_name' => $user?->full_name,
                    'email' => $user?->email,
                    'user_type' => $user?->user_type,
                    'roles' => $user?->roles->pluck('name')->values(),
                ],
                'device' => DeviceLabel::from($token->user_agent),
                'is_mobile' => DeviceLabel::isMobile($token->user_agent),
                'user_agent' => $token->user_agent,
                'ip_address' => $token->ip_address,
                'signed_in_at' => $token->created_at?->toIso8601String(),
                'last_active_at' => ($token->last_used_at ?? $token->created_at)?->toIso8601String(),
                'ends_at' => $this->sessions->describe($token)['expires_at'],
                'is_current' => (int) $token->id === (int) $currentId,
            ];
        })->values(), 'Signed-in devices retrieved');
    }

    /** Sign-in history: every attempt, newest first. */
    public function signIns(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('sessions.view'), 403, 'Only an administrator can see the sign-in history.');

        $filters = $request->validate([
            'result' => ['nullable', Rule::in(['success', 'failed', 'logout', 'ended'])],
            'search' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $events = AuditLog::query()
            ->with('actor')
            ->where('module_key', 'auth')
            ->whereIn('action_type', ['login', 'logout', 'revoke_session'])
            // JSON-path conditions, not LIKE on the column: it is jsonb on
            // PostgreSQL, where LIKE on the raw column is an error.
            ->when($filters['result'] ?? null, fn ($q, $result) => match ($result) {
                // SessionLifetime records an idle or lifetime sign-out as a
                // logout whose result is "session_ended".
                'logout' => $q->where('action_type', 'logout')->where(fn ($l) => $l
                    ->whereNull('new_values_json->result')
                    ->orWhere('new_values_json->result', '!=', 'session_ended')),
                'ended' => $q->where(fn ($e) => $e->where('action_type', 'revoke_session')
                    ->orWhere(fn ($l) => $l->where('action_type', 'logout')->where('new_values_json->result', 'session_ended'))),
                default => $q->where('action_type', 'login')->where('new_values_json->result', $result),
            })
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($s) => $s
                ->whereContains('ip_address', $v)
                // A failed attempt on an unknown address names only the email typed.
                ->orWhereContains('new_values_json->email', $v)
                ->orWhereHas('actor', fn ($a) => $a->whereContains('email', $v)
                    ->orWhereContains('first_name', $v)
                    ->orWhereContains('last_name', $v))))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);

        $accounts = User::query()
            ->whereIn('id', $events->getCollection()->where('record_type', 'User')->pluck('record_id')->filter())
            ->get()
            ->keyBy('id');

        return ApiResponse::paginated($events->through(function (AuditLog $event) use ($accounts) {
            $values = (array) ($event->new_values_json ?? []);
            $revoked = $event->action_type === 'revoke_session';
            // A revocation is done by an administrator to somebody else's
            // account: the account is the record, the administrator the actor.
            $account = $revoked
                ? $accounts->get($event->record_id)
                : ($event->actor ?? ($event->record_type === 'User' ? $accounts->get($event->record_id) : null));

            return [
                'id' => $event->id,
                'at' => $event->created_at?->toIso8601String(),
                'event' => match (true) {
                    $event->action_type === 'login' => ($values['result'] ?? null) === 'success' ? 'signed_in' : 'failed',
                    $revoked, ($values['result'] ?? null) === 'session_ended' => 'ended',
                    default => 'signed_out',
                },
                'reason' => $revoked ? 'signed_out_by_administrator' : ($values['reason'] ?? null),
                'by' => $revoked ? $event->actor?->full_name : null,
                'account' => $account ? [
                    'id' => $account->id,
                    'full_name' => $account->full_name,
                    'email' => $account->email,
                ] : null,
                // What was typed, when it matched no account.
                'attempted_email' => $account ? null : ($values['email'] ?? null),
                'role' => $revoked ? $account?->user_type : $event->actor_role,
                // For a revocation, the device that was signed out - the
                // request itself came from the administrator's browser.
                'device' => $revoked
                    ? ($event->old_values_json['device'] ?? 'Unknown device')
                    : DeviceLabel::from($event->user_agent),
                'user_agent' => $revoked ? null : $event->user_agent,
                'ip_address' => $revoked ? ($event->old_values_json['ip_address'] ?? null) : $event->ip_address,
            ];
        }), 'Sign-in history retrieved');
    }

    /** Signs one device out. The person can sign in again; nothing else changes. */
    public function revoke(Request $request, PersonalAccessToken $token): JsonResponse
    {
        abort_unless($request->user()->can('sessions.revoke'), 403, 'Only an administrator can sign a device out.');

        if ((int) $token->id === (int) $request->user()->currentAccessToken()?->getKey()) {
            return ApiResponse::error('This is your own session. Use "Log out" instead.', 422);
        }

        $user = $token->tokenable;

        $this->audit->record('revoke_session', 'auth', User::class, $user?->id, [
            'device' => DeviceLabel::from($token->user_agent),
            'ip_address' => $token->ip_address,
            'signed_in_at' => $token->created_at?->toIso8601String(),
        ], ['result' => 'signed_out_by_administrator']);

        $token->delete();

        return ApiResponse::success(null, ($user?->full_name ?? 'The account').' has been signed out of that device.');
    }
}
