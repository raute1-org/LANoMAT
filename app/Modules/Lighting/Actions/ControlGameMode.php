<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Actions;

use App\Models\User;
use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Support\LightingResult;

/**
 * Starts or cancels the bridge's Game Mode effect. The start is refused by the
 * safety client unless strobe is allowed (or attested strobe-free).
 */
final readonly class ControlGameMode
{
    use GuardsLightingAction;

    public function __construct(private LightingClient $client) {}

    public function start(User $actor, ?int $durationSeconds = null, ?int $stepMs = null): LightingResult
    {
        $this->guard($actor);

        return $this->client->startGame(
            $durationSeconds === null ? null : max(1, min(600, $durationSeconds)),
            $stepMs === null ? null : max(50, min(5000, $stepMs)),
        );
    }

    public function cancel(User $actor): LightingResult
    {
        $this->guard($actor);

        return $this->client->cancelGame();
    }
}
