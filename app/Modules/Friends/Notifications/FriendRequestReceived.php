<?php

declare(strict_types=1);

namespace App\Modules\Friends\Notifications;

use App\Models\User;
use App\Modules\Friends\Actions\SendFriendRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies the addressee of a newly created pending friend request (see
 * {@see SendFriendRequest}). Not sent on the auto-accept path — that case
 * skips straight to {@see FriendRequestAccepted} for the original requester.
 * Bell (`database`) only, no Discord mirror — a friend request is a
 * low-urgency, in-app-only signal, matching the RegistrationConfirmed
 * pattern rather than the CheckinOpened dual-channel one.
 */
class FriendRequestReceived extends Notification
{
    use Queueable;

    public readonly string $category;

    public function __construct(
        public readonly User $requester,
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
            'title' => __('friends.notifications.request_received.title'),
            'body' => __('friends.notifications.request_received.body', ['name' => $this->requester->name]),
        ];
    }
}
