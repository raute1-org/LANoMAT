<?php

declare(strict_types=1);

namespace App\Modules\Games\Domain;

/**
 * A per-game, one-click server preset: a named, orga-authored
 * {@see ServerConfig} the orga can pick in "form mode" instead of uploading a
 * raw config file (see `App\Modules\GameServers\Support\EffectiveConfig` —
 * referenced by name only, not imported, to avoid a Games -> GameServers
 * module dependency; see CLAUDE.md's modular-monolith rule).
 *
 * Stored as a list of ServerPreset in Game::$server_presets (typed jsonb, see
 * ServerPresetsCast) — never as Filament's raw KeyValue map, for the same
 * "typed jsonb through Filament mangles types" reason as ServerConfig itself
 * (roadmap insight #9).
 */
final readonly class ServerPreset
{
    /**
     * @param  string|null  $eggUuid  Stable egg identity (numeric ids differ per panel); the
     *                                provisioning side resolves it through the panel's egg list.
     * @param  string|null  $eggName  Fallback match when no egg with that uuid is on the panel.
     * @param  bool  $enabled  Orga switch; a disabled preset cannot be started.
     * @param  list<string>  $requires  What the preset needs before it can run: `authkey`
     *                                  (Steam Web API key), `gslt` (Game Server Login Token),
     *                                  `content` (a content bundle). Secrets live in the
     *                                  environment config, never in the preset.
     */
    public function __construct(
        public string $key,
        public string $name,
        public ServerConfig $config,
        public ?string $description = null,
        public ?string $eggUuid = null,
        public ?string $eggName = null,
        public bool $enabled = true,
        public array $requires = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'config' => $this->config->toArray(),
            'description' => $this->description,
            'egg_uuid' => $this->eggUuid,
            'egg_name' => $this->eggName,
            'enabled' => $this->enabled,
            'requires' => $this->requires,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $config = $data['config'] ?? [];
        $requires = $data['requires'] ?? [];

        return new self(
            key: (string) ($data['key'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            config: is_array($config) ? ServerConfig::fromArray($config) : new ServerConfig,
            description: self::nullableString($data['description'] ?? null),
            eggUuid: self::nullableString($data['egg_uuid'] ?? null),
            eggName: self::nullableString($data['egg_name'] ?? null),
            enabled: (bool) ($data['enabled'] ?? true),
            requires: is_array($requires) ? array_values(array_map(static fn (mixed $r): string => (string) $r, array_filter($requires, 'is_scalar'))) : [],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
