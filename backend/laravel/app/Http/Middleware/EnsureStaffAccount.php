<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps applicant and employee accounts out of the internal (staff) API.
 *
 * Portal accounts have their own endpoints under /portal, which resolve every
 * record from the signed-in account. The staff routes take record ids and
 * return the full HR view of a record - interview ratings, internal remarks,
 * draft terminations, who reported a violation - so a portal account must not
 * reach them at all, not even for its own record.
 *
 * Decided from the account type stored on the server, never from anything the
 * browser sends. Each staff action still checks its own permission as well;
 * this is the outer wall, not a replacement for those checks. Coordinators are
 * CDE staff and pass here, but hold none of the HR permissions, so every HR
 * action still refuses them.
 */
class EnsureStaffAccount
{
    private const INTERNAL_TYPES = ['admin', 'hr', 'coordinator'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->user_type, self::INTERNAL_TYPES, true)) {
            return ApiResponse::error(
                'This area is for CDE staff. Applicants and employees can find their own records in the portal.',
                403
            );
        }

        return $next($request);
    }
}
