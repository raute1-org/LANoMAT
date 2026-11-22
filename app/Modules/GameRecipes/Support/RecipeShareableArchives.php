<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\Files\Contracts\ShareableGameArchive;
use App\Modules\Files\Models\SharedFile;

/**
 * The recipe catalogue's answer to the files page: a file can back a shared
 * game exactly when the share page would accept it as the archive, which is
 * {@see PortableRecipeBuilder::isShareableArchive()} — the extension list, a
 * name a recipe can quote, and a match pattern that still names the file.
 */
final class RecipeShareableArchives implements ShareableGameArchive
{
    public function isShareable(SharedFile $file): bool
    {
        return PortableRecipeBuilder::isShareableArchive($file->original_name);
    }
}
