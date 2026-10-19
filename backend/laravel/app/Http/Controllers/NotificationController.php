<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * In-app notifications for the signed-in user.
 *
 * Notifications are always scoped to the records belonging to the signed-in
 * login, so a user can only ever read their own. There is deliberately no
 * route that lists another user's notifications.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'unread_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $notifications = $this->own($request)
            ->when($filters['unread_only'] ?? false, fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::success([
            'notifications' => collect($notifications->items())->map(fn ($n) => [
                'id' => $n->id,
                'category' => $n->data['category'] ?? 'general',
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? '',
                'link' => $n->data['link'] ?? null,
                'severity' => $n->data['severity'] ?? 'info',
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'unread_count' => $this->own($request)->whereNull('read_at')->count(),
        ], 'Notifications retrieved', 200, [
            'current_page' => $notifications->currentPage(),
            'last_page' => $notifications->lastPage(),
            'total' => $notifications->total(),
        ]);
    }

    /**
     * The unread badge, and the same figure broken down by category.
     *
     * One request answers both, because the bell and the sidebar are asking the
     * same question of the same rows and polling twice for it would double the
     * traffic to say nothing new. The totals are therefore guaranteed to agree:
     * the categories are a partition of the same count, not a second tally that
     * can drift away from it.
     *
     * The category lives in the notification's JSON payload. It is grouped in
     * PHP rather than in SQL deliberately - a JSON path expression is spelled
     * differently on MySQL, PostgreSQL and SQLite, and this project runs on all
     * three. One person's unread notifications are a handful of rows, so there
     * is nothing to gain by pushing it into the database and a working query on
     * two platforms out of three to lose.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $unread = $this->own($request)
            ->whereNull('read_at')
            ->get(['id', 'data']);

        $byCategory = $unread
            ->groupBy(fn ($notification) => $notification->data['category'] ?? 'general')
            ->map->count();

        return ApiResponse::success([
            'unread_count' => $unread->count(),
            'by_category' => $byCategory,
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        // The id column is a UUID; PostgreSQL rejects anything else with an
        // error rather than finding nothing, so it is turned away first.
        abort_unless(Str::isUuid($id), 404);

        $notification = $this->own($request)->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return ApiResponse::success(null, 'Notification marked as read');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->own($request)->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(null, 'All notifications marked as read');
    }

    /**
     * The notifications this login may read.
     *
     * @return Builder<DatabaseNotification>
     */
    private function own(Request $request): Builder
    {
        $records = $this->records($request);

        return DatabaseNotification::query()->where(function (Builder $query) use ($records) {
            foreach ($records as $record) {
                $query->orWhere(fn (Builder $q) => $q
                    ->where('notifiable_type', $record->getMorphClass())
                    ->where('notifiable_id', $record->getKey()));
            }
        });
    }

    /**
     * Every record whose notifications belong to this login (audit S-03).
     *
     * A portal login used to be resolved to one record only, so an employee -
     * whose login is linked to their employee record - never saw the
     * deployment notice sent to the applicant record they came from, nor even
     * the notice that their own password had changed. The rule lives on the
     * User model now, beside its reverse that decides whose notification
     * preferences apply, so the two cannot drift apart.
     *
     * @return list<Model>
     */
    private function records(Request $request): array
    {
        return $request->user()->notifiableRecords();
    }
}
