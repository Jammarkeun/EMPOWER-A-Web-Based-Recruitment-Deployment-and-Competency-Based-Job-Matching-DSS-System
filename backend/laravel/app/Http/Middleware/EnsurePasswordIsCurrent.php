<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account still on a password the office issued may do one thing: choose
 * its own (audit SEC-07, FN-03; decided 2026-10-19).
 *
 * The office learns every temporary password it hands out - on a reset, on
 * portal access, on a new staff account. Until the holder replaces it, every
 * endpoint except reading their own profile, signing out and changing the
 * password answers 403 with the code "password_change_required", which the
 * screen turns into the change-password page. Enforced here, on the server,
 * so skipping the page does not skip the rule.
 */
class EnsurePasswordIsCurrent
{
    /** What an account that must choose a new password can still reach. */
    private const ALLOWED = [
        'api/v1/auth/me',
        'api/v1/auth/logout',
        'api/v1/auth/change-password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! in_array($request->path(), self::ALLOWED, true)) {
            return ApiResponse::error(
                'Choose your own password before continuing. The temporary password from the office only lets you do that.',
                403,
                code: 'password_change_required',
            );
        }

        return $next($request);
    }
}
