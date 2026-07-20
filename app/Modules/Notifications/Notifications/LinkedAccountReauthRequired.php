<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent when a linked account's OAuth token refresh fails
 * (see {@see LinkedAccount::needsReauth()}) and the user needs to re-link
 * the provider from their connections settings. Always delivered — there is
 * no opt-out category for a security-relevant action item, unlike
 * {@see RegistrationConfirmed}.
 */
class LinkedAccountReauthRequired extends Notification
{
    use Queueable;

    public function __construct(public readonly LinkedAccountProvider $provider) {}

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
            'category' => 'account',
            'title' => __('notifications.linked_account_reauth_required.title'),
            'body' => __('notifications.linked_account_reauth_required.body', ['provider' => $this->provider->label()]),
        ];
    }
}
