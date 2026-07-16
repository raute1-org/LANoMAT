<?php

namespace App\Modules\Games\Domain;

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
}
