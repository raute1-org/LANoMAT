<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

use App\Modules\GameServers\Support\PlayerList;

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
        /**
         * Who is on the server (in-game nicknames, see PlayerList); empty when unknown.
         *
         * @var list<array{name: string, score: int|null, seconds: int|null}>
         */
        public array $playerList = [],
    ) {}

    /**
     * @param  list<array{name: string, score: int|null, seconds: int|null}>  $playerList
     */
    public function withPlayerList(array $playerList): self
    {
        return new self($this->name, $this->map, $this->folder, $this->game, $this->players, $this->maxPlayers, $this->bots, $this->playerNames, $playerList);
    }

    /**
     * @return array{name: string, map: string, folder: string, game: string, players: int, max_players: int, bots: int, player_names?: list<string>, player_list?: list<array{name: string, score: int|null, seconds: int|null}>}
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
        ] + ($this->playerNames === [] ? [] : ['player_names' => $this->playerNames])
            + ($this->playerList === [] ? [] : ['player_list' => $this->playerList]);
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
            playerList: is_array($data['player_list'] ?? null) ? PlayerList::normalize($data['player_list']) : [],
        );
    }
}
