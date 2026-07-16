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
 * reused as-is by the T9 preset work. See ServerConfigCast for the DB
 * jsonb <-> ServerConfig bridge.
 */
final readonly class ServerConfig
{
    /**
     * @param  array<string, scalar>  $extra
     */
    public function __construct(
        public ?int $maxPlayers = null,
        public ?string $map = null,
        public ?string $password = null,
        public array $extra = [],
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

        return new self(
            maxPlayers: $maxPlayers !== null ? (int) $maxPlayers : null,
            map: $data['map'] ?? null,
            password: $data['password'] ?? null,
            extra: is_array($extra) ? $extra : [],
        );
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
