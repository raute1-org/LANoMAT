<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

/**
 * The notification categories a user can switch off (`users.notification_prefs`, read by
 * {@see NotificationPreferences::wants()}). `account` and `system` are deliberately not
 * listed: re-auth warnings and health alerts are not optional.
 */
final class NotificationCategories
{
    /** @var list<string> */
    public const ALL = ['registration', 'checkin', 'match', 'teams', 'friends', 'schedule', 'catering', 'orga_ping', 'discord'];
}
