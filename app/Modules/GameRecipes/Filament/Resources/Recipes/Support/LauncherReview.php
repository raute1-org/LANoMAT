<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes\Support;

use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\LauncherSupport;
use Illuminate\Support\HtmlString;

/**
 * The read-only "what will actually run on the players' PCs" view of a recipe,
 * for the review page and the publish confirmations. `metadata.launch_targets`,
 * `metadata.play_url` and `metadata.portable` never had a form field, so a
 * reviewer used to release commands they could not see.
 *
 * Everything here reads the stored data: a launch command is shown exactly as
 * stored (no trimming, no re-rendering), and escaping happens at the edge (the
 * form components escape text, the confirmation escapes every line it builds).
 */
final class LauncherReview
{
    public const OVERRIDE_AUTO = 'auto';

    public const OVERRIDE_ALWAYS = 'always';

    public const OVERRIDE_NEVER = 'never';

    /** Which LauncherSupport rule decides, in LauncherSupport's own precedence. */
    public static function reason(GameRecipe $recipe): LauncherReviewReason
    {
        $override = self::override($recipe);

        if ($override === true) {
            return LauncherReviewReason::OverrideOn;
        }

        if ($override === false) {
            return LauncherReviewReason::OverrideOff;
        }

        if ($recipe->slug === LauncherSupport::PILOT_SLUG) {
            return LauncherReviewReason::Pilot;
        }

        if (in_array($recipe->slug, LauncherSupport::VERIFIED_SLUGS, true)) {
            return LauncherReviewReason::Verified;
        }

        if ($recipe->playUrl() !== null) {
            return LauncherReviewReason::Browser;
        }

        if (self::portable($recipe)) {
            return LauncherReviewReason::Portable;
        }

        return LauncherReviewReason::None;
    }

    /** Whether the launcher offers the recipe at all (LauncherSupport is the authority). */
    public static function offered(GameRecipe $recipe): bool
    {
        return app(LauncherSupport::class)->supports($recipe);
    }

    /** Whether the generic installer's portable shape is complete (not just the flag). */
    public static function portable(GameRecipe $recipe): bool
    {
        return app(LauncherSupport::class)->isPortable($recipe);
    }

    /** Whether the recipe declares `metadata.portable: true`, whatever its shape. */
    public static function portableDeclared(GameRecipe $recipe): bool
    {
        return (self::metadata($recipe)['portable'] ?? null) === true;
    }

    /**
     * The portable conditions that are still missing, so a declared-but-not-
     * offered recipe explains itself instead of just saying "no".
     *
     * @return list<string>
     */
    public static function missingPortableConditions(GameRecipe $recipe): array
    {
        $missing = [];

        if (! self::hasRequiredBase($recipe)) {
            $missing[] = __('game_recipes.launcher.missing_base');
        }

        if (! self::hasFileCheck($recipe)) {
            $missing[] = __('game_recipes.launcher.missing_check');
        }

        if ($recipe->launchTargets() === []) {
            $missing[] = __('game_recipes.launcher.missing_target');
        }

        return $missing;
    }

