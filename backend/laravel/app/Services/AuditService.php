<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CoordinatorAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Writes the audit trail.
 *
 * Records who did what, to which record, and what changed. This is the control
 * that replaces the agency's reliance on remembering who edited a folder, and it
 * is also what RA 10173 accountability expects of a system holding sensitive
 * personal information.
 */
class AuditService
{
    /**
     * Fields that must never be written into the audit trail. Logging a password
     * hash or an API token would turn the audit table into a second, weaker
     * credential store.
     */
    private const REDACTED = [
        'password',
        'password_confirmation',
        'remember_token',
        'token',
        'api_token',
        'secret',
    ];

    public function record(
        string $action,
        string $module,
        ?string $recordType = null,
        int|string|null $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $clientCompanyId = null,
        ?User $actor = null,
    ): ?AuditLog {
        try {
            // Passed explicitly where there is no signed-in user yet - a login.
            $actor ??= Auth::user();

            return AuditLog::create([
                'actor_user_id' => $actor?->id ?? Auth::id(),
                // The capacity they acted in, as it was at the time.
                'actor_role' => $actor?->user_type,
                'action_type' => $action,
                'module_key' => $module,
                'record_type' => $recordType ? class_basename($recordType) : null,
                'record_id' => $recordId,
                'client_company_id' => $clientCompanyId ?? $this->coordinatorCompany($actor),
                'old_values_json' => $this->redact($oldValues),
                'new_values_json' => $this->redact($newValues),
                'ip_address' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 255),
                'request_id' => Request::header('X-Request-Id'),
            ]);
        } catch (\Throwable $e) {
            // An audit write must never take down the business action that
            // triggered it. The failure is escalated to the log instead, where
            // it is visible without costing the user their work.
            Log::error('Audit log write failed', [
                'action' => $action,
                'module' => $module,
                'record_type' => $recordType,
                'record_id' => $recordId,
                // A database error's message carries the bound values - the
                // very personal data being audited - so only its code is kept.
                'exception' => $e instanceof \Illuminate\Database\QueryException
                    ? get_class($e).' SQLSTATE '.($e->errorInfo[0] ?? $e->getCode())
                    : $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Records only the fields that actually changed, so a diff is readable
     * rather than a wall of unchanged columns.
     */
    /**
     * The client a coordinator was acting for, so every entry they cause is
     * tied to that client without each caller having to remember it.
     */
    private function coordinatorCompany(?User $actor): ?int
    {
        if (! $actor?->isCoordinator()) {
            return null;
        }

        return CoordinatorAssignment::query()
            ->where('coordinator_user_id', $actor->id)
            ->active()
            ->value('client_company_id');
    }

    public function recordUpdate(
        string $module,
        string $recordType,
        int|string $recordId,
        array $before,
        array $after,
    ): ?AuditLog {
        $changed = [];
        $previous = [];

        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] != $value) {
                $changed[$key] = $value;
                $previous[$key] = $before[$key] ?? null;
            }
        }

        if ($changed === []) {
            return null;
        }

        return $this->record('update', $module, $recordType, $recordId, $previous, $changed);
    }

    /**
     * Masks credentials at any depth, and by name: any key containing
     * "password", "token" or "secret" (a temporary_password, a reset_token).
     * It used to check a fixed list at the top level only, so a credential
     * nested inside a value - or named slightly differently - was stored.
     */
    private function redact(?array $values): ?array
    {
        if (is_null($values)) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED, true) || AuditTrailPresenter::isSensitive((string) $key)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
