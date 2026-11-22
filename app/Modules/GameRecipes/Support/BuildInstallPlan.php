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
use App\Support\SafeText;
use Illuminate\Support\Str;

/**
 * Turns a recipe + the viewer's OS into a deterministic install plan for the
 * agent. The server never executes anything: it resolves LAN downloads,
 * orders the steps, and reports what is missing. Every requested plan is
 * recorded (who, which OS) for orga support.
 *
 * A recipe never names this installation's server: `{{server_host}}` and
 * `{{server_port}}` are filled in here, before the plan leaves the server, from
 * a server of the recipe's game the viewer may see ({@see PlanServerAddress}).
 * Every consumer — the agent route, the MCP tool, the native client — goes
 * through this one class, so all of them receive the same final text. The
 * clients keep substituting `{{target}}` and `{{downloads}}` themselves.
 */
final class BuildInstallPlan
{
    public function __construct(
        private readonly RecipeFileMatcher $matcher,
        private readonly PlanServerAddress $servers,
    ) {}

    private InstallPlanUrls $urls;

    /** @return array<string, mixed> */
    public function handle(GameRecipe $recipe, User $viewer, string $os, ?Event $event = null, bool $record = true, ?InstallPlanUrls $urls = null): array
    {
        $this->urls = $urls ?? new McpInstallPlanUrls;
        $os = $this->normalizeOs($os);
        $matches = $this->matcher->matchesFor($recipe, $viewer, $event);

        // A recipe without the placeholders costs no server query.
        $variables = $this->servers->variablesFor($recipe, $viewer, $event);

        $files = [];
        $missing = [];

        foreach ($recipe->files as $entry) {
            // A file authored for another OS (the Windows zip patch on a Linux
            // plan) is not part of this plan at all: it must not be offered as
            // a download and must not count as missing. Its LAN match is also
            // skipped, so a Linux install never picks up UT2004.exe.
            if (! $this->appliesToOs($entry, $os)) {
                continue;
            }

            $shared = $matches[$entry->id] ?? null;

            if ($shared instanceof SharedFile) {
                $files[] = $this->fileDto($entry, $shared, 'lan', $viewer);

                continue;
            }

            if ($entry->url !== null && $entry->url !== '') {
                $files[] = $this->externalDto($entry);

                if ($entry->required) {
                    $missing[] = self::label($entry).' (external download only)';
                }

                continue;
            }

            if ($entry->required) {
                $missing[] = self::label($entry);
            }

            $files[] = [
                'recipe_file_id' => $entry->id,
                'requirement' => $entry->role->value,
                'label' => self::label($entry),
                'os' => $entry->os(),
                'status' => 'missing',
                'required' => $entry->required,
                'source' => null,
            ];
        }

        $steps = array_values(array_filter(
            array_map(fn (array $step): array => $this->stepDto($step, $variables), $recipe->steps ?? []),
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
                'launch_target' => PlanServerAddress::text($recipe->launchTarget(), $variables),
                'launch_targets' => PlanServerAddress::launchTargets($recipe->launchTargets(), $variables),
                'launch_command' => $recipe->launchCommand(),
                'play_url' => $recipe->playUrl(),
            ],
            'os' => $os,
            'target_dir' => $target,
            'downloads_dir' => $target.'/.lanomat-downloads',
            'steps' => $steps,
            'files' => $files,
            'downloads' => $downloads,
            'avatars' => $this->avatars($recipe, $matches, $viewer),
            'missing' => array_values(array_unique($missing)),
            'generated_at' => now()->toIso8601String(),
        ];

        if ($record) {
            GameRecipeInstall::query()->create([
                'game_recipe_id' => $recipe->id,
                'user_id' => $viewer->getKey(),
                'os' => $os,
                'status' => InstallStatus::Planned,
                'plan' => $plan,
            ]);
        }

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

    /** Whether a file authored for one platform belongs in a plan for `$os`. */
    private function appliesToOs(GameRecipeFile $entry, string $os): bool
    {
        $fileOs = $entry->os();

        return $fileOs === null || $fileOs === $os;
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
        // A title is authored text (since game sharing, by any participant),
        // and the folder it becomes is substituted into the quoted arguments
        // of step and launch commands. So the folder keeps only characters
        // that mean nothing to PowerShell, cmd or a POSIX shell: letters,
        // digits, space, dot, underscore and hyphen. No quote, `$`, backtick,
        // semicolon, bracket or separator survives, whatever the title says.
        $name = (string) preg_replace('/[^A-Za-z0-9 ._-]+/', '', Str::ascii($recipe->title));
        $name = (string) preg_replace('/\.\.+/', '.', (string) preg_replace('/\s+/', ' ', $name));
        // Windows refuses a folder that starts or ends with a dot or a space.
        $name = trim($name, ' .');

        if ($name === '') {
            $name = $recipe->slug;
        }

        if ($os === 'windows') {
            return 'C:\\Games\\'.$name;
        }

        if ($os === 'macos' && $recipe->slug === LauncherSupport::PILOT_SLUG) {
            // The OldUnreal macOS bundle reads its assets from Application
            // Support, not from a ~/Games folder. Only the UT2004 pilot ships
            // that bundle; every other macOS recipe gets its own folder.
            return '~/Library/Application Support/UT2004';
        }

        // One folder per game, named like the Linux one. A title that is all
        // separators already fell back to the slug; Str::slug could still come
        // back empty for a degenerate slug, which must never yield `~/Games/`.
        $folder = Str::slug($name);

        return '~/Games/'.($folder !== '' ? $folder : 'game');
    }

    /** @param array<string, mixed> $step
     *  @param array<string, string> $variables
     *  @return array<string, mixed> */
    private function stepDto(array $step, array $variables): array
    {
        return [
            'id' => (string) ($step['id'] ?? ''),
            'title' => (string) ($step['title'] ?? ''),
            'os' => (string) ($step['os'] ?? 'any'),
            'optional' => (bool) ($step['optional'] ?? false),
            'detail' => (string) ($step['detail'] ?? ''),
            'commands' => array_map(
                fn (array $command): array => [
                    'shell' => (string) ($command['shell'] ?? 'bash'),
                    'run' => PlanServerAddress::text((string) ($command['run'] ?? ''), $variables),
                ],
                (array) ($step['commands'] ?? []),
            ),
            'verify' => array_values(array_map(
                fn (mixed $check): mixed => is_array($check) ? PlanServerAddress::check($check, $variables) : $check,
                (array) ($step['verify'] ?? []),
            )),
        ];
    }

    /** @return array<string, mixed> */
    private function fileDto(GameRecipeFile $entry, SharedFile $file, string $source, User $viewer): array
    {
        $link = $this->urls->forFile($file, $viewer);

        return [
            'recipe_file_id' => $entry->id,
            'requirement' => $entry->role->value,
            'label' => self::label($entry),
            'os' => $entry->os(),
            'status' => 'matched',
            'required' => $entry->required,
            'source' => $source,
            // The uploader chose this name: one bounded line, never a path.
            'name' => SafeText::fileName($file->original_name),
            'size_bytes' => $file->size_bytes,
            // The orga's recipe pin wins (it is the published contract); the
            // server-side digest of the stored bytes is the fallback, so the
            // launcher can verify every matched LAN file, not just pinned ones.
            'sha256' => $entry->sha256 ?? $file->sha256,
            'shared_file_id' => $file->id,
            'download_url' => $link['url'],
            'fallback_url' => $entry->url,
        ] + ($link['expires_at'] === null ? [] : ['download_expires_at' => $link['expires_at']]);
    }

    /** @return array<string, mixed> */
    private function externalDto(GameRecipeFile $entry): array
    {
        $url = (string) $entry->url;
        $path = (string) parse_url($url, PHP_URL_PATH);
        $isFile = preg_match('/\.(iso|zip|7z|rar|tar|bz2|gz|xz|exe|msi|dmg|appimage|vrm|bin|cue|chd)$/i', $path) === 1;

        return [
            'recipe_file_id' => $entry->id,
            'requirement' => $entry->role->value,
            'label' => self::label($entry),
            'os' => $entry->os(),
            // A URL that names a file is an external download; anything else
            // (a release page, a tool homepage) is a manual step.
            'status' => $isFile ? 'external' : 'manual',
            'required' => $entry->required,
            'source' => $isFile ? 'external' : 'manual',
            'name' => $isFile ? SafeText::fileName(basename($path)) : self::label($entry),
            'size_bytes' => $entry->size_bytes,
            'sha256' => $entry->sha256,
            'shared_file_id' => null,
            'download_url' => $isFile ? $url : null,
            'fallback_url' => $isFile ? $url : null,
            'url' => $url,
            'manual' => ! $isFile,
        ];
    }

    private static function label(GameRecipeFile $entry): string
    {
        return SafeText::line($entry->label, 120) ?? 'File';
    }

    /**
     * @param  array<int, SharedFile>  $matches
     * @return list<array<string, mixed>>
     */
    private function avatars(GameRecipe $recipe, array $matches, User $viewer): array
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
                'label' => self::label($entry),
                'author' => SafeText::line(is_string($metadata['author'] ?? null) ? $metadata['author'] : null, 80),
                'license' => SafeText::line(is_string($metadata['license'] ?? null) ? $metadata['license'] : null, 80),
                'size_bytes' => $shared === null ? $entry->size_bytes : $shared->size_bytes,
                'sha256' => $entry->sha256 ?? $shared?->sha256,
                'match_name' => SafeText::fileName($shared?->original_name),
                'download_url' => $shared instanceof SharedFile
                    ? $this->urls->forFile($shared, $viewer)['url']
                    : null,
                'available_on_lan' => $shared instanceof SharedFile,
            ];
        }

        return $avatars;
    }
}
