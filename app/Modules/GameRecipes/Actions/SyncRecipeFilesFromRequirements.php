<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Actions;

use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;

/** Keeps the normalized file rows in step with the recipe's requirement list (Filament saves). */
final class SyncRecipeFilesFromRequirements
{
    public function handle(GameRecipe $recipe): void
    {
        $pins = $recipe->files->mapWithKeys(fn (GameRecipeFile $file): array => [
            $file->role->value.'|'.$file->label => $file->shared_file_id,
        ])->all();

        $recipe->files()->delete();

        foreach ($recipe->requirements ?? [] as $index => $requirement) {
            $role = (string) ($requirement['role'] ?? 'content');
            $label = (string) ($requirement['label'] ?? 'File');

            $recipe->files()->create([
                'role' => $role,
                'label' => $label,
                'match_patterns' => array_values(array_map('strval', (array) ($requirement['match'] ?? []))),
                'size_bytes' => isset($requirement['size_bytes']) ? (int) $requirement['size_bytes'] : null,
                'sha256' => isset($requirement['sha256']) && (string) $requirement['sha256'] !== '' ? mb_strtolower((string) $requirement['sha256']) : null,
                'url' => isset($requirement['url']) && (string) $requirement['url'] !== '' ? (string) $requirement['url'] : null,
                'required' => (bool) ($requirement['required'] ?? false),
                'sort' => $index,
                'metadata' => array_filter([
                    'notes' => $requirement['notes'] ?? null,
                    'author' => $requirement['author'] ?? null,
                    'license' => $requirement['license'] ?? null,
                    'tags' => $requirement['tags'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== []),
                'shared_file_id' => isset($requirement['shared_file_id']) ? (int) $requirement['shared_file_id'] : ($pins[$role.'|'.$label] ?? null),
            ]);
        }
    }
}
