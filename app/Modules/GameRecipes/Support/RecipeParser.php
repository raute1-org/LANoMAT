<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Exceptions\RecipeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads one recipe directory (recipe.yaml + optional SKILL.md) into the row
 * shape GameRecipe/GameRecipeFile expect. No database access, no IO besides
 * the two files — SyncRecipes owns the transaction.
 */
final class RecipeParser
{
    public function __construct(
        private readonly RecipeValidator $validator,
        private readonly SkillRenderer $skills,
    ) {}

    /**
     * @return array{recipe: array<string, mixed>, files: list<array<string, mixed>>}
     */
    public function parseFile(string $yamlPath, string $source, ?string $sourceRef = null): array
    {
        try {
            $data = Yaml::parseFile($yamlPath);
        } catch (ParseException $exception) {
            throw RecipeException::file($yamlPath, $exception->getMessage());
        }

        if (! is_array($data)) {
            throw RecipeException::file($yamlPath, 'the file does not contain a YAML map');
        }

        /** @var array<string, mixed> $data */
        $this->validator->validate($data, $yamlPath);

        $dir = dirname($yamlPath);
        $skillPath = $dir.'/SKILL.md';
        $skill = is_file($skillPath) ? (string) file_get_contents($skillPath) : $this->skills->render($data);

        // A recipe source is untrusted data. Resolve symlinks and keep the
        // skill text inside the recipe directory, so a git/SMB source cannot
        // read /etc/shadow into a public SKILL.md.
        if (is_file($skillPath)) {
            $realDir = realpath($dir);
            $realSkill = realpath($skillPath);

            if ($realDir === false || $realSkill === false || ! str_starts_with($realSkill, $realDir.DIRECTORY_SEPARATOR)) {
                throw RecipeException::file($skillPath, 'SKILL.md resolves outside the recipe directory');
            }

            $skill = (string) file_get_contents($realSkill);
        }

        $recipe = [
            'slug' => (string) $data['slug'],
            'title' => (string) $data['title'],
            'aliases' => array_values(array_map('strval', (array) ($data['aliases'] ?? []))),
            'version' => isset($data['version']) ? (string) $data['version'] : null,
            'status' => (string) $data['status'],
            'source' => $source,
            'source_ref' => $sourceRef,
            'platforms' => array_values(array_map('strval', (array) $data['platforms'])),
            'summary' => isset($data['summary']) ? mb_substr((string) $data['summary'], 0, 500) : null,
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'homepage' => isset($data['homepage']) ? (string) $data['homepage'] : null,
            'license' => isset($data['license']) ? (string) $data['license'] : null,
            'cover_image_path' => isset($data['cover']) && is_string($data['cover']) && $data['cover'] !== '' ? (string) $data['cover'] : null,
            'skill_markdown' => $skill,
            'requirements' => array_values((array) ($data['requires'] ?? $data['requirements'] ?? [])),
            'steps' => array_values((array) ($data['steps'] ?? [])),
            'metadata' => $this->metadata($data),
        ];

        $files = [];

        foreach (array_values((array) ($data['requires'] ?? $data['requirements'] ?? [])) as $index => $requirement) {
            /** @var array<string, mixed> $requirement */
            $files[] = [
                'role' => (string) $requirement['role'],
                'label' => (string) $requirement['label'],
                'match_patterns' => array_values(array_map('strval', (array) ($requirement['match'] ?? []))),
                'size_bytes' => isset($requirement['size_bytes']) ? (int) $requirement['size_bytes'] : null,
                'sha256' => isset($requirement['sha256']) && (string) $requirement['sha256'] !== '' ? mb_strtolower((string) $requirement['sha256']) : null,
                'url' => isset($requirement['url']) && (string) $requirement['url'] !== '' ? (string) $requirement['url'] : null,
                'required' => (bool) ($requirement['required'] ?? in_array($requirement['role'] ?? '', [RecipeFileRole::Base->value, RecipeFileRole::Iso->value], true)),
                'sort' => $index,
                'metadata' => array_filter([
                    'notes' => $requirement['notes'] ?? null,
                    'author' => $requirement['author'] ?? null,
                    'license' => $requirement['license'] ?? null,
                    'tags' => is_array($requirement['tags'] ?? null) ? array_values($requirement['tags']) : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== []),
            ];
        }

        return ['recipe' => $recipe, 'files' => $files];
    }

    /**
     * Recipe metadata plus the launch keys that older recipes declared at the
     * top level. `metadata.*` is canonical (merge log § 10); the top-level form
     * is a deprecated alias that must not silently disappear at import time,
     * because the games page and the agent read launch data from `metadata`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function metadata(array $data): array
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        foreach (['launch', 'launch_targets', 'launch_vr', 'play_url'] as $key) {
            if (! array_key_exists($key, $metadata) && array_key_exists($key, $data)) {
                $metadata[$key] = $data[$key];
            }
        }

        return $metadata;
    }
}
