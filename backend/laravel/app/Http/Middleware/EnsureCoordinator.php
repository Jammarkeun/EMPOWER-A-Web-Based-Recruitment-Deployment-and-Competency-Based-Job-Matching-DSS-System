<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\CoordinatorAssignment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits a coordinator, and fixes the client they may see for this request.
 *
 * The client company is resolved here, on the server, from the coordinator's
 * active assignment - never from anything the browser sends. Controllers behind
 * this middleware read it from the request attributes and scope every query to
 * it, so a coordinator asking for another client's id gets their own client's
 * data or a refusal, never the other client's.
 */
class EnsureCoordinator
{
    public const ATTRIBUTE = 'coordinator.assignment';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isCoordinator() || ! $user->can('coordination.access')) {
            return ApiResponse::error('This area is for CDE coordinators.', 403);
        }

        $assignment = CoordinatorAssignment::query()
            ->with('company')
            ->where('coordinator_user_id', $user->id)
            ->active()
            ->first();

        if (! $assignment || ! $assignment->company) {
            return ApiResponse::error(
                'Your account is not assigned to a client company yet. Please contact the administrator.',
                403
            );
        }

        $request->attributes->set(self::ATTRIBUTE, $assignment);

        return $next($request);
    }
}
