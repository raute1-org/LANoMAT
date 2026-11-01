<?php

declare(strict_types=1);

namespace App\Modules\Identity\Testing;

use App\Modules\Identity\Contracts\SteamPresence;
use App\Modules\Identity\Support\SteamActivity;

/**
 * Scriptable {@see SteamPresence} for tests: nobody is known unless told so.
 */
class FakeSteamPresence implements SteamPresence
{
    /** @var list<list<string>> */
    public array $requests = [];

    /** @var array<string, SteamActivity|null> */
    private array $known = [];

    private bool $failing = false;

    public function playing(string $steamId, string $gameId, ?string $gameName = null, ?string $serverAddress = null, ?string $personaName = null): self
    {
        $this->known[$steamId] = new SteamActivity($gameId, $gameName, $serverAddress, $personaName);

        return $this;
    }

    public function idle(string $steamId): self
    {
        $this->known[$steamId] = null;

        return $this;
    }

    /** Every call comes back empty, like a Steam outage or a rate limit. */
    public function failing(bool $failing = true): self
    {
        $this->failing = $failing;

        return $this;
    }

    public function activities(array $steamIds): array
    {
        $this->requests[] = $steamIds;

        if ($this->failing) {
            return [];
        }

        return array_intersect_key($this->known, array_flip($steamIds));
    }
}
