<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Middleware\EnsureCoordinator;
use App\Models\CoordinatorAssignment;
use Illuminate\Http\Request;

/**
 * The client a coordinator may see, as resolved by EnsureCoordinator.
 *
 * Controllers use this and nothing else to decide which company they are
 * looking at. A client_company_id in the query string or body is never read on
 * these routes, so changing it in the browser changes nothing.
 */
trait CoordinatorScoped
{
    protected function assignment(Request $request): CoordinatorAssignment
    {
        return $request->attributes->get(EnsureCoordinator::ATTRIBUTE);
    }

    protected function companyId(Request $request): int
    {
        return (int) $this->assignment($request)->client_company_id;
    }

    /**
     * Refuses a record that belongs to another client.
     *
     * 403 rather than 404, so a coordinator who follows a stale link is told
     * plainly that the record is outside their client, not that it vanished.
     */
    protected function abortUnlessInScope(Request $request, ?int $companyId, string $what = 'record'): void
    {
        abort_unless(
            (int) $companyId === $this->companyId($request),
            403,
            "This {$what} is not under your assigned client company."
        );
    }
}
