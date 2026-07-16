<?php

namespace App\Modules\Infoscreen\Support;

use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Http\ScreenController;
use App\Modules\Infoscreen\Models\InfoscreenScene;

/**
 * The single scene -> wire projection used by the public screen page
 * ({@see ScreenController}), the
 * {@see SceneOverride} broadcast, and later
 * per-type "data" producers (winner overlay, status board, ...). Keeping
 * this in one place means the controller and the override event can never
 * drift on shape.
 *
 * `data` carries type-specific derived data that this task does not yet
 * populate (e.g. the current bracket state for a Bracket scene) — Tasks
 * 5/6 fill it in per SceneType. It is `[]` for every type here, which is
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
            'data' => [],
        ];
    }
}
