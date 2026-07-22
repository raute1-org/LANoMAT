<?php

namespace App\Modules\Preflight\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SystemUnhealthy extends Notification
{
    use Queueable;

    /** @param list<string> $downLabels */
    public function __construct(public readonly array $downLabels) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, string> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'system',
            'title' => __('preflight.bell.title'),
            'body' => __('preflight.bell.body', ['systems' => implode(', ', $this->downLabels)]),
        ];
    }
}
