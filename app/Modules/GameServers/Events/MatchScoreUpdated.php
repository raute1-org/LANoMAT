<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Events;

use App\Modules\GameServers\Http\MatchTelemetryController;
use App\Modules\GameServers\Support\Cs2TelemetryMapper;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by {@see MatchTelemetryController} whenever a CS2 game server
 * (MatchZy/G5API) reports a live round/score update for a match (roadmap
 * 6.9). Broadcasts the normalized score
 * ({@see Cs2TelemetryMapper}) on the
 * public `tournament.{id}` channel, mirroring
 * {@see MatchCompleted} — the bracket view
 * already reloads on any event on this channel
 * (`useTournamentChannel.ts`), so the match-page live score comes along for
 * free once this is wired into that listener list.
 *
 * `App\Modules\Infoscreen\Listeners\BroadcastScoreboardOnScoreUpdated`
 * bridges this onto the wider `event.{id}` channel as a synthetic
 * `scoreboard` {@see SceneOverride}, the same
 * bridge pattern as `BroadcastWinnerMoment`/`GongOnMatchLive`.
 *
 * Implements {@see ShouldDispatchAfterCommit} so listeners never observe an
 * update tied to a rolled-back transaction.
 */
class MatchScoreUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly GameMatch $match,
        public readonly string $team1,
        public readonly string $team2,
        public readonly int $score1,
        public readonly int $score2,
        public readonly int $round,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('tournament.'.$this->match->tournament_id);
    }

    /** @return array{tournament_id: int, match_id: int, team1: string, team2: string, score1: int, score2: int, round: int} */
    public function broadcastWith(): array
    {
        return [
            'tournament_id' => $this->match->tournament_id,
            'match_id' => $this->match->id,
            'team1' => $this->team1,
            'team2' => $this->team2,
            'score1' => $this->score1,
            'score2' => $this->score2,
            'round' => $this->round,
        ];
    }

    public function broadcastAs(): string
    {
        return 'match.score_updated';
    }
}
