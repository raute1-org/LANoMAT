<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Testing;

use App\Modules\GameServers\Contracts\ServerQuery;
use App\Modules\GameServers\Domain\ServerInfo;

/**
 * Test double for {@see ServerQuery}: answers from a queue of canned results
 * (null = no answer), repeating the last one, and records every query.
 */
class FakeServerQuery implements ServerQuery
{
    /** @var list<array{host: string, port: int}> */
    public array $queries = [];

    /** @var list<ServerInfo|null> */
    private array $answers = [];

    public function __construct(?ServerInfo ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function willAnswer(?ServerInfo ...$answers): self
    {
        $this->answers = array_values($answers);

        return $this;
    }

    public static function serverInfo(string $name = 'LANoMAT TTT', string $map = 'gm_construct', string $game = 'Trouble in Terrorist Town', int $players = 0, int $max = 16): ServerInfo
    {
        return new ServerInfo($name, $map, 'garrysmod', $game, $players, $max);
    }

    public function info(string $host, int $port): ?ServerInfo
    {
        $this->queries[] = ['host' => $host, 'port' => $port];

        if (count($this->answers) > 1) {
            return array_shift($this->answers);
        }

        return $this->answers[0] ?? null;
    }
}
