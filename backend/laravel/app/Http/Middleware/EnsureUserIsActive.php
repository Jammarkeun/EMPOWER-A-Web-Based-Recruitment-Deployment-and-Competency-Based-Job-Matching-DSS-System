<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks deactivated accounts even when they hold a valid token.
 *
 * Revoking a user's access has to take effect immediately. Without this check a
 * departed staff member's existing Sanctum token would keep working until it was
 * individually deleted, which is exactly the gap an offboarding process misses.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            // Drop the token being used, so the account cannot simply retry.
            $user->currentAccessToken()?->delete();

            return ApiResponse::error('This account has been deactivated.', 403);
        }

        return $next($request);
    }
}
