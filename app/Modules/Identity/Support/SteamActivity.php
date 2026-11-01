<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * One person's current Steam game. `serverAddress` is Steam's `gameserverip` (`ip:port`) and only
 * set while they are on a server.
 */
final readonly class SteamActivity
{
    public function __construct(
        public string $gameId,
        public ?string $gameName,
        public ?string $serverAddress = null,
        public ?string $personaName = null,
    ) {}

    /**
     * @return array{gameId: string, gameName: ?string, serverAddress: ?string, personaName: ?string}
     */
    public function toArray(): array
    {
        return [
            'gameId' => $this->gameId,
            'gameName' => $this->gameName,
            'serverAddress' => $this->serverAddress,
            'personaName' => $this->personaName,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            gameId: (string) ($data['gameId'] ?? ''),
            gameName: isset($data['gameName']) ? (string) $data['gameName'] : null,
            serverAddress: isset($data['serverAddress']) ? (string) $data['serverAddress'] : null,
            personaName: isset($data['personaName']) ? (string) $data['personaName'] : null,
        );
    }
}
