<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Contracts;

use App\Modules\GameServers\Domain\PelicanServer;
use App\Modules\GameServers\Domain\PowerAction;

interface PelicanClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function createServer(string $eggId, array $config, ?string $nodeId = null): PelicanServer;

    public function getServer(string $serverId): PelicanServer;

    public function powerAction(string $serverId, PowerAction $action): void;

    public function deleteServer(string $serverId): void;
}
