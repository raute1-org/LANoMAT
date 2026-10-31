<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Testing;

use App\Modules\GameServers\Contracts\MinecraftQuery;
use App\Modules\GameServers\Domain\ServerInfo;

/** Test double for {@see MinecraftQuery}: a canned answer (null = no answer), every query recorded. */
class FakeMinecraftQuery implements MinecraftQuery
{
    /** @var list<array{host: string, port: int}> */
    public array $queries = [];

    public function __construct(private ?ServerInfo $answer = null) {}

    public function willAnswer(?ServerInfo $answer): self
    {
        $this->answer = $answer;

        return $this;
    }

    public static function serverInfo(string $motd = 'Dauerserver', string $version = 'Paper 1.21', int $players = 0, int $max = 20): ServerInfo
    {
        return new ServerInfo($motd, '', 'minecraft', $version, $players, $max);
    }

    public function info(string $host, int $port): ?ServerInfo
    {
        $this->queries[] = ['host' => $host, 'port' => $port];

        return $this->answer;
    }
}
