<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notifications for the signed-in user.
 *
 * Notifications are always scoped to the authenticated notifiable, so a user can
 * only ever read their own. There is deliberately no route that lists another
 * user's notifications.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'unread_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $notifiable = $this->notifiable($request);

        $query = $filters['unread_only'] ?? false
            ? $notifiable->unreadNotifications()
            : $notifiable->notifications();

        $notifications = $query->paginate($filters['per_page'] ?? 20);

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
            'unread_count' => $notifiable->unreadNotifications()->count(),
        ], 'Notifications retrieved', 200, [
            'current_page' => $notifications->currentPage(),
            'last_page' => $notifications->lastPage(),
            'total' => $notifications->total(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'unread_count' => $this->notifiable($request)->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->notifiable($request)->notifications()->findOrFail($id);
        $notification->markAsRead();

        return ApiResponse::success(null, 'Notification marked as read');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->notifiable($request)->unreadNotifications->markAsRead();

        return ApiResponse::success(null, 'All notifications marked as read');
    }

    /**
     * Resolves who the notifications belong to.
     *
     * A portal login is attached to an applicant or employee record, and
     * lifecycle notifications are addressed to that record rather than to the
     * user account, so the notifiable is resolved through the link.
     */
    private function notifiable(Request $request)
    {
        $user = $request->user();

        if ($user->user_type === 'applicant' && $user->applicant) {
            return $user->applicant;
        }

        if ($user->user_type === 'employee' && $user->employee) {
            return $user->employee;
        }

        return $user;
    }
}
