<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use App\Modules\Notifications\Support\NotificationCategories;
use App\Modules\Notifications\Support\NotificationPreferences;

/** Sets the caller's own category switches; unknown categories are ignored, others keep their value. */
class UpdateNotificationPreferences
{
    /**
     * @param  array<string, bool>  $changes  category => wanted
     * @return array<string, bool> the effective preference of every known category
     */
    public function handle(User $user, array $changes): array
    {
        $prefs = $user->notification_prefs ?? [];

        foreach ($changes as $category => $wanted) {
            if (in_array($category, NotificationCategories::ALL, true)) {
                $prefs[$category] = $wanted;
            }
        }

        $user->forceFill(['notification_prefs' => $prefs])->save();

        return $this->effective($user);
    }

    /** @return array<string, bool> */
    public function effective(User $user): array
    {
        $preferences = app(NotificationPreferences::class);
        $out = [];

        foreach (NotificationCategories::ALL as $category) {
            $out[$category] = $preferences->wants($user, $category);
        }

        return $out;
    }
}
