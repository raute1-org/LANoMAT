<?php

namespace App\Modules\Infoscreen\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Exceptions\InfoscreenException;
use App\Modules\Infoscreen\Models\TombolaDraw;
use App\Modules\Infoscreen\Models\TombolaPrize;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Draws a single winner for a tombola prize: the eligible pool is every
 * checked-in registration of the event (`checked_in_at` set) that has not
 * already won a prize in this event (no existing {@see TombolaDraw} row for
 * that registration). Picked with `->inRandomOrder()->first()` — DB-side
 * randomness, so no client-observable seed/bias and no reliance on PHP's
 * `random_int`/`Math.random()` (unavailable in some contexts per the task
 * brief) — the test suite asserts pool membership + non-repetition, never a
 * specific winner.
 *
 * Locks the event row first (same lock-order convention as
 * StartTournament/EnrollSolo/ClosePoll) so two concurrent draws for the same
 * event can never race past the eligible-pool read and pick the same
 * registration twice.
 *
 * Broadcasts a synthetic `tombola` {@see SceneOverride} (mirrors
 * TriggerFoodReady/BroadcastWinnerMoment's synthetic-scene pattern) so the
 * draw reveals on the beamer without needing a pre-configured
 * `InfoscreenScene` row.
 */
class DrawTombola
{
    private const DURATION_SEC = 20;

    public function handle(Event $event, TombolaPrize $prize): TombolaDraw
    {
        return DB::transaction(function () use ($event, $prize): TombolaDraw {
            $event = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            $alreadyDrawnRegistrationIds = TombolaDraw::query()
                ->where('event_id', $event->id)
                ->pluck('registration_id');

            $winner = EventRegistration::query()
                ->where('event_id', $event->id)
                ->whereNotNull('checked_in_at')
                ->whereNotIn('id', $alreadyDrawnRegistrationIds)
                ->inRandomOrder()
                ->first();

            if ($winner === null) {
                throw InfoscreenException::noEligibleEntrants();
            }

            $draw = new TombolaDraw([
                'event_id' => $event->id,
                'tombola_prize_id' => $prize->id,
            ]);
            $draw->registration_id = $winner->id;
            $draw->drawn_at = Carbon::now();
            $draw->save();

            SceneOverride::dispatch($event->id, [
                'type' => SceneType::Tombola->value,
                'durationSec' => self::DURATION_SEC,
                'config' => [],
                'data' => $this->winnerData($prize, $winner),
            ]);

            return $draw;
        });
    }

    /**
     * @return array{prize: array{id: int, title: string}, winner: array{registrationId: int, name: string}}
     */
    private function winnerData(TombolaPrize $prize, EventRegistration $winner): array
    {
        $participant = $winner->user()->firstOrFail();

        return [
            'prize' => [
                'id' => $prize->id,
                'title' => $prize->title,
            ],
            'winner' => [
                'registrationId' => $winner->id,
                'name' => $participant->name,
            ],
        ];
    }
}
