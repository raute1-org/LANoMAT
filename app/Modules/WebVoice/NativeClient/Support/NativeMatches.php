<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\MyTournamentState;
use App\Modules\Tournaments\Support\PendingReportView;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;

/**
 * "My matches" for the native client: the viewer's own side of every match of an event's
 * visible tournaments, as one flat DTO each. Which entry is "mine", what I may do and which
 * voice room or server belongs to me come from the same helpers and policies the web pages
 * use ({@see MyTournamentState}); nothing about another team's match leaks.
 */
final class NativeMatches
{
    /** Finished matches stay in the list for this long. */
    private const FINISHED_VISIBLE_HOURS = 3;

    private const FINISHED_LIMIT = 5;

    public const PHASE_ACTIVE = 'active';

    public const PHASE_UPCOMING = 'upcoming';

    public const PHASE_FINISHED = 'finished';

    /**
     * @return list<array<string, mixed>>
     */
    public static function forEvent(User $user, Event $event): array
    {
        $entries = self::myEntries($user, $event);

        if ($entries->isEmpty()) {
            return [];
        }

        $matches = GameMatch::query()
            ->whereIn('tournament_id', $entries->pluck('tournament_id')->unique()->all())
            ->where(fn (Builder $query) => $query->whereIn('entry1_id', $entries->pluck('id')->all())->orWhereIn('entry2_id', $entries->pluck('id')->all()))
            ->with(['entry1.team', 'entry2.team', 'tournament.game', 'serverLink'])
            ->orderBy('round')
            ->orderBy('position')
            ->get();

        $rows = [];

        foreach ($matches as $match) {
            $mine = $entries->first(fn (TournamentEntry $entry): bool => $entry->id === $match->entry1_id || $entry->id === $match->entry2_id);
            $phase = $mine === null ? null : self::phase($match);

            if ($mine === null || $phase === null) {
                continue;
            }

            $rows[] = ['phase' => $phase, 'match' => $match, 'mine' => $mine];
        }

        $order = [self::PHASE_ACTIVE => 0, self::PHASE_UPCOMING => 1, self::PHASE_FINISHED => 2];

        usort($rows, function (array $a, array $b) use ($order): int {
            if ($a['phase'] !== $b['phase']) {
                return $order[$a['phase']] <=> $order[$b['phase']];
            }

            return match ($a['phase']) {
                self::PHASE_FINISHED => $b['match']->updated_at <=> $a['match']->updated_at,
                default => [self::urgency($a['match']), $a['match']->round, $a['match']->position] <=> [self::urgency($b['match']), $b['match']->round, $b['match']->position],
            };
        });

        $finished = 0;
        $result = [];

        foreach ($rows as $row) {
            if ($row['phase'] === self::PHASE_FINISHED && ++$finished > self::FINISHED_LIMIT) {
                continue;
            }

            $result[] = self::dto($user, $row['match'], $row['mine'], $row['phase']);
        }

        return $result;
    }

    /**
     * The viewer's matches of one event that are in play right now (ready, live, being reported), most urgent first.
     *
     * @return list<array<string, mixed>>
     */
    public static function active(User $user, Event $event): array
    {
        return array_values(array_filter(self::forEvent($user, $event), fn (array $row): bool => $row['phase'] === self::PHASE_ACTIVE));
    }

