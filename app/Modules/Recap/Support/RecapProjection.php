<?php

declare(strict_types=1);

namespace App\Modules\Recap\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Gallery\Support\GalleryQuery;
use App\Modules\Jukebox\Support\JukeboxStats;
use App\Modules\Presence\Support\PresenceProjection;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Support\Collection;

/**
 * Pure, IO-free aggregator of an event's post-LAN recap: activity counts,
 * tournament podiums, top gallery photos, and (eventually) the MVP into a
 * {@see RecapBoard} — the read model behind the public recap page (see the
 * M12 roadmap plan). No HTTP, no broadcasting, no request state; every
 * cross-module fact is read through the module's own models/read-models
 * ({@see GalleryQuery}, {@see JukeboxStats}, {@see Tournament}) — never a
 * raw cross-module table query, mirroring {@see PresenceProjection}'s
 * discipline.
 */
final class RecapProjection
{
    public static function forEvent(Event $event): RecapBoard
    {
        return new RecapBoard(
            participantCount: $event->registrations()->count(),
            tournamentCount: $event->tournaments()->count(),
            matchesPlayed: self::matchesPlayedCount($event),
            songsPlayed: (new JukeboxStats)->playedCount($event),
            podiums: self::podiums($event),
            topPhotos: self::topPhotos($event),
            mvp: self::resolveMvp($event),
        );
    }

    private static function matchesPlayedCount(Event $event): int
    {
        return GameMatch::query()
            ->where('status', MatchStatus::Completed)
            ->whereHas('tournament', function ($query) use ($event): void {
                $query->where('event_id', $event->id);
            })
            ->count();
    }

    /**
     * @return list<PodiumEntry>
     */
    private static function podiums(Event $event): array
    {
        $finishedTournaments = Tournament::query()
            ->where('event_id', $event->id)
            ->where('status', TournamentStatus::Finished)
            ->whereNotNull('winner_entry_id')
            ->with('winnerEntry')
            ->get();

        return array_values(
            $finishedTournaments
                ->map(function (Tournament $tournament): ?PodiumEntry {
                    $winner = $tournament->winnerEntry;

                    if ($winner === null) {
                        return null;
                    }

                    return new PodiumEntry(
                        tournamentName: $tournament->name,
                        winnerName: $winner->display_name,
                    );
                })
                ->filter()
                ->values()
                ->all()
        );
    }

    /**
     * @return list<RecapPhoto>
     */
    private static function topPhotos(Event $event): array
    {
        /** @var Collection<int, EventPhoto> $photos */
        $photos = (new GalleryQuery)->highlightsFor($event, 6);

        return array_values(
            $photos
                ->map(fn (EventPhoto $photo): RecapPhoto => new RecapPhoto(
                    url: route('gallery.photos.public.thumb', $photo),
                    caption: $photo->caption,
                ))
                ->values()
                ->all()
        );
    }

    private static function resolveMvp(Event $event): null
    {
        // Task 13 wires the closed MVP poll here, resolving to
        // ?array{name: string} once the closed-poll winner can be read.
        return null;
    }
}
