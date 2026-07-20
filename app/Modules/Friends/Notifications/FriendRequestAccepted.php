<?php

declare(strict_types=1);

namespace App\Modules\Friends\Notifications;

use App\Models\User;
use App\Modules\Friends\Actions\RespondToFriendRequest;
use App\Modules\Friends\Actions\SendFriendRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies the original requester that their pending friend request was
 * accepted — either explicitly via {@see RespondToFriendRequest} or
 * implicitly via the auto-accept path in {@see SendFriendRequest} (when the
 * addressee had already sent a reverse request). Bell (`database`) only, no
 * Discord mirror — see {@see FriendRequestReceived} for why.
 */
class FriendRequestAccepted extends Notification
{
    use Queueable;

    public readonly string $category;

    public function __construct(
        public readonly User $accepter,
    ) {
        $this->category = 'friends';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => __('friends.notifications.request_accepted.title'),
            'body' => __('friends.notifications.request_accepted.body', ['name' => $this->accepter->name]),
        ];
    }
}
