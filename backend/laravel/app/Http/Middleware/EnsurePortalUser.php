<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the portal routes to accounts that belong to an applicant or
 * employee.
 *
 * Staff are kept out deliberately. Portal endpoints resolve their record from
 * the signed-in user's own link, so a staff account reaching them would either
 * error or, worse, resolve to whichever record happened to be linked. The staff
 * screens already expose everything HR needs, with proper authorisation.
 */
class EnsurePortalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->user_type, ['applicant', 'employee'], true)) {
            return ApiResponse::error(
                'The portal is for applicants and employees. Staff should use the main system.',
                403
            );
        }

        if (! $user->applicant_id) {
            return ApiResponse::error(
                'This account is not linked to an applicant record. Please contact the HR office.',
                403
            );
        }

        return $next($request);
    }
}
