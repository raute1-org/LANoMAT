<?php

namespace App\Modules\Infoscreen\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Enums\StatusLevel;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Events\ScenesUpdated;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Models\StatusSignal;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The helper-reachable "set operations status" action backing the status
 * tile (internet/servers/voice): records a new {@see StatusSignal} row for
 * one component of the given event (append-only — see the model doc for why
 * "current" means "latest row"), then either pushes an outage reassurance
 * override onto the beamer (non-Ok level) or clears any active override by
 * asking the screen to reload its rotation (back to Ok).
 *
 * `component` is validated against {@see StatusSignal::COMPONENTS} here
 * rather than only in a FormRequest, because this action has more than one
 * entry point (the helper control endpoint today, potentially Filament or a
 * console command later) and must never persist an arbitrary string.
 */
class SetStatusSignal
{
    private const DURATION_SEC = 30;

    public function handle(Event $event, string $component, StatusLevel $level, ?string $message, User $actor): StatusSignal
    {
        Gate::forUser($actor)->authorize('setStatus', InfoscreenScene::class);

        if (! in_array($component, StatusSignal::COMPONENTS, true)) {
            throw new InvalidArgumentException("Unknown status component [{$component}].");
        }

        $signal = new StatusSignal([
            'event_id' => $event->id,
            'component' => $component,
            'level' => $level,
            'message' => $message,
        ]);
        $signal->save();

        if ($level !== StatusLevel::Ok) {
            SceneOverride::dispatch($event->id, [
                'type' => SceneType::Status->value,
                'durationSec' => self::DURATION_SEC,
                'config' => [],
                'data' => [
                    'component' => $component,
                    'level' => $level->value,
                    'message' => $message,
                ],
            ]);
        } else {
            ScenesUpdated::dispatch($event->id);
        }

        return $signal;
    }
}
