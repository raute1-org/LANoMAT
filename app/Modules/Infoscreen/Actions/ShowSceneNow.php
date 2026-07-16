<?php

namespace App\Modules\Infoscreen\Actions;

use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Http\ScreenControlController;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;

/**
 * The single "show now" entry point shared by the Filament resource (orga)
 * and the helper-reachable control page ({@see ScreenControlController}) —
 * both funnel through this Action so the beamer-push behaviour can never
 * drift between the two surfaces. Also the trigger target reused by Tasks
 * 10 & 12.
 *
 * Deliberately does not guard against disabled scenes: an orga/helper may
 * want a one-off push of an otherwise-off scene (e.g. previewing a
 * not-yet-enabled announcement), so this Action stays minimal with no
 * throw path — there is accordingly no InfoscreenException (YAGNI; add one
 * if/when a real guard is needed).
 */
class ShowSceneNow
{
    public function handle(InfoscreenScene $scene, ?int $durationSec = null): void
    {
        $payload = ScenePayload::for($scene);

        if ($durationSec !== null) {
            $payload['durationSec'] = $durationSec;
        }

        event(new SceneOverride($scene->event_id, $payload));
    }
}
