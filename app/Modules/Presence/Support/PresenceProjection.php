<?php

declare(strict_types=1);

namespace App\Modules\Presence\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Support\ScenePayload;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\SeatAssignment;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Support\EntryRoster;
use App\Modules\Voice\Jobs\ProvisionServerVoiceJob;
use Illuminate\Support\Collection;

/**
 * Pure, IO-free aggregator of check-in + seat + current-match activity per
 * participant, "free slots" (joinable tournaments), and live matches into a
 * {@see PresenceBoard} — the read model behind the presence view (see the
 * M10 roadmap plan). No HTTP, no broadcasting, no request state; every
 * cross-module fact is read through the module's own models/relations
 * ({@see EventRegistration}, {@see SeatAssignment},
 * {@see Tournament}, {@see GameMatch}, {@see EntryRoster}) — never a raw
 * cross-module table query.
 *
 * "Playing" mirrors {@see ScenePayload}'s
 * upcoming-matches read (`Ready`) extended with `Warmup` — both are
 * "currently at the table" once the tournament itself is `Live` — and the
 * match label reuses the same "{entry1} vs {entry2}" scheme
 * {@see ProvisionServerVoiceJob::channelNameFor()}
 * already uses for match voice channels (without its emoji, which is a
 * Discord/voice channel-naming concern, not a presence-board one).
 */
final class PresenceProjection
{
    public static function forEvent(Event $event): PresenceBoard
    {
        $registrations = self::checkedInRegistrations($event);
        $liveMatches = self::liveMatches($event);

        /** @var Collection<int, array{match: GameMatch, users: Collection<int, User>}> $liveMatchData */
        $liveMatchData = $liveMatches->map(fn (GameMatch $match): array => [
            'match' => $match,
            'users' => EntryRoster::usersForMatch($match),
        ]);

        // user_id -> ['match' => GameMatch, 'users' => Collection<User>]
        $playingIndex = [];
        foreach ($liveMatchData as $data) {
            foreach ($data['users'] as $user) {
                $playingIndex[$user->id] = $data;
            }
        }

        $participants = $registrations
            ->map(function (EventRegistration $registration) use ($playingIndex): ?ParticipantPresence {
                $user = $registration->user;

                if ($user === null) {
                    return null;
                }

                $playing = $playingIndex[$user->id] ?? null;

                return new ParticipantPresence(
                    registrationId: $registration->id,
                    name: $user->name,
                    avatarUrl: $user->avatar_url,
                    streamUrl: $user->stream_url,
                    seatLabel: $registration->seatAssignment?->seat?->label,
                    activity: $playing !== null ? self::activityLabel($playing['match']) : null,
                    isPlaying: $playing !== null,
                );
            })
            ->filter()
            ->sortBy('name')
            ->values()
            ->all();

        $freeSlots = self::freeSlots($event);

        $liveMatchPresences = $liveMatchData
            ->map(fn (array $data): LiveMatchPresence => new LiveMatchPresence(
                matchId: $data['match']->id,
                game: $data['match']->tournament?->game?->name,
                label: self::matchLabel($data['match']),
                players: array_values($data['users']->pluck('name')->all()),
            ))
            ->values()
            ->all();

        return new PresenceBoard(
            participants: array_values($participants),
            freeSlots: $freeSlots,
            liveMatches: array_values($liveMatchPresences),
            checkedInCount: $registrations->count(),
        );
    }

    /**
     * @return Collection<int, EventRegistration>
     */
    private static function checkedInRegistrations(Event $event): Collection
    {
        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', RegistrationStatus::Cancelled)
            ->whereNotNull('checked_in_at')
            ->with(['user', 'seatAssignment.seat'])
            ->get();
    }

    /**
     * Matches currently "at the table": `Warmup`/`Ready` in a `Live`
     * tournament of this event.
     *
     * @return Collection<int, GameMatch>
     */
    private static function liveMatches(Event $event): Collection
    {
        return GameMatch::query()
            ->whereIn('status', [MatchStatus::Warmup->value, MatchStatus::Ready->value])
            ->whereHas('tournament', function ($query) use ($event): void {
                $query->where('event_id', $event->id)
                    ->where('status', TournamentStatus::Live->value);
            })
            ->with(['entry1', 'entry2', 'tournament.game'])
            ->get();
    }

    /**
     * @return list<FreeSlot>
     */
    private static function freeSlots(Event $event): array
    {
        $tournaments = Tournament::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [TournamentStatus::Enrollment->value, TournamentStatus::CheckIn->value])
            ->withCount('entries')
            ->with('game')
            ->get();

        return array_values(
            $tournaments
                ->map(function (Tournament $tournament): ?FreeSlot {
                    $openSpots = $tournament->max_entries === null
                        ? null
                        : $tournament->max_entries - $tournament->entries_count;

                    if ($openSpots !== null && $openSpots <= 0) {
                        return null;
                    }

                    return new FreeSlot(
                        tournamentId: $tournament->id,
                        name: $tournament->name,
                        game: $tournament->game?->name,
                        openSpots: $openSpots,
                    );
                })
                ->filter()
                ->values()
                ->all()
        );
    }

    private static function activityLabel(GameMatch $match): string
    {
        $game = $match->tournament?->game;
        $gameName = $game === null ? '?' : $game->name;

        return "{$gameName} · ".self::matchLabel($match);
    }

    /**
     * "Ada vs Bob" when both entries are known, falling back to a plain
     * match label for the rare case only one side is set yet — mirrors
     * {@see ProvisionServerVoiceJob::channelNameFor()}
     * (without its Discord-channel emoji prefix).
     */
    private static function matchLabel(GameMatch $match): string
    {
        if ($match->entry1 !== null && $match->entry2 !== null) {
            return "{$match->entry1->display_name} vs {$match->entry2->display_name}";
        }

        return "Match #{$match->id}";
    }
}
