<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Support;

use App\Modules\GameRecipes\Enums\RecipeFileRole;
use App\Modules\GameRecipes\Models\GameRecipe;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes a GameRecipe row back into the repository's file format
 * (`recipe.yaml` + `SKILL.md`), so a shared recipe can travel into git as a
 * pull request. The parser reads the result into the same row.
 *
 * Only recipe text leaves: instance data (file pins, people, `pr_*`,
 * `in_lineup`, `contributed_via`) never does. Anything the v1 schema does not
 * allow is normalised or dropped, so the emitted document always validates —
 * even for a contributed row that was written by a panel form rather than the
 * bundled YAML.
 */
final class RecipeYamlExporter
{
    /**
     * The keys `recipe.schema.json` allows under `metadata`. `launch_targets`
     * and `play_url` are top-level in the file format (the parser lifts them
     * back into metadata); every other metadata key is instance data or a
     * legacy alias. A test compares this list with the schema file.
     *
     * @var list<string>
     */
    public const METADATA_KEYS = [
        'genre',
        'players',
        'lan_recommended',
        'live_url',
        'server',
        'tags',
        'players_max',
        'multiplayer',
        'portable',
        'launcher_supported',
        'launch',
        'store_ids',
    ];

    private const FALLBACK_LICENSE = 'Game files are user-supplied.';

    private const PLATFORMS = ['windows', 'linux', 'macos'];

    private const OS_VALUES = ['windows', 'linux', 'macos', 'any'];

    private const SHELLS = ['powershell', 'cmd', 'bash'];

    private const VERIFY_TYPES = ['file_exists', 'dir_exists', 'command'];

    /**
     * @return array{yaml: string, skill: string}
     */
    public function export(GameRecipe $recipe): array
    {
        $summary = $this->summary($recipe);
        $skill = $this->skillText($recipe);
        $frontmatter = $this->frontmatter($skill);
        $metadata = is_array($recipe->metadata) ? $recipe->metadata : [];

        $data = [
            'schema' => RecipeValidator::SCHEMA,
            'slug' => $this->slug($recipe),
            'title' => $this->title($recipe),
            'aliases' => $this->aliases($recipe),
            'version' => $this->text($recipe->version) !== '' ? $this->limit($this->text($recipe->version), 40) : '1.0',
            'status' => $recipe->status->value,
            'platforms' => $this->platforms($recipe),
            'summary' => $summary,
            'description' => $this->description($recipe, $summary),
        ];

        // The schema asks for a URI; a row without a valid one simply has no
        // homepage rather than an unvalidatable document.
        $homepage = $this->text($recipe->homepage);

        if ($homepage !== '' && filter_var($homepage, FILTER_VALIDATE_URL) !== false) {
            $data['homepage'] = $homepage;
        }

        $data['license'] = $this->text($recipe->license) !== '' ? $this->text($recipe->license) : self::FALLBACK_LICENSE;

        $playUrl = $this->httpUrl($metadata['play_url'] ?? null);

        if ($playUrl !== null) {
            $data['play_url'] = $playUrl;
        }

        $data['skill'] = $this->skill($recipe, $frontmatter, $summary);
        $data['requires'] = $this->requirements($recipe);
        $data['steps'] = $this->steps($recipe);

        $launchTargets = $this->launchTargets($metadata);

        if ($launchTargets !== []) {
            $data['launch_targets'] = $launchTargets;
        }

        $data['metadata'] = $this->metadata($metadata);

        $yaml = Yaml::dump(
            $data,
            8,
            4,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
                | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
                | Yaml::DUMP_COMPACT_NESTED_MAPPING,
        );

        return [
            'yaml' => rtrim($yaml, "\r\n")."\n",
            'skill' => $skill,
        ];
    }

    /** One row for `resources/recipes/_catalog.md`. */
    public function catalogRow(GameRecipe $recipe): string
    {
        $cells = [
            '`'.$this->slug($recipe).'`',
            $this->title($recipe),
            implode(', ', $this->platforms($recipe)),
            $recipe->status->value,
            $this->summary($recipe),
        ];

        return '| '.implode(' | ', array_map($this->cell(...), $cells)).' |';
    }

    /** The stored skill with exactly one trailing newline. */
    private function skillText(GameRecipe $recipe): string
    {
        return rtrim((string) ($recipe->skill_markdown ?? ''), "\r\n")."\n";
    }

