<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Actions;

use App\Models\User;
use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Support\LightingResult;
use App\Modules\Lighting\Support\LightState;

final readonly class SetLightPattern
{
    use GuardsLightingAction;

    public function __construct(private LightingClient $client) {}

    public function __invoke(User $actor, string $a, string $b, float $brightness = 1.0): LightingResult
    {
        $this->guard($actor);

        // Validates (InvalidArgumentException on a bad colour) and normalises.
        $a = LightState::normalizeHex($a);
        $b = LightState::normalizeHex($b);

        if (! is_finite($brightness)) {
            throw new \InvalidArgumentException('brightness must be finite.');
        }

        return $this->client->pattern($a, $b, max(0.0, min(1.0, $brightness)));
    }
}
