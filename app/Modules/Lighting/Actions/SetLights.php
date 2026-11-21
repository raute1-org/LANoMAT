<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Actions;

use App\Models\User;
use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Support\Fixture;
use App\Modules\Lighting\Support\LightingResult;
use App\Modules\Lighting\Support\LightState;
use App\Modules\Lighting\Support\TopologyResolver;
use InvalidArgumentException;

/**
 * Sets one light (`$fixtureId` 1..4) or all lights (null).
 */
final readonly class SetLights
{
    use GuardsLightingAction;

    public function __construct(private LightingClient $client, private TopologyResolver $topology) {}

    public function __invoke(User $actor, ?int $fixtureId, LightState $state): LightingResult
    {
        $this->guard($actor);

        if ($fixtureId === null) {
            return $this->client->setAll($state);
        }

        if (! Fixture::validId($fixtureId) || ! $this->topology->resolve()->hasFixture($fixtureId)) {
            throw new InvalidArgumentException('Invalid fixture id.');
        }

        return $this->client->setFixture($fixtureId, $state);
    }
}
