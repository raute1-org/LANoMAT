<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Jobs;

use App\Modules\GameServers\Contracts\ServerQuery;
use App\Modules\GameServers\Domain\ServerInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The panel reports a server `running` as soon as the console prints its start
 * line, long before the map is loaded. For presets with a query check this job
 * asks the server itself (Source A2S_INFO via {@see ServerQuery}) every few
 * seconds: an answer that matches the preset's expectations (game title, game
 * folder) flips the link to Ready with name, map and players; silence for the
 * preset's timeout marks it NotResponding. Like the status poll it re-dispatches a
 * fresh instance per attempt.
 */
class CheckServerReadinessJob implements ShouldQueue
{
    use Queueable;

    public const int INTERVAL_SECONDS = 5;

    public function __construct(
        public readonly int $serverLinkId,
        public readonly int $attempt = 1,
    ) {}

    public function handle(ServerQuery $query): void
    {
        $link = ServerLink::query()->find($this->serverLinkId);

        if ($link === null || $link->status !== ServerLinkStatus::Provisioning) {
            return;
        }

        $address = $link->join_info->address;
        $port = $link->join_info->port;

        if ($address === null || $port === null) {
            return;
        }

        $plan = $link->readiness ?? [];
        $info = $query->info($address, $port);

        if ($info !== null && self::matches($info, $plan)) {
            $link->query_info = $info->toArray();
            $link->queried_at = now();
            $link->status = ServerLinkStatus::Ready;
            $link->save();

            ServerLinkUpdated::dispatch($link);

            return;
        }

        $timeout = is_numeric($plan['timeout'] ?? null) ? (int) $plan['timeout'] : (int) config('services.pelican.readiness_timeout', 180);
        $maxAttempts = max(1, intdiv($timeout, self::INTERVAL_SECONDS));

        if ($this->attempt >= $maxAttempts) {
            $link->status = ServerLinkStatus::NotResponding;
            $link->queried_at = now();
            $link->save();

            ServerLinkUpdated::dispatch($link);

            return;
        }

        self::dispatch($this->serverLinkId, $this->attempt + 1)->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }

    /**
     * Does the answer come from the game this preset starts (and not from a
     * different server that happens to sit on the port)?
     *
     * @param  array<string, mixed>  $plan
     */
    public static function matches(ServerInfo $info, array $plan): bool
    {
        $game = $plan['game'] ?? null;
        $folder = $plan['folder'] ?? null;

        if (is_string($game) && $game !== '' && ! str_contains(mb_strtolower($info->game), mb_strtolower($game))) {
            return false;
        }

        return ! (is_string($folder) && $folder !== '' && mb_strtolower($info->folder) !== mb_strtolower($folder));
    }
}
