<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Actions;

use App\Models\User;
use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Support\LightingResult;

final readonly class BlackoutLights
{
    use GuardsLightingAction;

    public function __construct(private LightingClient $client) {}

    public function __invoke(User $actor): LightingResult
    {
        $this->guard($actor);

        return $this->client->blackout();
    }
}
