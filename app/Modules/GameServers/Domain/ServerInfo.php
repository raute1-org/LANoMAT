<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

/**
 * What a Source server answers to an A2S_INFO query: its browser name, map,
 * game directory (`folder`, e.g. garrysmod), game title (for Garry's Mod the
 * gamemode title) and player counts.
 */
final readonly class ServerInfo
{
    /**
     * @param  list<string>  $playerNames
     */
    public function __construct(
        public string $name,
        public string $map,
        public string $folder,
        public string $game,
        public int $players,
        public int $maxPlayers,
        public int $bots = 0,
        /** Player names the server volunteered (Minecraft's status sample); empty when it names nobody. */
        public array $playerNames = [],
    ) {}

    /**
     * @return array{name: string, map: string, folder: string, game: string, players: int, max_players: int, bots: int, player_names?: list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'map' => $this->map,
            'folder' => $this->folder,
            'game' => $this->game,
            'players' => $this->players,
            'max_players' => $this->maxPlayers,
            'bots' => $this->bots,
        ] + ($this->playerNames === [] ? [] : ['player_names' => $this->playerNames]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            map: (string) ($data['map'] ?? ''),
            folder: (string) ($data['folder'] ?? ''),
            game: (string) ($data['game'] ?? ''),
            players: (int) ($data['players'] ?? 0),
            maxPlayers: (int) ($data['max_players'] ?? 0),
            bots: (int) ($data['bots'] ?? 0),
            playerNames: is_array($data['player_names'] ?? null) ? array_values(array_filter($data['player_names'], 'is_string')) : [],
        );
    }
}
