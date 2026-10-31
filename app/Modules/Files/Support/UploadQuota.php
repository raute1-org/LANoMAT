<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

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
    public static function capBytes(): int
    {
        return (int) config('files.per_user_quota_mb') * 1024 * 1024;
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