    /**
     * Every launch target as stored, plus the commands the launcher derives
     * from `metadata.launch` / `metadata.launch_vr` when they are not declared
     * as a target — nothing that the launcher runs stays hidden.
     *
     * @return list<array{label: string, os: string, shell: string|null, command: string|null, notes: string|null, source: string}>
     */
    public static function launchRows(GameRecipe $recipe): array
    {
        $rows = [];

        foreach ((array) (self::metadata($recipe)['launch_targets'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $rows[] = [
                'label' => self::text($entry['label'] ?? null) ?? __('game_recipes.launcher.no_label'),
                'os' => self::text($entry['os'] ?? null) ?? 'any',
                'shell' => self::text($entry['shell'] ?? null),
                'command' => self::text($entry['command'] ?? null),
                'notes' => self::text($entry['notes'] ?? null),
                'source' => 'launch_targets',
            ];
        }

        // `metadata.launch` (and `launch_vr`) become launch targets without
        // being stored as one. Show them as derived rows when the same command
        // is not already on a declared row.
        $declared = array_values(array_filter(array_column($rows, 'command')));

        foreach ($recipe->launchTargets() as $target) {
            if (in_array($target['command'], $declared, true)) {
                continue;
            }

            $rows[] = [
                'label' => $target['label'],
                'os' => $target['os'],
                'shell' => $target['shell'],
                'command' => $target['command'],
                'notes' => $target['notes'],
                'source' => 'metadata.launch',
            ];
        }

        return $rows;
    }

    /**
     * The commands the launcher would start, exactly as stored. This is what
     * `GameRecipe::launchTargets()` hands the launcher engine.
     *
     * @return list<string>
     */
    public static function launchCommands(GameRecipe $recipe): array
    {
        return array_map(
            static fn (array $target): string => $target['command'],
            $recipe->launchTargets(),
        );
    }

    /** How many shell commands the recipe's steps carry (these run in the installer, not the launcher). */
    public static function stepCommandCount(GameRecipe $recipe): int
    {
        $count = 0;

        foreach ((array) $recipe->steps as $step) {
            $count += count(array_filter(
                (array) ($step['commands'] ?? []),
                static fn (mixed $command): bool => is_array($command),
            ));
        }

        return $count;
    }

    /** The three-state form value for `metadata.launcher_supported`. */
    public static function overrideState(?GameRecipe $recipe): string
    {
        return match ($recipe === null ? null : self::override($recipe)) {
            true => self::OVERRIDE_ALWAYS,
            false => self::OVERRIDE_NEVER,
            null => self::OVERRIDE_AUTO,
        };
    }

    /**
     * The stored metadata with only `launcher_supported` changed: every other
     * key keeps its value and its position. `auto` removes the key; `null`
     * metadata stays null when there was nothing to remove.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    public static function withOverride(?array $metadata, string $state): ?array
    {
        if ($state === self::OVERRIDE_AUTO) {
            if ($metadata === null || ! array_key_exists('launcher_supported', $metadata)) {
                return $metadata;
            }

            unset($metadata['launcher_supported']);

            return $metadata;
        }

        if (! in_array($state, [self::OVERRIDE_ALWAYS, self::OVERRIDE_NEVER], true)) {
            return $metadata;
        }

        $metadata ??= [];
        $metadata['launcher_supported'] = $state === self::OVERRIDE_ALWAYS;

        return $metadata;
    }

    /**
     * The honest confirmation text of a publish action: what the players' PCs
     * will run. Every recipe-authored line is escaped with `e()`; only the
     * markup this class adds is HTML.
     */
    public static function releaseSummary(GameRecipe $recipe): HtmlString
    {
        $commands = self::launchCommands($recipe);

        $html = [
            '<strong>'.e(__('game_recipes.release.heading')).'</strong>',
            e(__('game_recipes.release.counts', [
                'steps' => self::stepCommandCount($recipe),
                'launch' => count($commands),
            ])),
            e(__('game_recipes.release.runs_on_players')),
        ];

        if ($commands === []) {
            $html[] = e(__('game_recipes.release.no_launch'));
        }

        foreach ($commands as $command) {
            // A stored command may be multi-line and may contain quotes,
            // `$`, `<` or `>`: escaped, inside a block that keeps the lines and
            // wraps long ones.
            $html[] = '<pre style="white-space: pre-wrap; word-break: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; margin: 0.25rem 0;">'
                .e($command).'</pre>';
        }

        return new HtmlString(implode('<br>', $html));
    }

    /** @return array<string, mixed> */
    private static function metadata(GameRecipe $recipe): array
    {
        return is_array($recipe->metadata) ? $recipe->metadata : [];
    }

    private static function override(GameRecipe $recipe): ?bool
    {
        $override = self::metadata($recipe)['launcher_supported'] ?? null;

        return is_bool($override) ? $override : null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /** Same rule as LauncherSupport: a base requirement is required unless it says otherwise. */
    private static function hasRequiredBase(GameRecipe $recipe): bool
    {
        foreach ((array) $recipe->requirements as $requirement) {
            $isBase = ($requirement['role'] ?? null) === RecipeFileRole::Base->value;

            if ($isBase && (bool) ($requirement['required'] ?? true)) {
                return true;
            }
        }

        return false;
    }

    /** Same rule as LauncherSupport: a non-optional step verifies a file or folder. */
    private static function hasFileCheck(GameRecipe $recipe): bool
    {
        foreach ((array) $recipe->steps as $step) {
            if ((bool) ($step['optional'] ?? false)) {
                continue;
            }

            foreach ((array) ($step['verify'] ?? []) as $check) {
                if (is_array($check)
                    && in_array($check['type'] ?? null, ['file_exists', 'dir_exists'], true)
                    && is_string($check['path'] ?? null)
                    && trim($check['path']) !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
