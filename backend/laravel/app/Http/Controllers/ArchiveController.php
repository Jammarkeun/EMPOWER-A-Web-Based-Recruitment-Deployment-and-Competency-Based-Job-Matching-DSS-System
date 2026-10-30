<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Archive;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditTrailPresenter;
use App\Support\TextSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ArchiveController extends Controller
{
    public function __construct(private readonly AuditTrailPresenter $auditTrail)
    {
    }

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
                //
                // Lower-cased on both sides outside PostgreSQL: MariaDB keeps
                // a JSON column in a binary collation, so a plain LIKE there
                // was case-sensitive and "ramirez" did not find "Ramirez".
                $pgsql = $q->getConnection()->getDriverName() === 'pgsql';
                $snapshot = ($pgsql ? 'snapshot_json::text ilike' : 'LOWER(snapshot_json) like')
                    .' ? escape \''.TextSearch::ESCAPE.'\'';

                $q->where(function ($sub) use ($v, $pgsql, $snapshot) {
                    foreach ($this->snapshotForms($v, $pgsql) as $form) {
                        $sub->orWhereRaw($snapshot, [TextSearch::contains($form)]);
                    }

                    $sub->orWhereContains('archive_reason', $v);
                });
            })
            ->orderByDesc('archived_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated($archives, 'Archived records retrieved');
    }

    /**
     * The search text as it can appear inside a stored snapshot.
     *
     * Snapshots are JSON text on MariaDB and SQLite, and until 2026-10-30 they
     * were written with every accented letter escaped: "Peña" is stored as
     * "Peña", so typing "peña" found nothing. Newer snapshots keep the
     * letter itself (Archive casts to json:unicode). Both spellings are tried,
     * the escaped one in the casings a name is written in, since lower-casing
     * the column cannot change the case an escape stands for. PostgreSQL keeps
     * jsonb, which prints letters as themselves whatever was sent.
     *
     * @return list<string>
     */
    private function snapshotForms(string $text, bool $pgsql): array
    {
        $json = fn (string $value, int $flags = 0) => substr(
            json_encode($value, $flags | JSON_INVALID_UTF8_IGNORE),
            1,
            -1,
        );

        if ($pgsql) {
            return [$json($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }

        return array_values(array_unique([
            $json(mb_strtolower($text), JSON_UNESCAPED_UNICODE),
            ...array_map(
                fn (int $case) => strtolower($json(mb_convert_case($text, $case))),
                [MB_CASE_LOWER, MB_CASE_UPPER, MB_CASE_TITLE],
            ),
        ]));
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
            'actor_role' => ['nullable', 'string', 'max:40'],
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'action_type' => ['nullable', 'string'],
            'module_key' => ['nullable', 'string'],
            'record_type' => ['nullable', 'string'],
            'record_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $logs = AuditLog::query()
            ->with(['actor', 'company:id,company_name'])
            ->when($filters['actor_user_id'] ?? null, fn ($q, $v) => $q->where('actor_user_id', $v))
            ->when($filters['actor_role'] ?? null, fn ($q, $v) => $q->where('actor_role', $v))
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['action_type'] ?? null, fn ($q, $v) => $q->where('action_type', $v))
            ->when($filters['module_key'] ?? null, fn ($q, $v) => $q->where('module_key', $v))
            ->when($filters['record_type'] ?? null, fn ($q, $v) => $q->where('record_type', $v))
            ->when($filters['record_id'] ?? null, fn ($q, $v) => $q->where('record_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);

        // Who did what to which record, field by field (audit R-28), rather
        // than the raw rows with only their new values readable.
        $logs->setCollection(collect($this->auditTrail->present($logs->getCollection())));

        return ApiResponse::paginated($logs, 'Audit trail retrieved');
    }

    /**
     * The values the audit filters can offer: only those that occur in the
     * trail, so a filter never lists an action nothing has recorded.
     */
    public function auditLogFacets(): JsonResponse
    {
        $this->authorize('viewAuditLogs');

        $distinct = fn (string $column) => AuditLog::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->values();

        return ApiResponse::success([
            'action_types' => $distinct('action_type'),
            'modules' => $distinct('module_key'),
            'record_types' => $distinct('record_type'),
            'roles' => $distinct('actor_role'),
            'actors' => User::query()
                ->whereIn('id', AuditLog::query()->whereNotNull('actor_user_id')->distinct()->select('actor_user_id'))
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->limit(300)
                ->get()
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->full_name, 'user_type' => $user->user_type])
                ->values(),
        ]);
    }
}
