<?php

declare(strict_types=1);

namespace App\Modules\Games\Filament\Resources\Games;

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;

/**
 * Bridges the flat Filament repeater rows of the game form and the typed
 * {@see ServerPreset} (the Filament-only boundary, like the other Create/Edit
 * page marshalling). The environment is edited as `key / value / overridable`
 * rows and stored as `environment` (map) plus `overridable` (key list), which
 * keeps the stored shape the provisioning side already reads.
 */
final class PresetFormMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function toForm(ServerPreset $preset): array
    {
        $config = $preset->config;
        $environment = [];

        foreach ($config->environment as $key => $value) {
            $environment[] = [
                'key' => (string) $key,
                'value' => (string) $value,
                'overridable' => in_array((string) $key, $config->overridable, true),
            ];
        }

        return [
            'key' => $preset->key,
            'name' => $preset->name,
            'description' => $preset->description,
            'enabled' => $preset->enabled,
            'egg_uuid' => $preset->eggUuid,
            'egg_uuid_manual' => $preset->eggUuid,
            'egg_name' => $preset->eggName,
            'requires' => $preset->requires,
            'max_players' => $config->maxPlayers,
            'map' => $config->map,
            'password' => $config->password,
            'memory_mb' => $config->memoryMb,
            'disk_mb' => $config->diskMb,
            'cpu_percent' => $config->cpuPercent,
            'environment' => $environment,
            'extra_ports' => $config->extraPorts,
            'readiness_type' => $config->readinessType ?? 'none',
            'readiness_game' => $config->readinessGame,
            'readiness_folder' => $config->readinessFolder,
            'readiness_timeout' => $config->readinessTimeout,
            'connect_template' => $config->connectTemplate,
            'content_type' => $config->contentType ?? 'none',
            'content_ref' => $config->contentRef,
            'install_marker' => $config->installMarker,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromForm(array $row): ServerPreset
    {
        $environment = [];
        $overridable = [];

        foreach (is_array($row['environment'] ?? null) ? $row['environment'] : [] as $entry) {
            $key = trim((string) ($entry['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $environment[$key] = (string) ($entry['value'] ?? '');

            if ((bool) ($entry['overridable'] ?? false)) {
                $overridable[] = $key;
            }
        }

        $readiness = self::text($row['readiness_type'] ?? null);
        $content = self::text($row['content_type'] ?? null);

        return new ServerPreset(
            key: (string) ($row['key'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            config: new ServerConfig(
                maxPlayers: self::int($row['max_players'] ?? null),
                map: self::text($row['map'] ?? null),
                password: self::text($row['password'] ?? null),
                memoryMb: self::int($row['memory_mb'] ?? null),
                diskMb: self::int($row['disk_mb'] ?? null),
                cpuPercent: self::int($row['cpu_percent'] ?? null),
                environment: $environment,
                overridable: $overridable,
                extraPorts: array_values(array_filter(array_map(strval(...), is_array($row['extra_ports'] ?? null) ? $row['extra_ports'] : []), static fn (string $v): bool => $v !== '')),
                readinessType: $readiness === 'none' ? null : $readiness,
                readinessGame: self::text($row['readiness_game'] ?? null),
                readinessFolder: self::text($row['readiness_folder'] ?? null),
                readinessTimeout: self::int($row['readiness_timeout'] ?? null),
                connectTemplate: self::text($row['connect_template'] ?? null),
                contentType: $content === 'none' ? null : $content,
                contentRef: self::text($row['content_ref'] ?? null),
                installMarker: self::text($row['install_marker'] ?? null),
            ),
            description: self::text($row['description'] ?? null),
            eggUuid: self::text($row['egg_uuid'] ?? null) ?? self::text($row['egg_uuid_manual'] ?? null),
            eggName: self::text($row['egg_name'] ?? null),
            enabled: (bool) ($row['enabled'] ?? true),
            requires: array_values(array_map(strval(...), is_array($row['requires'] ?? null) ? $row['requires'] : [])),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
