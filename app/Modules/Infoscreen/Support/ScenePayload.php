<?php

namespace App\Modules\Infoscreen\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Gallery\Support\GalleryQuery;
use App\Modules\GameServers\Support\ServerListProjection;
use App\Modules\Infoscreen\Actions\DrawTombola;
use App\Modules\Infoscreen\Actions\SetStatusSignal;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Http\ScreenController;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Models\StatusSignal;
use App\Modules\Infoscreen\Models\TombolaDraw;
use App\Modules\Infoscreen\Models\TombolaPrize;
use App\Modules\Jukebox\Models\JukeboxItem;
use App\Modules\Jukebox\Support\JukeboxQueue;
use App\Modules\Presence\Support\PresenceProjection;
use App\Modules\Recap\Support\RecapProjection;
use App\Modules\Registration\Support\QrCode;
use App\Modules\Schedule\Support\ScheduleProjection;
use App\Modules\Seating\Support\SeatProjection;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Support\BracketMatchProjection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The single scene -> wire projection used by the public screen page
 * ({@see ScreenController}), the
 * {@see SceneOverride} broadcast, and the
 * per-type "data" producers below (winner overlay, status board, ... are
 * still TODO for a later task). Keeping this in one place means the
 * controller and the override event can never drift on shape.
 *
 * `Scoreboard` (roadmap 6.9) is, like `Winner`/`Gong`, synthetic and
 * override-only: it never has an `InfoscreenScene` row, so `dataFor()`
 * never needs a match arm for it — its payload is built directly by
 * `App\Modules\Infoscreen\Listeners\BroadcastScoreboardOnScoreUpdated` and
 * pushed via `SceneOverride`.
 *
 * `data` carries type-specific derived data: bracket/upcoming-matches read
 * `config.tournamentId` and project via {@see BracketMatchProjection}
 * (shared with the tournament show page); schedule, seatmap and servers
 * read the scene's own event via {@see ScheduleProjection}/{@see SeatProjection}/{@see ServerListProjection}
 * (shared with the schedule/seating/server-list pages); presence reads the
 * scene's event via {@see PresenceProjection} and returns only the
 * headcount/live-matches/free-slots subset (the beamer glanceable set, not
 * the full participant roster); now-playing reads the scene's event via
 * {@see JukeboxQueue} (the Jukebox module's read-model, never a raw
 * `jukebox_items` query from here) and returns only public track metadata
 * (title/artist/imageUrl) — never the vote counts or adder's name the
 * participant queue view shows; gallery reads the scene's event via
 * {@see GalleryQuery::approvedFor()} (the Gallery module's own read-model,
 * never a raw `event_photos` query from here) and returns only a public
 * photo url (the new public-serving route, never the auth-gated
 * `gallery.photos.show`) + caption — no uploader name, no ids, no
 * visibility; recap reads the scene's event via {@see RecapProjection}
 * (the Recap module's own read-model, shared verbatim with the public
 * `/events/{event}/recap` page) and passes its already-public board through
 * unchanged; payment-qr renders
 * `config.qrPayload` via the content-agnostic {@see QrCode} (no `qrSvg` key
 * when the payload is empty/unset); sponsors resolves `config.sponsorLogoPaths`
 * to public storage URLs. Every other scene type still gets `[]`, which is
 * also the correct final value for Announcement (it needs none).
 */