    /** @return array<string, mixed> */
    private function frontmatter(string $skill): array
    {
        if (preg_match('/^---\r?\n(.*?)\r?\n---(?:\r?\n|$)/s', $skill, $match) !== 1) {
            return [];
        }

        try {
            $data = Yaml::parse($match[1]);
        } catch (ParseException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     * @return array{name: string, description: string, compatibility?: string}
     */
    private function skill(GameRecipe $recipe, array $frontmatter, string $summary): array
    {
        $name = $this->text($frontmatter['name'] ?? null);

        if ($name === '' || mb_strlen($name) > 64 || preg_match(RecipeValidator::SKILL_NAME_PATTERN, $name) !== 1) {
            $name = $this->slug($recipe);
        }

        // RecipeValidator wants a description of at least 20 characters, while
        // the schema only asks for one: pad a thin fallback rather than emit a
        // document that cannot be read back.
        $description = $this->oneLine($this->text($frontmatter['description'] ?? null));

        if (mb_strlen($description) < 20) {
            $description = mb_strlen($summary) >= 20
                ? $summary
                : 'Install '.$this->title($recipe).' from the LAN recipe when the user asks for it.';
        }

        $skill = [
            'name' => $name,
            'description' => $this->limit($description, 1024),
        ];

        $compatibility = $this->oneLine($this->text($frontmatter['compatibility'] ?? null));

        if ($compatibility !== '') {
            $skill['compatibility'] = $this->limit($compatibility, 200);
        }

        return $skill;
    }

    private function description(GameRecipe $recipe, string $summary): string
    {
        // Multi-line text keeps its exact shape (a literal block round-trips);
        // an empty description falls back to the summary, as the schema needs
        // one and the row has no better text.
        $description = $this->preserve($recipe->description);

        return trim($description) !== '' ? $description : $summary;
    }

    private function summary(GameRecipe $recipe): string
    {
        $summary = $this->oneLine($this->text($recipe->summary));

        if ($summary === '') {
            $summary = $this->oneLine($this->title($recipe));
        }

        return $this->limit($summary, 200);
    }

    private function title(GameRecipe $recipe): string
    {
        $title = $this->oneLine($this->text($recipe->title));

        if ($title === '') {
            $title = $this->slug($recipe);
        }

        return $this->limit($title, 120);
    }

    private function slug(GameRecipe $recipe): string
    {
        $slug = $this->text($recipe->slug);

        if (mb_strlen($slug) > 64 || preg_match(RecipeValidator::SLUG_PATTERN, $slug) !== 1) {
            $slug = trim(Str::slug($slug !== '' ? $slug : $this->text($recipe->title)), '-');
        }

        $slug = $this->limit($slug, 64);

        return $slug !== '' ? $slug : 'game';
    }

    /** @return list<string> */
    private function aliases(GameRecipe $recipe): array
    {
        $aliases = [];

        foreach ((array) ($recipe->aliases ?? []) as $alias) {
            $alias = trim($alias);

            if ($alias === '') {
                continue;
            }

            $aliases[] = $this->limit($alias, 80);
        }

        return array_values(array_unique($aliases));
    }

    /** @return list<string> */
    private function platforms(GameRecipe $recipe): array
    {
        $platforms = [];

        foreach ((array) ($recipe->platforms ?? []) as $platform) {
            if (in_array($platform, self::PLATFORMS, true) && ! in_array($platform, $platforms, true)) {
                $platforms[] = $platform;
            }
        }

        // The schema requires at least one platform; a row without a usable
        // value is a Windows recipe, the only platform every LAN bench has.
        return $platforms !== [] ? $platforms : ['windows'];
    }

    /** @return list<array<string, mixed>> */
    private function requirements(GameRecipe $recipe): array
    {
        $requirements = [];

        $files = $recipe->files()->limit(50)->get();

        if ($files->isNotEmpty()) {
            foreach ($files as $file) {
                $requirements[] = $this->requirement([
                    'role' => $file->role->value,
                    'label' => $file->label,
                    'os' => is_array($file->metadata) ? ($file->metadata['os'] ?? null) : null,
                    'match' => $file->match_patterns ?? [],
                    'required' => $file->required,
                    'size_bytes' => $file->size_bytes,
                    'sha256' => $file->sha256,
                    'url' => $file->url,
                    'notes' => is_array($file->metadata) ? ($file->metadata['notes'] ?? null) : null,
                    'tags' => is_array($file->metadata) ? ($file->metadata['tags'] ?? null) : null,
                ], count($requirements));
            }

            return $requirements;
        }

        // A row that was never synced or contributed has no file rows yet; its
        // YAML-shaped `requirements` column is the honest fallback.
        foreach (array_slice((array) ($recipe->requirements ?? []), 0, 50) as $index => $requirement) {
            $requirements[] = $this->requirement($requirement, $index);
        }

        return $requirements;
    }

    /**
     * @param  array<string, mixed>  $requirement
     * @return array<string, mixed>
     */
    private function requirement(array $requirement, int $index): array
    {
        $role = $this->text($requirement['role'] ?? null);
        $role = in_array($role, RecipeFileRole::values(), true) ? $role : RecipeFileRole::Content->value;

        $label = $this->oneLine($this->text($requirement['label'] ?? null));
        $label = $label !== '' ? $this->limit($label, 160) : 'File';

        $out = ['role' => $role, 'label' => $label];

        $os = $this->text($requirement['os'] ?? null);

        if (in_array($os, self::PLATFORMS, true)) {
            $out['os'] = $os;
        }

        $match = [];

        foreach ((array) ($requirement['match'] ?? $requirement['match_patterns'] ?? []) as $pattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                continue;
            }

            $match[] = $this->limit(trim($pattern), 200);
        }

        $out['match'] = array_values(array_unique($match));

        $url = $this->httpUrl($requirement['url'] ?? null);
        $required = $this->toBool($requirement['required'] ?? null)
            ?? in_array($role, [RecipeFileRole::Base->value, RecipeFileRole::Iso->value], true);

        // `manual` means "the player supplies this by hand". Without it, a
        // required entry that names neither a file nor a URL is something the
        // validator refuses, so it is written as optional instead.
        $manual = $this->toBool($requirement['manual'] ?? null) === true;
        $out['required'] = $required && ($manual || $match !== [] || $url !== null);

        if ($manual) {
            $out['manual'] = true;
        }

        $size = $requirement['size_bytes'] ?? null;

        if (is_numeric($size) && (int) $size > 0) {
            $out['size_bytes'] = (int) $size;
        }

        $sha = mb_strtolower($this->text($requirement['sha256'] ?? null));

        if (preg_match('/^[a-f0-9]{64}$/', $sha) === 1) {
            $out['sha256'] = $sha;
        }

        if ($url !== null) {
            $out['url'] = $url;
        }

        $notes = $this->preserve($requirement['notes'] ?? null);

        if (trim($notes) !== '') {
            $out['notes'] = $notes;
        }

        $tags = $this->stringList($requirement['tags'] ?? null);

        if ($tags !== []) {
            $out['tags'] = $tags;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function steps(GameRecipe $recipe): array
    {
        $steps = [];

        $stored = (array) ($recipe->steps ?? []);

        foreach (array_slice($stored, 0, 20) as $index => $step) {
            $steps[] = $this->step($step, $index);
        }

        if ($steps === []) {
            // The schema insists on one step; a row without any is still
            // exportable, and the honest step is "there is nothing to run".
            $steps[] = [
                'id' => 'install',
                'title' => 'Install '.$this->title($recipe),
                'os' => 'any',
                'optional' => false,
                'detail' => 'Copy the game files into the target folder. This recipe has no detailed steps yet.',
            ];
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function step(array $step, int $index): array
    {
        $id = $this->text($step['id'] ?? null);

        if ($id === '' || mb_strlen($id) > 64 || preg_match(RecipeValidator::SLUG_PATTERN, $id) !== 1) {
            $id = 'step-'.($index + 1);
        }

        $title = $this->oneLine($this->text($step['title'] ?? null));
        $title = $title !== '' ? $this->limit($title, 160) : 'Step '.($index + 1);

        $os = $this->text($step['os'] ?? null);

        $detail = $this->preserve($step['detail'] ?? null);

        $out = [
            'id' => $id,
            'title' => $title,
            'os' => in_array($os, self::OS_VALUES, true) ? $os : 'any',
            'optional' => $this->toBool($step['optional'] ?? null) ?? false,
            'detail' => trim($detail) !== '' ? $detail : $title,
        ];

        $commands = [];

        foreach ((array) ($step['commands'] ?? []) as $command) {
            if (! is_array($command)) {
                continue;
            }

            // Keep a command byte-for-byte: a trailing newline in a script
            // block is part of what the author wrote.
            $run = $this->preserve($command['run'] ?? null);

            if (trim($run) === '') {
                continue;
            }

            $shell = $this->text($command['shell'] ?? null);

            $commands[] = [
                'shell' => in_array($shell, self::SHELLS, true) ? $shell : 'bash',
                'run' => $run,
            ];
        }

        if ($commands !== []) {
            $out['commands'] = $commands;
        }

        $verify = [];

        foreach ((array) ($step['verify'] ?? []) as $check) {
            if (! is_array($check)) {
                continue;
            }

            $type = $this->text($check['type'] ?? null);

            if (! in_array($type, self::VERIFY_TYPES, true)) {
                continue;
            }

            $entry = ['type' => $type];

            if ($type === 'command') {
                $command = $this->preserve($check['command'] ?? null);

                if (trim($command) === '') {
                    continue;
                }

                $entry['command'] = $command;
            } else {
                $path = $this->preserve($check['path'] ?? null);

                if (trim($path) === '') {
                    continue;
                }

                $entry['path'] = $path;
            }

            $checkOs = $this->text($check['os'] ?? null);

            if (in_array($checkOs, self::OS_VALUES, true)) {
                $entry['os'] = $checkOs;
            }

            $verify[] = $entry;
        }

        if ($verify !== []) {
            $out['verify'] = $verify;
        }

        return $out;
    }

    /**
     * Launch targets live at the top level in the file format; the parser
     * lifts them back into `metadata.launch_targets`.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    private function launchTargets(array $metadata): array
    {
        $targets = [];

        foreach ((array) ($metadata['launch_targets'] ?? []) as $index => $target) {
            if (! is_array($target)) {
                continue;
            }

            $command = $this->preserve($target['command'] ?? null);

            if (trim($command) === '') {
                $command = $this->commandFrom($target);
            }

            if ($command === null) {
                continue;
            }

            $id = $this->text($target['id'] ?? null);

            if ($id === '' || mb_strlen($id) > 64 || preg_match(RecipeValidator::SLUG_PATTERN, $id) !== 1) {
                $id = 'target-'.($index + 1);
            }

            $label = $this->oneLine($this->text($target['label'] ?? null));

            $os = $this->text($target['os'] ?? null);

            $entry = [
                'id' => $id,
                'label' => $label !== '' ? $this->limit($label, 160) : 'Start',
                'os' => in_array($os, self::OS_VALUES, true) ? $os : 'any',
            ];

            $shell = $this->text($target['shell'] ?? null);

            if (in_array($shell, self::SHELLS, true)) {
                $entry['shell'] = $shell;
            }

            $entry['command'] = $command;

            $notes = $this->preserve($target['notes'] ?? null);

            if (trim($notes) !== '') {
                $entry['notes'] = $notes;
            }

            $targets[] = $entry;
        }

        return $targets;
    }

    /** @param array<string, mixed> $target */
    private function commandFrom(array $target): ?string
    {
        $value = $this->text($target['target'] ?? null);

        if ($value === '') {
            return null;
        }

        $args = $target['args'] ?? null;
        $argLine = is_array($args)
            ? implode(' ', array_map(fn (mixed $arg): string => (string) $arg, $args))
            : (is_string($args) ? $args : '');

        return match ($target['kind'] ?? null) {
            'steam' => str_starts_with($value, 'steam://')
                ? $value
                : 'steam://rungameid/'.$value.($argLine !== '' ? '//'.$argLine : ''),
            'uri' => $value,
            default => $value.($argLine !== '' ? ' '.$argLine : ''),
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function metadata(array $metadata): array
    {
        $out = [
            'genre' => $this->text($metadata['genre'] ?? null) !== '' ? $this->text($metadata['genre']) : 'other',
            'players' => $this->text($metadata['players'] ?? null) !== '' ? $this->text($metadata['players']) : '1+',
        ];

        $playersMax = $metadata['players_max'] ?? null;

        if (is_numeric($playersMax) && (int) $playersMax >= 1 && (int) $playersMax <= 1024) {
            $out['players_max'] = (int) $playersMax;
        }

        $out['lan_recommended'] = $this->toBool($metadata['lan_recommended'] ?? null) ?? true;

        $liveUrl = $this->httpUrl($metadata['live_url'] ?? null);

        if ($liveUrl !== null) {
            $out['live_url'] = $liveUrl;
        }

        $server = $this->server($metadata['server'] ?? null);

        if ($server !== null) {
            $out['server'] = $server;
        }

        $out['tags'] = $this->stringList($metadata['tags'] ?? null);

        foreach (['multiplayer', 'portable', 'launcher_supported'] as $flag) {
            $value = $this->toBool($metadata[$flag] ?? null);

            if ($value !== null) {
                $out[$flag] = $value;
            }
        }

        $storeIds = $this->storeIds($metadata['store_ids'] ?? null);

        if ($storeIds !== []) {
            $out['store_ids'] = $storeIds;
        }

        $launch = $this->launch($metadata['launch'] ?? null);

        if ($launch !== null) {
            $out['launch'] = $launch;
        }

        return $out;
    }

    /**
     * Only the four known stores, each with a value of the shape the validator accepts: an
     * exported file must pass the same gate as a hand-written one.
     *
     * @return array<string, string>
     */
    private function storeIds(mixed $storeIds): array
    {
        if (! is_array($storeIds)) {
            return [];
        }

        $out = [];

        foreach (RecipeValidator::STORE_ID_PATTERNS as $store => $pattern) {
            $value = $storeIds[$store] ?? null;

            if (is_string($value) && preg_match($pattern, $value) === 1) {
                $out[$store] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array{name: string, address: string, port: int, connect_url: string, players?: string}|null
     */
    private function server(mixed $server): ?array
    {
        if (! is_array($server)) {
            return null;
        }

        $name = $this->oneLine($this->text($server['name'] ?? null));
        $address = $this->oneLine($this->text($server['address'] ?? null));
        $port = $server['port'] ?? null;
        $connect = $this->httpUrl($server['connect_url'] ?? null);

        if ($name === '' || $address === '' || ! is_numeric($port) || (int) $port < 1 || (int) $port > 65535 || $connect === null) {
            return null;
        }

        $out = [
            'name' => $name,
            'address' => $address,
            'port' => (int) $port,
            'connect_url' => $connect,
        ];

        $players = $this->oneLine($this->text($server['players'] ?? null));

        if ($players !== '') {
            $out['players'] = $players;
        }

        return $out;
    }

    /**
     * @return array{kind: string, target: string, args?: list<string>, notes?: string}|null
     */
    private function launch(mixed $launch): ?array
    {
        if (! is_array($launch)) {
            return null;
        }

        $kind = $this->text($launch['kind'] ?? null);
        $target = $this->text($launch['target'] ?? null);

        if (! in_array($kind, ['steam', 'uri', 'exe'], true) || trim($target) === '') {
            return null;
        }

        $out = ['kind' => $kind, 'target' => $target];

        $args = array_values(array_filter(
            array_map(fn (mixed $arg): string => (string) $arg, (array) ($launch['args'] ?? [])),
            fn (string $arg): bool => $arg !== '',
        ));

        if ($args !== []) {
            $out['args'] = $args;
        }

        $notes = $this->preserve($launch['notes'] ?? null);

        if (trim($notes) !== '') {
            $out['notes'] = $notes;
        }

        return $out;
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $list = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $list[] = trim($value);
            }
        }

        return array_values(array_unique($list));
    }

    private function httpUrl(mixed $value): ?string
    {
        $url = $this->text($value);

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : (is_numeric($value) ? (string) $value : '');
    }

    /** A string exactly as stored, for multi-line text that must round-trip. */
    private function preserve(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** A JSON/panel boolean, including the "true"/"false"/0/1 spellings. */
    private function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    private function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** Cut at a word boundary so a shortened summary never ends mid-word. */
    private function limit(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        $cut = mb_substr($value, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace >= (int) ($max * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        $cut = rtrim($cut, " \t,;:-");

        return $cut !== '' ? $cut : mb_substr($value, 0, $max);
    }

    /** Escape markdown table separators and keep every row on one line. */
    private function cell(string $value): string
    {
        return str_replace('|', '\\|', $this->oneLine($value));
    }
}
