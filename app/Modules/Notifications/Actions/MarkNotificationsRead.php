<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Read-state changes, always scoped through the user's own notifications relation: another
 * user's id is a 404 (the id is client-supplied and never trusted on its own).
 */
class MarkNotificationsRead
{
    public function one(User $user, string $id): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()->findOrFail($id);
        $notification->markAsRead();

        return $notification;
    }

    /** @return int how many were unread before */
    public function all(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
