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

    /** Shape of each store id in `metadata.store_ids`, matching the recipe schema. */
    public const STORE_ID_PATTERNS = [
        'steam' => '/^[0-9]{1,10}$/D',
        'epic' => '/^[A-Za-z0-9._-]{1,128}$/D',
        'gog' => '/^[0-9]{1,20}$/D',
        'ubisoft' => '/^[0-9]{1,10}$/D',
    ];

    public const STORE_ID_HINTS = [
        'steam' => 'digits',
        'epic' => 'an Epic app name (letters, digits, dot, underscore, hyphen)',
        'gog' => 'digits',
        'ubisoft' => 'digits',
    ];

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

        $requirements = $data['requires'] ?? $data['requirements'] ?? [];

        if (! is_array($requirements)) {
            $problems[] = "{$origin}: requires must be a list";
            $requirements = [];
        } elseif (count($requirements) > 50) {
            $problems[] = "{$origin}: requirements may contain at most 50 entries";
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
            } elseif (mb_strlen($label) > 200) {
                $problems[] = "{$where}.label must be at most 200 characters";
            }

            if (! in_array($requirement['role'] ?? null, RecipeFileRole::values(), true)) {
                $problems[] = "{$where}.role must be one of ".implode(', ', RecipeFileRole::values());
            }

            // A file that only exists for one platform says so, so a plan for
            // the other OS lists (and requires) the matching asset instead of
            // downloading the wrong archive.
            $requirementOs = $requirement['os'] ?? null;

            if ($requirementOs !== null && ! in_array($requirementOs, ['windows', 'linux', 'macos', 'any'], true)) {
                $problems[] = "{$where}.os must be windows, linux, macos or any";
            }

            $match = $requirement['match'] ?? [];
            $url = $requirement['url'] ?? null;

            $required = (bool) ($requirement['required'] ?? in_array($requirement['role'] ?? '', ['base', 'iso'], true));
            $manual = (bool) ($requirement['manual'] ?? false);

            if ($required && ! $manual && (! is_array($match) || $match === []) && (! is_string($url) || $url === '')) {
                // An optional entry may be informational only (a tool the Orga
                // provides, a note); a required one must name a file or a URL.
                $problems[] = "{$where} needs match patterns, a url, or both";
            }

            if (is_array($match)) {
                if (count($match) > 20) {
                    $problems[] = "{$where}.match may contain at most 20 patterns";
                }

                foreach ($match as $pattern) {
                    if (! is_string($pattern) || trim($pattern) === '') {
                        $problems[] = "{$where}.match contains a non-string pattern";
                    } elseif (mb_strlen($pattern) > 200) {
                        $problems[] = "{$where}.match patterns must be at most 200 characters";
                    }
                }
            }

            // The installer downloads a recipe's url on the player's machine and
            // asks no further questions. Anything but http(s) (file://, ftp://,
            // javascript:, data:) is either a local-file read or a scheme the
            // agent's HTTP client cannot validate — reject it at the source.
            if (is_string($url) && $url !== '') {
                $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

                if (! in_array($scheme, ['http', 'https'], true)) {
                    $problems[] = "{$where}.url must be an http(s) URL";
                } elseif (mb_strlen($url) > 1000) {
                    $problems[] = "{$where}.url must be at most 1000 characters";
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
        } elseif (count($steps) > 50) {
            $problems[] = "{$origin}: steps may contain at most 50 entries";
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
            } elseif (mb_strlen((string) $step['title']) > 200) {
                $problems[] = "{$where}.title must be at most 200 characters";
            }

            if (! in_array($step['os'] ?? 'any', ['windows', 'linux', 'macos', 'any'], true)) {
                $problems[] = "{$where}.os must be windows, linux, macos or any";
            }

            $commands = $step['commands'] ?? [];

            if (! is_array($commands)) {
                $problems[] = "{$where}.commands must be a list";
                $commands = [];
            } elseif (count($commands) > 50) {
                $problems[] = "{$where}.commands may contain at most 50 entries";
            }

            foreach ($commands as $commandIndex => $command) {
                $commandRun = data_get($command, 'run');

                if (! is_array($command) || ! is_string($commandRun) || trim($commandRun) === '') {
                    $problems[] = "{$where}.commands[{$commandIndex}].run is required";
                } elseif (mb_strlen($commandRun) > 2000) {
                    $problems[] = "{$where}.commands[{$commandIndex}].run must be at most 2000 characters";
                } elseif (! in_array($command['shell'] ?? 'bash', ['bash', 'powershell', 'cmd'], true)) {
                    $problems[] = "{$where}.commands[{$commandIndex}].shell must be bash, powershell or cmd";
                }
            }

            $verify = $step['verify'] ?? [];

            if (! is_array($verify)) {
                $problems[] = "{$where}.verify must be a list";
            } else {
                foreach ($verify as $verifyIndex => $check) {
                    $checkOs = is_array($check) ? ($check['os'] ?? null) : null;

                    if ($checkOs !== null && ! in_array($checkOs, ['windows', 'linux', 'macos', 'any'], true)) {
                        $problems[] = "{$where}.verify[{$verifyIndex}].os must be windows, linux, macos or any";
                    }
                }
            }
        }

        // A launch target may narrow itself to one platform (flat on Windows,
        // Linux or macOS). Metadata is the canonical place; the top-level
        // `launch_targets` form is a deprecated alias kept for old recipes.
        $launchTargets = [];

        if (is_array($data['metadata'] ?? null) && is_array($data['metadata']['launch_targets'] ?? null)) {
            $launchTargets = $data['metadata']['launch_targets'];
        } elseif (is_array($data['launch_targets'] ?? null)) {
            $launchTargets = $data['launch_targets'];
        }

        foreach ($launchTargets as $index => $target) {
            $targetOs = is_array($target) ? ($target['os'] ?? null) : null;

            if ($targetOs !== null && ! in_array($targetOs, ['windows', 'linux', 'macos', 'any'], true)) {
                $problems[] = "{$origin}: launch_targets[{$index}].os must be windows, linux, macos or any";
            }
        }

        $storeIds = is_array($data['metadata'] ?? null) ? ($data['metadata']['store_ids'] ?? null) : null;

        if ($storeIds !== null) {
            if (! is_array($storeIds)) {
                $problems[] = "{$origin}: metadata.store_ids must be a map";
            } else {
                foreach ($storeIds as $store => $storeId) {
                    $pattern = self::STORE_ID_PATTERNS[$store] ?? null;

                    if ($pattern === null) {
                        $problems[] = "{$origin}: metadata.store_ids.{$store} is not a known store";

                        continue;
                    }

                    if (! is_string($storeId) || preg_match($pattern, $storeId) !== 1) {
                        $problems[] = "{$origin}: metadata.store_ids.{$store} must be ".self::STORE_ID_HINTS[$store];
                    }
                }
            }
        }

        if ($problems !== []) {
            throw new RecipeException($problems);
        }
    }
}
