<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use App\Modules\Files\Contracts\ShareableGameArchive;
use App\Modules\Files\Models\SharedFile;

/**
 * Nothing can be shared as a game while the recipe catalogue is off: the share
 * page does not exist, so the files page offers no link.
 */
final class NullShareableGameArchive implements ShareableGameArchive
{
    public function isShareable(SharedFile $file): bool
    {
        return false;
    }
}
