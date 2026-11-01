<?php

declare(strict_types=1);

namespace App\Modules\Presence\Domain;

/**
 * One of LANoMAT's own game servers with the player names it reported on its last query (often
 * none: a Source A2S_INFO answer carries a count only, Minecraft names a small sample).
 */
final readonly class ServerRoster
{
    /**
     * @param  list<string>  $playerNames
     */
    public function __construct(
        public int $linkId,
        public ?string $game,
        public ?string $title,
        public ?string $address,
        public ?int $port,
        public array $playerNames = [],
    ) {}

    public function endpoint(): ?string
    {
        return $this->address === null || $this->port === null ? null : $this->address.':'.$this->port;
    }
}
