<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use App\Models\User;
use App\Modules\Files\Enums\FileVisibility;
use Carbon\CarbonInterface;

/**
 * The moderation state a new upload starts in. Everyone's uploads wait for an
 * approval, except an admin's: an admin could approve the file anyway, so the
 * upload is approved on arrival and the admin is recorded as the reviewer.
 */
final class UploadReview
{
    /**
     * @return array{visibility: FileVisibility, reviewed_by: int|null, reviewed_at: CarbonInterface|null}
     */
    public static function initialFor(?User $uploader): array
    {
        if ($uploader !== null && $uploader->isAdmin()) {
            return ['visibility' => FileVisibility::Approved, 'reviewed_by' => $uploader->id, 'reviewed_at' => now()];
        }

        return ['visibility' => FileVisibility::Pending, 'reviewed_by' => null, 'reviewed_at' => null];
    }
}
