<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Listeners;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Lighting\Enums\LightingTrigger;
use App\Modules\Lighting\Jobs\PlayLightingSceneJob;
use App\Modules\Lighting\Models\LightingRule;
use App\Modules\Lighting\Support\LightingAutomation;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\MatchWentLive;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Events\TournamentStarted;
use Throwable;

/**
 * Maps existing LANoMAT events to rules and queues the rule's scene. Rules are
 * off by default; each has a cooldown (claimed atomically, so concurrent events
 * fire at most once per window). Failures are swallowed: this runs inside
 * tournament flows and must never break them.
 */
class TriggerLightingRules
{
    public function handle(object $event): void
    {
        try {
            if (! LightingAutomation::active()) {
                return;
            }

            [$trigger, $eventId, $tournamentId] = $this->describe($event);

            if ($trigger === null) {
                return;
            }

            $rules = LightingRule::query()
                ->where('trigger', $trigger->value)
                ->where('active', true)
                ->get();

            foreach ($rules as $rule) {
                if ($rule->event_id !== null && $rule->event_id !== $eventId) {
                    continue;
                }

                if ($rule->tournament_id !== null && $rule->tournament_id !== $tournamentId) {
                    continue;
                }

                if (! $this->claim($rule)) {
                    continue;
                }

                PlayLightingSceneJob::dispatch($rule->lighting_scene_id);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{0: LightingTrigger|null, 1: int|null, 2: int|null}
     */
    private function describe(object $event): array
    {
        return match (true) {
            $event instanceof TournamentStarted => [LightingTrigger::TournamentStarted, $event->tournament->event_id, $event->tournament->id],
            $event instanceof TournamentCompleted => [LightingTrigger::TournamentCompleted, $event->tournament->event_id, $event->tournament->id],
            $event instanceof MatchReady => [LightingTrigger::MatchReady, $event->match->tournament?->event_id, $event->match->tournament_id],
            $event instanceof MatchWentLive => [LightingTrigger::MatchLive, $event->match->tournament?->event_id, $event->match->tournament_id],
            $event instanceof MatchCompleted => [LightingTrigger::MatchCompleted, $event->match->tournament?->event_id, $event->match->tournament_id],
            $event instanceof EventStatusChanged => [
                match ($event->to) {
                    EventStatus::Live => LightingTrigger::EventLive,
                    EventStatus::Finished => LightingTrigger::EventFinished,
                    default => null,
                },
                $event->event->id,
                null,
            ],
            default => [null, null, null],
        };
    }

    /**
     * Atomically claims the cooldown window: only one caller wins per window.
     */
    private function claim(LightingRule $rule): bool
    {
        $now = now();
        $threshold = $now->copy()->subSeconds($rule->cooldown_seconds);

        return LightingRule::query()
            ->whereKey($rule->id)
            ->where(fn ($q) => $q->whereNull('last_fired_at')->orWhere('last_fired_at', '<=', $threshold))
            ->update(['last_fired_at' => $now]) === 1;
    }
}
