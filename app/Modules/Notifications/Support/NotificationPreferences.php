<?php

namespace App\Modules\Notifications\Support;

use App\Models\User;

class NotificationPreferences
{
    /**
     * Whether the given user wants notifications in the given category.
     * Defaults to true — a category must be explicitly disabled to be
     * suppressed.
     */
    public function wants(User $user, string $category): bool
    {
        $prefs = $user->notification_prefs ?? [];

        return ($prefs[$category] ?? true) === true;
    }
}
