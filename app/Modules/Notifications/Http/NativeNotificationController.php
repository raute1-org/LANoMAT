<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Actions\MarkNotificationsRead;
use App\Modules\Notifications\Actions\UpdateNotificationPreferences;
use App\Modules\Notifications\Support\NotificationCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notifications for the native client (bearer API v1, routes in routes/voice-client.php). The
 * data is the browser bell's: the caller's own database notifications, nothing else is
 * reachable because every query goes through `$user->notifications()`.
 */
class NativeNotificationController extends Controller
{
    private const MAX_PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $perPage = max(1, min(self::MAX_PER_PAGE, $request->integer('per_page', 20)));

        $query = $request->boolean('unread') ? $user->unreadNotifications() : $user->notifications();
        $page = $query
            ->reorder()
            ->orderByRaw('(read_at is null) desc')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'notifications' => collect($page->items())->map(fn (mixed $n): array => $this->payload($n))->values(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->user($request)->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $notification, MarkNotificationsRead $mark): JsonResponse
    {
        return response()->json(['notification' => $this->payload($mark->one($this->user($request), $notification))]);
    }

    public function readAll(Request $request, MarkNotificationsRead $mark): JsonResponse
    {
        return response()->json(['marked' => $mark->all($this->user($request))]);
    }

    public function preferences(Request $request, UpdateNotificationPreferences $update): JsonResponse
    {
        return response()->json(['preferences' => $update->effective($this->user($request))]);
    }

    public function updatePreferences(Request $request, UpdateNotificationPreferences $update): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array:'.implode(',', NotificationCategories::ALL)],
            'preferences.*' => ['boolean'],
        ]);

        /** @var array<string, bool> $changes */
        $changes = array_map(fn (mixed $v): bool => (bool) $v, $data['preferences']);

        return response()->json(['preferences' => $update->handle($this->user($request), $changes)]);
    }

    /** @return array{id: string, category: string|null, title: string, body: string, read: bool, read_at: string|null, created_at: string|null} */
    private function payload(mixed $notification): array
    {
        /** @var DatabaseNotification $notification */
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'category' => is_string($data['category'] ?? null) ? $data['category'] : null,
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
