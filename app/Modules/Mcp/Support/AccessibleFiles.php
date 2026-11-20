<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Support;

use App\Models\User;
use App\Modules\Files\Models\SharedFile;

/**
 * The extra rule the agent surface applies on top of SharedFilePolicy.
 *
 * The policy answers "may this role download this file's bytes?". It does not
 * know that the *object* belongs to an unpublished event: an approved file of
 * a draft event would otherwise be downloadable by anyone who guesses its
 * (sequential) id. Lists and recipe matching already hide draft events; the
 * download route and the file_download_url tool must agree, or ids become the
 * way around it.
 *
 * The uploader and orga keep access: a draft event's own files must still be
 * inspectable before the event is announced.
 */
final class AccessibleFiles
{
    public static function ensureVisible(SharedFile $file, User $user): void
    {
        if ($user->isOrga() || $file->user_id === $user->getKey()) {
            return;
        }

        $event = $file->event;

        abort_unless($event !== null && $event->isPubliclyVisible(), 404);
    }
}