final class ScenePayload
{
    /**
     * @return array{id: int, type: string, durationSec: int, config: array<string, mixed>, data: array<string, mixed>}
     */
    public static function for(InfoscreenScene $scene): array
    {
        return [
            'id' => $scene->id,
            'type' => $scene->type->value,
            'durationSec' => $scene->duration_sec,
            'config' => $scene->config->toArray(),
            'data' => self::dataFor($scene),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function dataFor(InfoscreenScene $scene): array
    {
        return match ($scene->type) {
            SceneType::Bracket => self::bracketData($scene),
            SceneType::UpcomingMatches => self::upcomingMatchesData($scene),
            SceneType::Schedule => self::scheduleData($scene),
            SceneType::Seatmap => self::seatmapData($scene),
            SceneType::PaymentQr => self::paymentQrData($scene),
            SceneType::Sponsors => self::sponsorsData($scene),
            SceneType::Tombola => self::tombolaData($scene),
            SceneType::Status => self::statusData($scene),
            SceneType::Servers => self::serversData($scene),
            SceneType::Presence => self::presenceData($scene),
            SceneType::NowPlaying => self::nowPlayingData($scene),
            SceneType::Gallery => self::galleryData($scene),
            SceneType::Recap => self::recapData($scene),
            default => [],
        };
    }

    /**
     * @return array{matches: list<array<string, mixed>>}
     */
    private static function bracketData(InfoscreenScene $scene): array
    {
        $tournamentId = $scene->config->tournamentId;

        if ($tournamentId === null) {
            return ['matches' => []];
        }

        return ['matches' => BracketMatchProjection::forTournament($tournamentId)];
    }

    /**
     * @return array{matches: list<array<string, mixed>>}
     */
    private static function upcomingMatchesData(InfoscreenScene $scene): array
    {
        $tournamentId = $scene->config->tournamentId;

        if ($tournamentId === null) {
            return ['matches' => []];
        }

        $matches = GameMatch::query()
            ->where('tournament_id', $tournamentId)
            ->where('status', MatchStatus::Ready->value)
            ->with(['entry1', 'entry2'])
            ->orderBy('round')
            ->orderBy('position')
            ->get()
            ->map(fn (GameMatch $match): array => BracketMatchProjection::fromMatch($match))
            ->all();

        return ['matches' => array_values($matches)];
    }

    /**
     * @return array{items: list<array<string, mixed>>, now: array<string, mixed>|null, next: array<string, mixed>|null}
     */
    private static function scheduleData(InfoscreenScene $scene): array
    {
        $event = self::eventFor($scene);

        if ($event === null) {
            return ['items' => [], 'now' => null, 'next' => null];
        }

        $items = ScheduleProjection::itemsFor($event);
        $now = Carbon::now();

        return [
            'items' => ScheduleProjection::itemDtos($items),
            'now' => ScheduleProjection::currentItem($items, $now),
            'next' => ScheduleProjection::nextItem($items, $now),
        ];
    }

    /**
     * @return array{seats: list<array<string, mixed>>}
     */
    private static function seatmapData(InfoscreenScene $scene): array
    {
        $event = self::eventFor($scene);

        if ($event === null) {
            return ['seats' => []];
        }

        return ['seats' => SeatProjection::forEvent($event)];
    }

    /**
     * @return array{servers: list<array<string, mixed>>}
     */
    private static function serversData(InfoscreenScene $scene): array
    {
        $event = self::eventFor($scene);

        if ($event === null) {
            return ['servers' => []];
        }

        return ['servers' => ServerListProjection::forEvent($event)];
    }

    /**
     * The rotation-configured presence scene shows the smallest glanceable
     * subset of the full {@see PresenceProjection} board: the checked-in
     * headcount, who's currently playing, and which tournaments still have
     * open slots. The full per-participant roster (name/seat/avatar) is
     * deliberately left out — a beamer glanced at across a room needs the
     * headline numbers, not a scrollable participant list (that stays the
     * job of the `Presence/Index.vue` participant page).
     *
     * @return array{checkedInCount: int, liveMatches: list<array<string, mixed>>, freeSlots: list<array<string, mixed>>}
     */
    private static function presenceData(InfoscreenScene $scene): array
    {
        $event = self::eventFor($scene);

        if ($event === null) {
            return ['checkedInCount' => 0, 'liveMatches' => [], 'freeSlots' => []];
        }

        $board = PresenceProjection::forEvent($event)->toArray();

        return [
            'checkedInCount' => $board['checkedInCount'],
            'liveMatches' => $board['liveMatches'],
            'freeSlots' => $board['freeSlots'],
        ];
    }

    /**
     * The rotation-configured now-playing scene shows the Jukebox's current
     * track plus a short "up next" preview, both via {@see JukeboxQueue} (the
     * Jukebox module's own read-model — this never queries `jukebox_items`
     * directly, keeping the module boundary the same way `presenceData`
     * stays behind {@see PresenceProjection}). Only public track metadata is
     * exposed: no vote counts, no adder name, no user ids — this is a public,
     * unauthenticated beamer surface.
     *
     * @return array{track: array{title: string, artist: string|null, imageUrl: string|null}|null, upNext: list<array{title: string, artist: string|null, imageUrl: string|null}>}
     */
    private static function nowPlayingData(InfoscreenScene $scene): array
    {
        $event = self::eventFor($scene);

        if ($event === null) {
            return ['track' => null, 'upNext' => []];
        }

        $queue = app(JukeboxQueue::class);
        $current = $queue->current($event);
        $upNext = $queue->upcoming($event)->take(5);

        return [
            'track' => $current === null ? null : self::trackMetadata($current),
            'upNext' => array_values($upNext->map(fn (JukeboxItem $item): array => self::trackMetadata($item))->all()),
        ];
    }

    /**
     * @return array{title: string, artist: string|null, imageUrl: string|null}
     */
    private static function trackMetadata(JukeboxItem $item): array
    {
        return [
            'title' => $item->title,
            'artist' => $item->artist,
            'imageUrl' => $item->image_url,
        ];
    }

    /**
     * The rotation-configured gallery scene shows a slideshow of the
     * event's approved photos via {@see GalleryQuery::approvedFor()} (the
     * Gallery module's own read-model — this never queries `event_photos`
     * directly, keeping the module boundary the same way `nowPlayingData`
     * stays behind {@see JukeboxQueue}). Only a public photo url (the
     * `gallery.photos.public.show` route, never the auth-gated
     * `gallery.photos.show` a participant browser session uses) and its
     * caption are exposed — no uploader name, no ids, no visibility. This is
     * a public, unauthenticated beamer surface.
     *
     * @return array{photos: list<array{url: string, caption: string|null}>}
     */
    private static function galleryData(InfoscreenScene $scene): array
    {
        $event = self::eventFor($scene);

        if ($event === null) {
            return ['photos' => []];
        }

        $photos = app(GalleryQuery::class)->approvedFor($event)
            ->map(fn (EventPhoto $photo): array => [
                'url' => route('gallery.photos.public.show', $photo),
                'caption' => $photo->caption,
            ])
            ->all();

        return ['photos' => array_values($photos)];
    }

    /**
     * The rotation-configured recap scene shows the same post-LAN board as
     * the public `/events/{event}/recap` page, via {@see RecapProjection}
     * (the Recap module's own pure, IO-free read-model — this never queries
     * tournament/gallery/jukebox tables directly, keeping the module
     * boundary the same way `presenceData`/`nowPlayingData`/`galleryData`
     * stay behind their own projections). `RecapProjection` is itself
     * already public/no-PII (display names + the public photo-thumb route
     * only), so this arm passes its `toArray()` through unchanged — no
     * additional fields are added here. Recap is post-event static data
     * (unlike now-playing/gallery/presence there is no live update to react
     * to), so it needs no dedicated broadcast — the existing scene
     * rotation/`.scenes.updated` reload already covers it.
     *
     * @return array{participantCount: int, tournamentCount: int, matchesPlayed: int, songsPlayed: int|null, podiums: list<array<string, mixed>>, topPhotos: list<array<string, mixed>>, mvp: array{name: string}|null}
     */
    private static function recapData(InfoscreenScene $scene): array
    {
        $event = self::eventFor($scene);

        if ($event === null) {
            return [
                'participantCount' => 0,
                'tournamentCount' => 0,
                'matchesPlayed' => 0,
                'songsPlayed' => null,
                'podiums' => [],
                'topPhotos' => [],
                'mvp' => null,
            ];
        }

        return RecapProjection::forEvent($event)->toArray();
    }

    /**
     * @return array{qrSvg?: string, caption: string|null}
     */
    private static function paymentQrData(InfoscreenScene $scene): array
    {
        $payload = $scene->config->qrPayload;
        $data = ['caption' => $scene->config->qrCaption];

        if ($payload !== null && $payload !== '') {
            $data['qrSvg'] = app(QrCode::class)->svg($payload);
        }

        return $data;
    }

    /**
     * @return array{logos: list<string>}
     */
    private static function sponsorsData(InfoscreenScene $scene): array
    {
        $logos = array_map(
            static fn (string $path): string => Storage::disk('public')->url($path),
            $scene->config->sponsorLogoPaths,
        );

        return ['logos' => $logos];
    }

    /**
     * The rotation-configured tombola scene shows the prize board: all
     * prizes with their drawn/undrawn state plus the most recent draw's
     * winner (so a viewer tuning in mid-rotation still sees who just won).
     * The reveal moment itself is pushed separately by {@see DrawTombola}
     * as a `SceneOverride` carrying only the just-drawn prize + winner.
     *
     * @return array{prizes: list<array{id: int, title: string, winner: string|null}>, lastDraw: array{prize: array{id: int|null, title: string|null}, winner: array{registrationId: int, name: string|null}}|null}
     */
    private static function tombolaData(InfoscreenScene $scene): array
    {
        $event = self::eventFor($scene);

        if ($event === null) {
            return ['prizes' => [], 'lastDraw' => null];
        }

        $draws = TombolaDraw::query()
            ->where('event_id', $event->id)
            ->with(['registration.user', 'prize'])
            ->get()
            ->keyBy('tombola_prize_id');

        $prizes = TombolaPrize::query()
            ->where('event_id', $event->id)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(function (TombolaPrize $prize) use ($draws): array {
                /** @var TombolaDraw|null $draw */
                $draw = $draws->get($prize->id);

                return [
                    'id' => $prize->id,
                    'title' => $prize->title,
                    'winner' => $draw?->registration?->user?->name,
                ];
            })
            ->all();

        $prizes = array_values($prizes);

        /** @var TombolaDraw|null $lastDraw */
        $lastDraw = $draws->sortByDesc('drawn_at')->first();

        return [
            'prizes' => $prizes,
            'lastDraw' => $lastDraw === null ? null : [
                'prize' => [
                    'id' => $lastDraw->prize?->id,
                    'title' => $lastDraw->prize?->title,
                ],
                'winner' => [
                    'registrationId' => $lastDraw->registration_id,
                    'name' => $lastDraw->registration?->user?->name,
                ],
            ],
        ];
    }

    /**
     * The rotation-configured status scene shows every component's current
     * signal (latest {@see StatusSignal} row per component — see that
     * model's doc on why "current" means "latest"). The outage-moment push
     * itself is a separate synthetic `status` {@see SceneOverride} carrying
     * only the just-changed component, dispatched by
     * {@see SetStatusSignal}.
     *
     * @return array{signals: list<array{component: string, level: string, message: string|null}>}
     */
    private static function statusData(InfoscreenScene $scene): array
    {
        $event = self::eventFor($scene);

        if ($event === null) {
            return ['signals' => []];
        }

        $signals = StatusSignal::query()
            ->where('event_id', $event->id)
            ->currentPerComponent()
            ->get()
            ->map(fn (StatusSignal $signal): array => [
                'component' => $signal->component,
                'level' => $signal->level->value,
                'message' => $signal->message,
            ])
            ->all();

        return ['signals' => array_values($signals)];
    }

    private static function eventFor(InfoscreenScene $scene): ?Event
    {
        return $scene->event ?? $scene->event()->first();
    }
}
