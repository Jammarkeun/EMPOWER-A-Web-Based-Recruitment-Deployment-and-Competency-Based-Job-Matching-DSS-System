<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Archive;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ArchiveController extends Controller
{
    /**
     * Archived records stay searchable. Losing the ability to answer "did this
     * person work for us, and how did it end" is exactly the problem the paper
     * system had once a folder was refiled.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewArchives');

        $filters = $request->validate([
            'entity_type' => ['nullable', Rule::in(['applicant', 'employee', 'deployment', 'violation', 'resignation', 'termination'])],
            'search' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $archives = Archive::query()
            ->with('archivedBy')
            ->when($filters['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('archived_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('archived_at', '<=', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                // Searches inside the stored snapshot, so a former employee can
                // still be found by name even though the archive table itself
                // holds no name columns.
                $driver = $q->getConnection()->getDriverName();
                $operator = $driver === 'pgsql' ? 'ilike' : 'like';
                $column = $driver === 'pgsql' ? 'snapshot_json::text' : 'snapshot_json';

                $q->where(fn ($sub) => $sub
                    ->whereRaw("{$column} {$operator} ?", ["%{$v}%"])
                    ->orWhere('archive_reason', $operator, "%{$v}%"));
            })
            ->orderByDesc('archived_at')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated($archives, 'Archived records retrieved');
    }

    public function show(Archive $archive): JsonResponse
    {
        $this->authorize('viewArchives');

        return ApiResponse::success($archive->load('archivedBy'));
    }

    /**
     * The audit trail. Administrator-only: the person whose actions are being
     * recorded should not also control the record.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $this->authorize('viewAuditLogs');

        $filters = $request->validate([
            'actor_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action_type' => ['nullable', 'string'],
            'module_key' => ['nullable', 'string'],
            'record_type' => ['nullable', 'string'],
            'record_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $logs = AuditLog::query()
            ->with('actor')
            ->when($filters['actor_user_id'] ?? null, fn ($q, $v) => $q->where('actor_user_id', $v))
            ->when($filters['action_type'] ?? null, fn ($q, $v) => $q->where('action_type', $v))
            ->when($filters['module_key'] ?? null, fn ($q, $v) => $q->where('module_key', $v))
            ->when($filters['record_type'] ?? null, fn ($q, $v) => $q->where('record_type', $v))
            ->when($filters['record_id'] ?? null, fn ($q, $v) => $q->where('record_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($logs, 'Audit trail retrieved');
    }
}
