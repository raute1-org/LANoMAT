<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Exceptions\RecipeException;

/**
 * Validates the authored YAML before it reaches the database. Every problem is
 * collected in one pass so a contributor sees the whole list, not one error at
 * a time. The JSON Schema in resources/recipes/schema mirrors these rules for
 * editors; this class is the runtime authority.
 */
final class RecipeValidator
{
    public const SCHEMA = 'lanomat.recipe/v1';

    public const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public const SKILL_NAME_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** @param array<string, mixed> $data */
    public function validate(array $data, string $origin = 'recipe'): void
    {
        $problems = [];

        if (($data['schema'] ?? null) !== self::SCHEMA) {
            $problems[] = "{$origin}: schema must be \"".self::SCHEMA.'"';
        }

        $slug = $data['slug'] ?? null;

        if (! is_string($slug) || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $problems[] = "{$origin}: slug must be lowercase-kebab-case";
        }

        if (! is_string($data['title'] ?? null) || trim((string) $data['title']) === '') {
            $problems[] = "{$origin}: title is required";
        }

        $status = $data['status'] ?? null;

        if (! is_string($status) || RecipeStatus::tryFrom($status) === null) {
            $problems[] = "{$origin}: status must be one of ".implode(', ', array_column(RecipeStatus::cases(), 'value'));
        }

        $platforms = $data['platforms'] ?? null;

        if (! is_array($platforms) || $platforms === []) {
            $problems[] = "{$origin}: platforms must be a non-empty list";
        } else {
            foreach ($platforms as $platform) {
                if (! in_array($platform, ['windows', 'linux', 'macos'], true)) {
                    $problems[] = "{$origin}: unknown platform \"{$platform}\"";
                }
            }
        }

        $aliases = $data['aliases'] ?? [];

        if (! is_array($aliases)) {
            $problems[] = "{$origin}: aliases must be a list";
        }

        $skill = $data['skill'] ?? [];

        $skillName = data_get($skill, 'name');

        if (! is_array($skill) || ! is_string($skillName) || preg_match(self::SKILL_NAME_PATTERN, $skillName) !== 1) {
            $problems[] = "{$origin}: skill.name must be lowercase-kebab-case";
        }

        $skillDescription = data_get($skill, 'description');

        if (! is_array($skill) || ! is_string($skillDescription) || mb_strlen($skillDescription) < 20) {
            $problems[] = "{$origin}: skill.description must say what the skill does and when it applies (>= 20 chars)";
        }

        $requirements = $data['requirements'] ?? [];

        if (! is_array($requirements)) {
            $problems[] = "{$origin}: requirements must be a list";
            $requirements = [];
        }

        foreach ($requirements as $index => $requirement) {
            $label = is_array($requirement) ? ($requirement['label'] ?? null) : null;
            $where = "{$origin}: requirements[{$index}]";

            if (! is_array($requirement)) {
                $problems[] = "{$where} must be a map";

                continue;
            }

            if (! is_string($label) || trim($label) === '') {
                $problems[] = "{$where}.label is required";
            }

            if (! in_array($requirement['role'] ?? null, RecipeFileRole::values(), true)) {
                $problems[] = "{$where}.role must be one of ".implode(', ', RecipeFileRole::values());
            }

            $match = $requirement['match'] ?? [];
            $url = $requirement['url'] ?? null;

            if ((! is_array($match) || $match === []) && (! is_string($url) || $url === '')) {
                $problems[] = "{$where} needs match patterns, a url, or both";
            }

            if (is_array($match)) {
                foreach ($match as $pattern) {
                    if (! is_string($pattern) || trim($pattern) === '') {
                        $problems[] = "{$where}.match contains a non-string pattern";
                    }
                }
            }

            $sha = $requirement['sha256'] ?? null;

            if (is_string($sha) && $sha !== '' && preg_match('/^[a-f0-9]{64}$/i', $sha) !== 1) {
                $problems[] = "{$where}.sha256 must be 64 hex chars";
            }
        }

        $steps = $data['steps'] ?? [];

        if (! is_array($steps) || $steps === []) {
            $problems[] = "{$origin}: steps must be a non-empty list";
            $steps = is_array($steps) ? $steps : [];
        }

        $seenIds = [];

        foreach ($steps as $index => $step) {
            $where = "{$origin}: steps[{$index}]";

            if (! is_array($step)) {
                $problems[] = "{$where} must be a map";

                continue;
            }

            $id = $step['id'] ?? null;

            if (! is_string($id) || $id === '') {
                $problems[] = "{$where}.id is required";
            } elseif (in_array($id, $seenIds, true)) {
                $problems[] = "{$where}.id \"{$id}\" is duplicated";
            } else {
                $seenIds[] = $id;
            }

            if (! is_string($step['title'] ?? null) || trim((string) $step['title']) === '') {
                $problems[] = "{$where}.title is required";
            }

            if (! in_array($step['os'] ?? 'any', ['windows', 'linux', 'any'], true)) {
                $problems[] = "{$where}.os must be windows, linux or any";
            }

            $commands = $step['commands'] ?? [];

            if (! is_array($commands)) {
                $problems[] = "{$where}.commands must be a list";
                $commands = [];
            }

            foreach ($commands as $commandIndex => $command) {
                $commandRun = data_get($command, 'run');

                if (! is_array($command) || ! is_string($commandRun) || trim($commandRun) === '') {
                    $problems[] = "{$where}.commands[{$commandIndex}].run is required";
                } elseif (! in_array($command['shell'] ?? 'bash', ['bash', 'powershell', 'cmd'], true)) {
                    $problems[] = "{$where}.commands[{$commandIndex}].shell must be bash, powershell or cmd";
                }
            }

            $verify = $step['verify'] ?? [];

            if (! is_array($verify)) {
                $problems[] = "{$where}.verify must be a list";
            }
        }

        if ($problems !== []) {
            throw new RecipeException($problems);
        }
    }
}
