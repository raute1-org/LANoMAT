<?php

declare(strict_types=1);

namespace App\Modules\Files\Contracts;

use App\Modules\Files\Models\SharedFile;

/**
 * Whether a shared file can back a shared game ("Als Spiel teilen"). Files
 * owns the question because the files page answers it for every row; the
 * recipe catalogue knows the archive rule and answers while GameRecipes is
 * enabled, and a null implementation answers "nothing" otherwise.
 *
 * Files must never import GameRecipes: the answer arrives through this
 * contract, bound in AppServiceProvider.
 */
interface ShareableGameArchive
{
    /**
     * The same rule the share page applies to its file list: an archive the
     * page can describe, with a name a recipe can quote and match.
     */
    public function isShareable(SharedFile $file): bool;
}
