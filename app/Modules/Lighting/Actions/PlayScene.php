<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Actions;

use App\Models\User;
use App\Modules\Lighting\Models\LightingScene;
use App\Modules\Lighting\Support\LightingResult;
use App\Modules\Lighting\Support\ScenePlayer;

final readonly class PlayScene
{
    use GuardsLightingAction;

    public function __construct(private ScenePlayer $player) {}

    public function __invoke(User $actor, LightingScene $scene): LightingResult
    {
        $this->guard($actor);

        return $this->player->play($scene);
    }
}
