<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use App\Models\User;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Models\UploadReservation;

/**
 * The per-event per-user storage quota and the per-file maximum, in one place
 * so the ticket check, the legacy upload, the page and the docs agree. Used
 * bytes are the user's files (every visibility) plus the quota held by open
 * resumable-upload reservations.
 */
final class UploadQuota
{
    /**
     * The upload cap per event: the user's own quota when an admin set one
     * (`users.files_quota_mb`), otherwise the configured default.
     */
    public static function capBytes(?User $user = null): int
    {
        $megabytes = $user === null || $user->files_quota_mb === null
            ? (int) config('files.per_user_quota_mb')
            : $user->files_quota_mb;

        return $megabytes * 1024 * 1024;
    }

    public static function maxFileBytes(): int
    {
        return (int) config('files.max_file_gb') * 1024 * 1024 * 1024;
    }

    public static function usedBytes(int $eventId, int $userId): int
    {
        return self::fileBytes($eventId, $userId) + self::reservedBytes($eventId, $userId);
    }

    public static function fileBytes(int $eventId, int $userId): int
    {
        return (int) SharedFile::query()
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->sum('size_bytes');
    }

    public static function reservedBytes(int $eventId, int $userId): int
    {
        return (int) UploadReservation::query()
            ->holdingQuota()
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->sum('size_bytes');
    }
}
