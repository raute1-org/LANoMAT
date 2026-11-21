<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Testing;

use App\Modules\GameServers\Contracts\Ut2004Query;
use App\Modules\GameServers\Domain\ServerInfo;

/** Test double for {@see Ut2004Query}: a canned answer (null = no answer), every query recorded. */
class FakeUt2004Query implements Ut2004Query
{
    /** @var list<array{host: string, port: int}> */
    public array $queries = [];

    public function __construct(private ?ServerInfo $answer = null) {}

    public function willAnswer(?ServerInfo $answer): self
    {
        $this->answer = $answer;

        return $this;
    }

    /**
     * @param  list<array{name: string, score: int|null, seconds: int|null}>  $playerList
     */
    public static function serverInfo(string $name = 'Raute1 LAN', string $map = 'DM-DE-GRENDELKEEP', string $game = 'xDeathMatch', int $players = 0, int $max = 16, array $playerList = []): ServerInfo
    {
        return new ServerInfo($name, $map, 'ut2004', $game, $players, $max, playerList: $playerList);
    }

    public function info(string $host, int $gamePort): ?ServerInfo
    {
        $this->queries[] = ['host' => $host, 'port' => $gamePort];

        return $this->answer;
    }
}