    /**
     * @return array<string, mixed>
     */
    public static function dto(User $user, GameMatch $match, TournamentEntry $mine, ?string $phase = null): array
    {
        $match->loadMissing(['entry1.team', 'entry2.team', 'tournament.game', 'serverLink']);
        $tournament = self::tournamentOf($match);
        $iAmEntry1 = $match->entry1_id === $mine->id;
        $opponent = $iAmEntry1 ? $match->entry2 : $match->entry1;
        $abilities = MyTournamentState::matchAbilities($user, $match);
        $hasScore = $match->score1 !== null && $match->score2 !== null;

        return [
            'id' => $match->id,
            'phase' => $phase ?? self::phase($match) ?? self::PHASE_FINISHED,
            'status' => $match->status->value,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'game' => $tournament->game?->name,
                'status' => $tournament->status->value,
            ],
            'round' => $match->round,
            'bracket' => $match->bracket,
            'scheduledAt' => $match->scheduled_at?->toIso8601String(),
            'warmupStartedAt' => $match->warmup_started_at?->toIso8601String(),
            'updatedAt' => $match->updated_at?->toIso8601String(),
            'me' => ['entryId' => $mine->id, 'name' => $mine->display_name],
            'opponent' => $opponent === null ? null : ['entryId' => $opponent->id, 'name' => $opponent->display_name],
            'score' => $hasScore ? [
                'own' => $iAmEntry1 ? $match->score1 : $match->score2,
                'opponent' => $iAmEntry1 ? $match->score2 : $match->score1,
            ] : null,
            'won' => $match->winner_entry_id === null ? null : $match->winner_entry_id === $mine->id,
            'lockVersion' => $match->lock_version,
            'pendingReport' => self::pendingReport($match, $mine),
            'voiceRoomId' => self::voiceRoomId($user, $match, $mine),
            'server' => self::server($match),
            'can' => $abilities + [
                'checkIn' => MyTournamentState::canCheckIn($user, $mine, $tournament),
            ],
        ];
    }

    /**
     * The entries the viewer is part of in the event's visible tournaments: owned ones and, for
     * display, the ones of a team they play in.
     *
     * @return EloquentCollection<int, TournamentEntry>
     */
    public static function myEntries(User $user, Event $event): EloquentCollection
    {
        return TournamentEntry::query()
            ->where('status', '!=', EntryStatus::Withdrawn->value)
            ->whereIn('tournament_id', Tournament::query()->where('event_id', $event->id)->visibleTo($user)->select('id'))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $owned) => $owned->ownedBy($user))
                ->orWhere(fn (Builder $rostered) => $rostered->rosteredBy($user)))
            ->with(['team', 'tournament'])
            ->get();
    }

    /** active (in play), upcoming (waiting for its turn) or finished (recently, with real opponents); null when it is not worth listing. */
    private static function phase(GameMatch $match): ?string
    {
        return match ($match->status) {
            MatchStatus::Ready, MatchStatus::Warmup, MatchStatus::Live, MatchStatus::Reported, MatchStatus::Disputed => self::PHASE_ACTIVE,
            MatchStatus::Pending => self::tournamentOf($match)->status === TournamentStatus::Finished ? null : self::PHASE_UPCOMING,
            MatchStatus::Completed => $match->entry1_id !== null && $match->entry2_id !== null
                && $match->updated_at !== null && $match->updated_at->gte(now()->subHours(self::FINISHED_VISIBLE_HOURS))
                ? self::PHASE_FINISHED : null,
        };
    }

    /** Matches that need the player first (a result to confirm, then ready, then live). */
    private static function urgency(GameMatch $match): int
    {
        return match ($match->status) {
            MatchStatus::Reported => 0,
            MatchStatus::Ready, MatchStatus::Warmup => 1,
            MatchStatus::Live => 2,
            MatchStatus::Disputed => 3,
            default => 4,
        };
    }

    /**
     * @return array{reportedByMe: bool, ownScore: int, opponentScore: int}|null
     */
    private static function pendingReport(GameMatch $match, TournamentEntry $mine): ?array
    {
        $row = PendingReportView::forViewer(collect([$match]), $mine)[$match->id] ?? null;

        return $row === null ? null : [
            'reportedByMe' => $row['reportedByMe'],
            'ownScore' => $row['ownScore'],
            'opponentScore' => $row['opponentScore'],
        ];
    }

    /** The viewer's own team room of this match, if it exists and the room policy lets them in. */
    private static function voiceRoomId(User $user, GameMatch $match, TournamentEntry $mine): ?int
    {
        $room = WebVoiceChannel::query()
            ->where('game_match_id', $match->id)
            ->where('tournament_entry_id', $mine->id)
            ->whereNull('archived_at')
            ->first();

        return $room !== null && Gate::forUser($user)->allows('view', $room) ? $room->id : null;
    }

    /**
     * The match's own server, in the same projection as the server list. The caller has already
     * established that the viewer plays in this match.
     *
     * @return array<string, mixed>|null
     */
    private static function server(GameMatch $match): ?array
    {
        $link = $match->serverLink;

        if ($link === null || ! app(Modules::class)->enabled(Module::GameServers)) {
            return null;
        }

        return NativeServerDto::from(ServerListProjection::dto($link) + ['description' => $link->description]);
    }

    private static function tournamentOf(GameMatch $match): Tournament
    {
        return $match->tournament ?? $match->tournament()->firstOrFail();
    }
}
