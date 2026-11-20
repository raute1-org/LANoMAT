<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\InstallStatus;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Models\GameRecipeFile;
use App\Modules\GameRecipes\Models\GameRecipeInstall;
use Illuminate\Support\Str;

/**
 * Turns a recipe + the viewer's OS into a deterministic install plan for the
 * agent. The server never executes anything: it resolves LAN downloads,
 * orders the steps, and reports what is missing. Every requested plan is
 * recorded (who, which OS) for orga support.
 */
final class BuildInstallPlan
{
    public function __construct(private readonly RecipeFileMatcher $matcher) {}

    /** @return array<string, mixed> */
    public function handle(GameRecipe $recipe, User $viewer, string $os, ?Event $event = null): array
    {
        $os = $this->normalizeOs($os);
        $matches = $this->matcher->matchesFor($recipe, $viewer, $event);

        $files = [];
        $missing = [];

        foreach ($recipe->files as $entry) {
            $shared = $matches[$entry->id] ?? null;

            if ($shared instanceof SharedFile) {
                $files[] = $this->fileDto($entry, $shared, 'lan');

                continue;
            }

            if ($entry->url !== null && $entry->url !== '') {
                $files[] = $this->externalDto($entry);

                if ($entry->required) {
                    $missing[] = $entry->label.' (external download only)';
                }

                continue;
            }

            if ($entry->required) {
                $missing[] = $entry->label;
            }

            $files[] = [
                'recipe_file_id' => $entry->id,
                'requirement' => $entry->role->value,
                'label' => $entry->label,
                'status' => 'missing',
                'required' => $entry->required,
                'source' => null,
            ];
        }

        $steps = array_values(array_filter(
            array_map(fn (array $step): array => $this->stepDto($step), $recipe->steps ?? []),
            fn (array $step): bool => $step['os'] === 'any' || $step['os'] === $os,
        ));

        $downloads = array_values(array_filter(
            $files,
            static fn (array $file): bool => in_array($file['status'], ['matched', 'external'], true),
        ));

        $target = $this->targetDir($recipe, $os);
        $plan = [
            'recipe' => [
                'slug' => $recipe->slug,
                'title' => $recipe->title,
                'version' => $recipe->version,
                'skill' => $this->skillName($recipe),
                'launch_target' => $recipe->launchTarget(),
                'launch_command' => $recipe->launchCommand(),
                'play_url' => $recipe->playUrl(),
            ],
            'os' => $os,
            'target_dir' => $target,
            'downloads_dir' => $target.'/.lanomat-downloads',
            'steps' => $steps,
            'files' => $files,
            'downloads' => $downloads,
            'avatars' => $this->avatars($recipe, $matches),
            'missing' => array_values(array_unique($missing)),
            'generated_at' => now()->toIso8601String(),
        ];

        GameRecipeInstall::query()->create([
            'game_recipe_id' => $recipe->id,
            'user_id' => $viewer->getKey(),
            'os' => $os,
            'status' => InstallStatus::Planned,
            'plan' => $plan,
        ]);

        return $plan;
    }

    public function normalizeOs(string $os): string
    {
        $os = mb_strtolower(trim($os));

        return match ($os) {
            'win', 'windows', 'win32', 'win64' => 'windows',
            'mac', 'macos', 'darwin' => 'macos',
            default => 'linux',
        };
    }

    private function skillName(GameRecipe $recipe): string
    {
        $markdown = (string) $recipe->skill_markdown;

        if (preg_match('/^name:\s*"?([a-z0-9-]+)"?$/m', $markdown, $matches) === 1) {
            return $matches[1];
        }

        return $recipe->slug;
    }

    private function targetDir(GameRecipe $recipe, string $os): string
    {
        $name = trim((string) preg_replace('/[<>:"|?*\\\\\/]+/', '', $recipe->title));
        $name = trim(str_replace(['..', '~'], '', $name));
        $name = $name === '' ? $recipe->slug : $name;

        return $os === 'windows'
            ? 'C:\\Games\\'.$name
            : '~/Games/'.Str::slug($name);
    }

    /** @param array<string, mixed> $step
     *  @return array<string, mixed> */
    private function stepDto(array $step): array
    {
        return [
            'id' => (string) ($step['id'] ?? ''),
            'title' => (string) ($step['title'] ?? ''),
            'os' => (string) ($step['os'] ?? 'any'),
            'optional' => (bool) ($step['optional'] ?? false),
            'detail' => (string) ($step['detail'] ?? ''),
            'commands' => array_map(
                static fn (array $command): array => [
                    'shell' => (string) ($command['shell'] ?? 'bash'),
                    'run' => (string) ($command['run'] ?? ''),
                ],
                (array) ($step['commands'] ?? []),
            ),
            'verify' => array_values((array) ($step['verify'] ?? [])),
        ];
    }

    /** @return array<string, mixed> */
    private function fileDto(GameRecipeFile $entry, SharedFile $file, string $source): array
    {
        return [
            'recipe_file_id' => $entry->id,
            'requirement' => $entry->role->value,
            'label' => $entry->label,
            'status' => 'matched',
            'required' => $entry->required,
            'source' => $source,
            'name' => $file->original_name,
            'size_bytes' => $file->size_bytes,
            'sha256' => $entry->sha256,
            'shared_file_id' => $file->id,
            'download_url' => route('mcp.files.download', ['sharedFile' => $file->id], true),
            'fallback_url' => $entry->url,
        ];
    }

    /** @return array<string, mixed> */
    private function externalDto(GameRecipeFile $entry): array
    {
        return [
            'recipe_file_id' => $entry->id,
            'requirement' => $entry->role->value,
            'label' => $entry->label,
            'status' => 'external',
            'required' => $entry->required,
            'source' => 'external',
            'name' => basename((string) parse_url((string) $entry->url, PHP_URL_PATH)),
            'size_bytes' => $entry->size_bytes,
            'sha256' => $entry->sha256,
            'shared_file_id' => null,
            'download_url' => $entry->url,
            'fallback_url' => $entry->url,
        ];
    }

    /**
     * @param  array<int, SharedFile>  $matches
     * @return list<array<string, mixed>>
     */
    private function avatars(GameRecipe $recipe, array $matches): array
    {
        $avatars = [];

        foreach ($recipe->files as $entry) {
            if (! $entry->role->isSelectable()) {
                continue;
            }

            $shared = $matches[$entry->id] ?? null;
            $metadata = $entry->metadata ?? [];

            $avatars[] = [
                'id' => 'recipe-file-'.$entry->id,
                'recipe_file_id' => $entry->id,
                'label' => $entry->label,
                'author' => $metadata['author'] ?? null,
                'license' => $metadata['license'] ?? null,
                'size_bytes' => $shared === null ? $entry->size_bytes : $shared->size_bytes,
                'sha256' => $entry->sha256,
                'match_name' => $shared?->original_name,
                'download_url' => $shared instanceof SharedFile
                    ? route('mcp.files.download', ['sharedFile' => $shared->id], true)
                    : null,
                'available_on_lan' => $shared instanceof SharedFile,
            ];
        }

        return $avatars;
    }
}
