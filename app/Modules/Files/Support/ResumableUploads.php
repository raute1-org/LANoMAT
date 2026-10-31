<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/**
 * Whether uploads go through the resumable (tus) path: the file-host store is
 * selected AND the tusd endpoint and the hook secret are configured. Anything
 * else keeps the legacy form upload through the app. The tus settings are
 * read in one place so the UI, the ticket check and the hooks cannot disagree.
 */
final class ResumableUploads
{
    public static function enabled(): bool
    {
        return config('files.store') === 'host'
            && self::endpoint() !== null
            && FileUrlSigner::isAcceptableSecret((string) config('files.tus.hook_secret'));
    }

    public static function endpoint(): ?string
    {
        $endpoint = (string) config('files.tus.endpoint');

        return str_starts_with($endpoint, 'http') ? $endpoint : null;
    }

    public static function chunkBytes(): int
    {
        return max(1, (int) config('files.tus.chunk_mb')) * 1024 * 1024;
    }

    public static function ticketTtlSeconds(): int
    {
        return max(60, (int) config('files.tus.ticket_ttl_seconds'));
    }

    public static function uploadTtlHours(): int
    {
        return max(1, (int) config('files.tus.upload_ttl_hours'));
    }

    /** Path of tusd's metadata file for an upload, relative to the file host's data directory. */
    public static function infoPath(string $uploadId): string
    {
        return trim((string) config('files.tus.staging_dir'), '/').'/'.$uploadId.'.info';
    }

    public static function hashTicket(string $ticket): string
    {
        return hash('sha256', $ticket);
    }
}
