<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Models\User;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;

/**
 * The avatar/content catalogue both the MCP tool and the REST endpoint return.
 * Each entry carries its LAN match; a null download_url means "not uploaded
 * to this LAN yet", which the installer shows disabled.
 */
final class AvatarCatalog
{
    public function __construct(private readonly RecipeFileMatcher $matcher) {}

    /** @return list<array<string, mixed>> */
    public function forRecipe(?GameRecipe $recipe, User $user, ?string $query = null): array
    {
        $query = mb_strtolower(trim((string) $query));

        $files = GameRecipeFile::query()
            ->whereIn('role', ['avatar', 'content'])
            ->when(($recipeId = $recipe?->getKey()) !== null, fn ($builder) => $builder->where('game_recipe_id', $recipeId), fn ($builder) => $builder->whereNull('game_recipe_id'))
            ->orderBy('sort')
            ->get()
            ->when($query !== '', fn ($collection) => $collection->filter(
                static fn (GameRecipeFile $file): bool => str_contains(mb_strtolower($file->label.' '.($file->metadata['author'] ?? '')), $query),
            ))
            ->values();

        return array_values($files->map(function (GameRecipeFile $file) use ($user): array {
            $shared = $this->matcher->match($file, $user);
            $metadata = $file->metadata ?? [];

            return [
                'id' => 'recipe-file-'.$file->id,
                'recipe_slug' => $file->recipe?->slug,
                'label' => $file->label,
                'author' => $metadata['author'] ?? null,
                'license' => $metadata['license'] ?? null,
                'size_bytes' => $shared === null ? $file->size_bytes : $shared->size_bytes,
                'sha256' => $file->sha256,
                'match_name' => $shared?->original_name,
                'available_on_lan' => $shared instanceof SharedFile,
                'download_url' => $shared instanceof SharedFile
                    ? route('mcp.files.download', ['sharedFile' => $shared->id], true)
                    : null,
            ];
        })->all());
    }
}
