<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Models\SharedFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one rule for which files of an event a participant sees in a list: every
 * approved file plus the viewer's own pending ones (never rejected files, never
 * another user's pending files). Shared by the participant page and the native client API.
 */
final class VisibleFiles
{
    /**
     * @return Collection<int, SharedFile>
     */
    public static function listFor(Event $event, ?int $userId): Collection
    {
        return self::query($event, $userId)
            ->with('uploader')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Builder<SharedFile>
     */
    public static function query(Event $event, ?int $userId): Builder
    {
        return SharedFile::query()
            ->where('event_id', $event->id)
            ->where(function ($builder) use ($userId) {
                $builder->where('visibility', FileVisibility::Approved);

                if ($userId !== null) {
                    $builder->orWhere(function ($ownPending) use ($userId) {
                        $ownPending->where('user_id', $userId)
                            ->where('visibility', FileVisibility::Pending);
                    });
                }
            });
    }
}
