<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

use App\Modules\GameServers\Enums\ServerState;

/**
 * Runtime state of a server from the Client API
 * (`GET /api/client/servers/{identifier}/resources`) — the only place the
 * real running/offline/starting/stopping state lives.
 */
final readonly class ServerStats
{
    public function __construct(
        public ServerState $state,
        public bool $suspended = false,
        public int $memoryBytes = 0,
        public float $cpuAbsolute = 0.0,
        public int $diskBytes = 0,
        public int $uptimeMs = 0,
    ) {}
}
