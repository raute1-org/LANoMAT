<?php

declare(strict_types=1);

namespace App\Modules\Voting\Actions;

use App\Models\User;
use App\Modules\Infoscreen\Actions\DrawTombola;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Listeners\BroadcastWinnerMoment;
use App\Modules\Voting\Exceptions\VotingException;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Support\MvpPollQuery;
use Illuminate\Support\Facades\Gate;

/**
 * Reveals the "player of the evening" poll's winner on the public beamer:
 * authorizes the orga actor (reusing the {@see Poll} `close` ability — the
 * same orga action that would have closed this poll), confirms `$poll` is
 * actually the event's closed MVP poll (via {@see MvpPollQuery}, so a stray
 * poll id can never trigger a reveal), then dispatches a synthetic
 * {@see SceneType::Winner} {@see SceneOverride} — mirroring
 * {@see DrawTombola}'s and
 * {@see BroadcastWinnerMoment}'s synthetic-
 * scene pattern, and reusing `SceneWinner.vue`'s existing `data.winner`
 * shape exactly (no `tournament` subtitle for an MVP reveal), plus an
 * MVP-specific `data.title` override (`polls.mvp.reveal_title`) so the
 * beamer reads "Spieler:in des Abends" instead of `SceneWinner.vue`'s
 * default tournament-winner heading.
 *
 * The scene payload carries only the already-public winning option's label
 * — never `subject_user_id`/the winner's user id — so the beamer (an
 * unauthenticated public channel) never receives PII beyond what the poll
 * page already showed every voter.
 */
class RevealMvp
{
    private const DURATION_SEC = 20;

    public function handle(Poll $poll, User $actor): void
    {
        Gate::forUser($actor)->authorize('close', $poll);

        $event = $poll->event()->firstOrFail();
        $closedMvpPoll = MvpPollQuery::closedFor($event);

        if ($closedMvpPoll === null || $closedMvpPoll->id !== $poll->id) {
            throw VotingException::notClosedMvpPoll();
        }

        $winner = MvpPollQuery::winner($poll);

        if ($winner === null) {
            throw VotingException::noVotesCast();
        }

        SceneOverride::dispatch($poll->event_id, [
            'type' => SceneType::Winner->value,
            'durationSec' => self::DURATION_SEC,
            'config' => [],
            'data' => [
                'title' => trans('polls.mvp.reveal_title'),
                'winner' => $winner->label,
            ],
        ]);
    }
}
