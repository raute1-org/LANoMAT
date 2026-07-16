<?php

namespace App\Modules\Infoscreen\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\DrawTombola;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Http\ScreenController;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Models\TombolaDraw;
use App\Modules\Infoscreen\Models\TombolaPrize;
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
 * `data` carries type-specific derived data: bracket/upcoming-matches read
 * `config.tournamentId` and project via {@see BracketMatchProjection}
 * (shared with the tournament show page); schedule and seatmap read the
 * scene's own event via {@see ScheduleProjection}/{@see SeatProjection}
 * (shared with the schedule/seating pages); payment-qr renders
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

    private static function eventFor(InfoscreenScene $scene): ?Event
    {
        return $scene->event ?? $scene->event()->first();
    }
}
