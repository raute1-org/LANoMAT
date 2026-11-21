<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Jobs;

use App\Modules\Lighting\Models\LightingScene;
use App\Modules\Lighting\Support\LightingAutomation;
use App\Modules\Lighting\Support\ScenePlayer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Plays a scene triggered by a rule. No retries, errors are swallowed: a light
 * show must never break or delay anything else.
 */
class PlayLightingSceneJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $sceneId) {}

    public function handle(ScenePlayer $player): void
    {
        if (! LightingAutomation::active()) {
            return;
        }

        try {
            $scene = LightingScene::query()->find($this->sceneId);

            if ($scene !== null) {
                $player->play($scene);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function failed(?Throwable $e): void
    {
        // Intentionally silent.
    }
}
