<?php

namespace App\Services;

use App\Models\AuditLog;
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
    ): ?AuditLog {
        try {
            return AuditLog::create([
                'actor_user_id' => Auth::id(),
                'action_type' => $action,
                'module_key' => $module,
                'record_type' => $recordType ? class_basename($recordType) : null,
                'record_id' => $recordId,
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
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Records only the fields that actually changed, so a diff is readable
     * rather than a wall of unchanged columns.
     */
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

    private function redact(?array $values): ?array
    {
        if (is_null($values)) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED, true)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
