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
    public function __construct(
        public string $key,
        public string $name,
        public ServerConfig $config,
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
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $config = $data['config'] ?? [];

        return new self(
            key: (string) ($data['key'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            config: is_array($config) ? ServerConfig::fromArray($config) : new ServerConfig,
        );
    }
}
