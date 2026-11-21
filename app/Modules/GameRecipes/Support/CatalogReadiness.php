<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameRecipes\Exceptions\RecipeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Whether the text LANoMAT is about to put on a recipe branch satisfies the
 * repository's own gate. `.github/workflows/recipes.yml` runs
 * `resources/recipes/schema/validate.mjs` on every recipe pull request, so a
 * proposal that would fail there must be refused here — otherwise the orga
 * gets a red pull request instead of a reason.
 *
 * Only what can be decided from the exported `recipe.yaml` and `SKILL.md` is
 * checked: the schema-level rules (through {@see RecipeValidator}), the
 * SKILL.md contract (front matter, 60 to 150 lines), the draft rules (3 to 6
 * steps and the LAN-verification prose), command shape and glob shape.
 * Catalogue-wide rules (duplicate slugs, aliases, the index row) are not
 * visible from one recipe; the row is written by {@see CatalogFile} and the
 * rest is the reviewer's.
 *
 * Pure text in, findings out — no IO, so a proposal never leaves LANoMAT to
 * discover that it would have been refused.
 */
final class CatalogReadiness
{
    /** The line range `validate.mjs` enforces for every SKILL.md. */
    private const int MIN_LINES = 60;

    private const int MAX_LINES = 150;

    /** A draft needs this many steps; the validator's own bounds. */
    private const int MIN_DRAFT_STEPS = 3;

    private const int MAX_DRAFT_STEPS = 6;

    /**
     * Everything about the exported text that would turn the recipe pull
     * request red, in the order the validator reports it.
     *
     * @param  array{yaml: string, skill: string}  $export
     * @param  string|null  $directory  the catalogue directory the files go to (the directory name must equal the slug)
     * @return list<string>
     */
    public function problems(array $export, ?string $directory = null): array
    {
        try {
            $data = Yaml::parse($export['yaml']);
        } catch (ParseException $exception) {
            return ['recipe.yaml is not valid YAML: '.$exception->getMessage()];
        }

        if (! is_array($data)) {
            return ['recipe.yaml does not hold a mapping.'];
        }

        /** @var array<string, mixed> $data */
        $problems = [];

        try {
            (new RecipeValidator)->validate($data, 'recipe.yaml');
        } catch (RecipeException $exception) {
            $problems = [...$problems, ...$exception->problems];
        }

        $slug = $data['slug'] ?? null;

        if ($directory !== null && is_string($slug) && $slug !== $directory) {
            $problems[] = sprintf(
                'recipe.yaml slug "%s" does not equal the directory "%s" it would be read from.',
                $slug,
                $directory,
            );
        }

        return [
            ...$problems,
            ...$this->skillProblems($data, $export['skill']),
            ...$this->stepProblems($data),
            ...$this->requirementProblems($data),
            ...$this->draftProblems($data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function skillProblems(array $data, string $skill): array
    {
        // validate.mjs counts `skill.split('\n').length`, including a trailing empty line.
        $lines = count(explode("\n", $skill));
        $problems = [];

        if ($lines < self::MIN_LINES || $lines > self::MAX_LINES) {
            $problems[] = sprintf(
                'SKILL.md has %d lines; the catalogue needs %d to %d lines (add the install guide sections).',
                $lines,
                self::MIN_LINES,
                self::MAX_LINES,
            );
        }

        if (preg_match('/^---\r?\n([\s\S]*?)\r?\n---\r?\n/', $skill, $match) !== 1) {
            $problems[] = 'SKILL.md has no YAML front matter; the catalogue needs "---" with name and description at the top.';

            return $problems;
        }

        try {
            $meta = Yaml::parse($match[1]);
        } catch (ParseException) {
            $problems[] = 'SKILL.md front matter is not valid YAML.';

            return $problems;
        }

        $name = is_array($meta) ? ($meta['name'] ?? null) : null;
        $expected = data_get($data, 'skill.name');

        if ($name !== $expected) {
            $problems[] = sprintf(
                'SKILL.md front matter name %s does not match the recipe\'s skill.name %s.',
                is_string($name) ? '"'.$name.'"' : '(missing)',
                is_string($expected) ? '"'.$expected.'"' : '(missing)',
            );
        }

        $description = is_array($meta) ? ($meta['description'] ?? null) : null;

        if (! is_string($description) || trim($description) === '') {
            $problems[] = 'SKILL.md front matter description is missing.';
        } elseif (mb_strlen($description) > 1024) {
            $problems[] = 'SKILL.md front matter description is longer than 1024 characters.';
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function stepProblems(array $data): array
    {
        $problems = [];
        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $id = (string) ($step['id'] ?? 'step');
            $os = (string) ($step['os'] ?? 'any');
            $commands = is_array($step['commands'] ?? null) ? $step['commands'] : [];

            foreach ($commands as $command) {
                if (! is_array($command)) {
                    continue;
                }

                $run = $command['run'] ?? null;

                if (is_string($run) && str_contains($run, '&&')) {
                    $problems[] = sprintf(
                        'Step "%s" joins commands with "&&"; the catalogue needs one command per entry.',
                        $id,
                    );
                }

                $shell = (string) ($command['shell'] ?? 'bash');

                if ($os === 'windows' && $shell === 'bash') {
                    $problems[] = sprintf(
                        'Step "%s" is os: windows but uses bash; the catalogue needs powershell or cmd there (or os: any).',
                        $id,
                    );
                }

                if ($os === 'linux' && $shell !== 'bash') {
                    $problems[] = sprintf(
                        'Step "%s" is os: linux but uses %s; the catalogue needs bash there (or os: any).',
                        $id,
                        $shell,
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function requirementProblems(array $data): array
    {
        $problems = [];
        $requirements = is_array($data['requires'] ?? null) ? $data['requires'] : [];

        foreach ($requirements as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $label = (string) ($requirement['label'] ?? 'File');
            $patterns = is_array($requirement['match'] ?? null) ? $requirement['match'] : [];

            foreach ($patterns as $pattern) {
                if (! is_string($pattern)) {
                    continue;
                }

                if (str_contains($pattern, '/') || str_contains($pattern, '\\')) {
                    $problems[] = sprintf(
                        'Requirement "%s" has a match glob "%s" with a path separator; the catalogue matches base names only.',
                        $label,
                        $pattern,
                    );
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function draftProblems(array $data): array
    {
        if (($data['status'] ?? null) !== 'draft') {
            return [];
        }

        $problems = [];
        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        $count = count($steps);

        if ($count < self::MIN_DRAFT_STEPS || $count > self::MAX_DRAFT_STEPS) {
            $problems[] = sprintf(
                'The draft has %d step%s; the catalogue requires %d to %d steps for a draft (split or merge the guide).',
                $count,
                $count === 1 ? '' : 's',
                self::MIN_DRAFT_STEPS,
                self::MAX_DRAFT_STEPS,
            );
        }

        $prose = [(string) ($data['description'] ?? '')];

        foreach ($steps as $step) {
            if (is_array($step)) {
                $prose[] = (string) ($step['detail'] ?? '');
                $prose[] = (string) ($step['title'] ?? '');
            }
        }

        if (preg_match('/needs verification on the lan|draft recipe|not yet verified/i', implode("\n", $prose)) !== 1) {
            $problems[] = 'The draft must say that it needs verification on the LAN (in the description or a step detail).';
        }

        return $problems;
    }
}
