<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tells an open screen whether anything has changed since it last asked.
 *
 * The client polls this every few seconds and refetches what it is showing
 * only when the answer moves, which is what makes the system feel live without
 * a WebSocket server - something neither XAMPP nor the deployment host would
 * keep running, and which a change measured in seconds does not need.
 *
 * The marker is built from the newest audit entry, because every consequential
 * write in the system already records one, and the newest notification for
 * this user, which is written without one. Indexed lookups, so it stays cheap
 * even over the round trip to Supabase.
 *
 * Two markers (usability audit, 2 November 2026):
 *
 * - `version` leaves out audit entries that change no record a screen shows:
 *   signing in and out, a failed sign-in, a revoked session, an export. These
 *   used to move the marker too, so somebody signing in anywhere made every
 *   open screen of every user refetch everything - and reset forms that
 *   re-seeded from the refreshed record.
 * - `activity` moves on every entry, those included. Only the screens that
 *   show sign-ins (the sign-in activity, the audit trail, the user list's
 *   "last signed in") listen to it.
 *
 * Hashed so the response is opaque: a raw audit id would tell any signed-in
 * applicant how busy the office is.
 */
class LiveController extends Controller
{
    /** Audit entries that record access, not a change to any record shown. */
    public const NOT_A_CHANGE = ['login', 'logout', 'revoke_session', 'export'];

    public function version(Request $request): JsonResponse
    {
        // The newest entry that is a change. Read backwards along the primary
        // key, so it stops at the first match rather than scanning the table.
        $change = AuditLog::query()
            ->whereNotIn('action_type', self::NOT_A_CHANGE)
            ->orderByDesc('id')
            ->value('id');
        $latest = AuditLog::query()->max('id');
        $notification = $request->user()->notifications()->max('created_at');

        return ApiResponse::success([
            'version' => $this->opaque($change, $notification),
            'activity' => $this->opaque($latest, $notification),
        ]);
    }

    private function opaque(mixed $audit, mixed $notification): string
    {
        return sha1($audit.'|'.$notification.'|'.config('app.key'));
    }
}
