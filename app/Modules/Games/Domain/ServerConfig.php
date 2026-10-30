<?php

declare(strict_types=1);

namespace App\Modules\Games\Domain;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;

/**
 * The default game-server configuration preset for a Game.
 *
 * This is the typed answer to the "typed jsonb through Filament's raw
 * KeyValue mangles types" footgun (see roadmap insight #9, mirrored from
 * Catering's MenuOption and Infoscreen's SceneConfig): a preset is never
 * stored/edited as a loose string=>string map. `$extra` is an escape hatch
 * for provisioner-specific keys not yet promoted to a typed field — it is
 * reused as-is by the T9 preset work. `memoryMb`/`diskMb`/`cpuPercent` and
 * `environment` carry the Pelican resource limits and egg variables a real
 * server needs (see GameServers' ServerSpecBuilder). See ServerConfigCast for the DB
 * jsonb <-> ServerConfig bridge.
 */
final readonly class ServerConfig
{
    /**
     * @param  array<string, scalar>  $extra
     * @param  array<string, scalar>  $environment  Explicit Pelican egg variable
     *                                              overrides (`ENV_NAME => value`); win over the
     *                                              values derived from max_players/map/password.
     * @param  list<string>  $overridable  Environment keys the orga may override per server
     *                                     (all others are fixed by the preset).
     * @param  list<string>  $extraPorts  Env variable names that each get an extra allocation's
     *                                    port (e.g. `TV_PORT` for CS2 SourceTV); one pool allocation
     *                                    per entry besides the main one.
     * @param  string|null  $readinessType  `a2s` (Source query) or null/`none`.
     * @param  string|null  $contentType  `none`, `workshop` or `upload`.
     */
    public function __construct(
        public ?int $maxPlayers = null,
        public ?string $map = null,
        public ?string $password = null,
        public array $extra = [],
        public ?int $memoryMb = null,
        public ?int $diskMb = null,
        public ?int $cpuPercent = null,
        public array $environment = [],
        public array $overridable = [],
        public array $extraPorts = [],
        public ?string $readinessType = null,
        public ?string $readinessGame = null,
        public ?string $readinessFolder = null,
        public ?int $readinessTimeout = null,
        public ?string $connectTemplate = null,
        public ?string $contentType = null,
        public ?string $contentRef = null,
        public ?string $installMarker = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'max_players' => $this->maxPlayers,
            'map' => $this->map,
            'password' => $this->password,
            'extra' => $this->extra,
            'memory_mb' => $this->memoryMb,
            'disk_mb' => $this->diskMb,
            'cpu_percent' => $this->cpuPercent,
            'environment' => $this->environment,
            'overridable' => $this->overridable,
            'extra_ports' => $this->extraPorts,
            'readiness_type' => $this->readinessType,
            'readiness_game' => $this->readinessGame,
            'readiness_folder' => $this->readinessFolder,
            'readiness_timeout' => $this->readinessTimeout,
            'connect_template' => $this->connectTemplate,
            'content_type' => $this->contentType,
            'content_ref' => $this->contentRef,
            'install_marker' => $this->installMarker,
        ];

        return array_filter(
            $data,
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $maxPlayers = $data['max_players'] ?? null;
        $extra = $data['extra'] ?? [];
        $environment = $data['environment'] ?? [];

        return new self(
            maxPlayers: $maxPlayers !== null ? (int) $maxPlayers : null,
            map: $data['map'] ?? null,
            password: $data['password'] ?? null,
            extra: is_array($extra) ? $extra : [],
            memoryMb: isset($data['memory_mb']) ? (int) $data['memory_mb'] : null,
            diskMb: isset($data['disk_mb']) ? (int) $data['disk_mb'] : null,
            cpuPercent: isset($data['cpu_percent']) ? (int) $data['cpu_percent'] : null,
            environment: is_array($environment) ? $environment : [],
            overridable: self::stringList($data['overridable'] ?? []),
            extraPorts: self::stringList($data['extra_ports'] ?? []),
            readinessType: self::nullableString($data['readiness_type'] ?? null),
            readinessGame: self::nullableString($data['readiness_game'] ?? null),
            readinessFolder: self::nullableString($data['readiness_folder'] ?? null),
            readinessTimeout: isset($data['readiness_timeout']) ? (int) $data['readiness_timeout'] : null,
            connectTemplate: self::nullableString($data['connect_template'] ?? null),
            contentType: self::nullableString($data['content_type'] ?? null),
            contentRef: self::nullableString($data['content_ref'] ?? null),
            installMarker: self::nullableString($data['install_marker'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, array_filter($value, 'is_scalar')));
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function usesA2sReadiness(): bool
    {
        return $this->readinessType === 'a2s';
    }

    /**
     * Parses an uploaded server-config file off a Laravel Storage disk into
     * a {@see ServerConfig}. Single source of truth for both
     * `App\Modules\GameServers\Support\EffectiveConfig::resolve()` (upload
     * mode) and the Games Filament Create/Edit pages
     * (`CreateGame::extractConfig`/`EditGame`) — the two used to parse the
     * same upload independently and had silently diverged on error handling
     * (GameServers threw, Filament silently fell back to an empty config).
     * This method always throws on a missing file or invalid JSON; callers
     * that need a softer UX (e.g. a Filament form validation error instead
     * of an unhandled 500) must catch and translate at their boundary.
     *
     * @throws InvalidArgumentException if the file does not exist on the disk.
     * @throws JsonException if the file's contents are not valid JSON.
     */
    public static function fromStoragePath(string $path, string $disk = 'public'): self
    {
        $contents = Storage::disk($disk)->get($path);

        if ($contents === null) {
            throw new InvalidArgumentException("Uploaded server config not found at [{$path}].");
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Uploaded server config at [{$path}] is not valid JSON.");
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }
}
