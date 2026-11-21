<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Actions;

use App\Models\User;
use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Models\LightingSetting;
use App\Modules\Lighting\Support\LightState;
use Illuminate\Support\Facades\Gate;

/**
 * Lets orga override the deployment default for strobe at runtime. Locking it
 * also stops a strobe that is already running: a best-effort strobe-off command
 * and a Game Mode cancel (the safety client forces strobe to 0 once locked).
 */
final readonly class SetStrobePermission
{
    public function __construct(private LightingClient $client) {}

    public function __invoke(User $actor, bool $allowed): void
    {
        Gate::forUser($actor)->authorize('lighting.control');

        LightingSetting::current()->update(['strobe_allowed' => $allowed]);

        if (! $allowed) {
            $this->client->setAll(new LightState(strobe: 0));
            $this->client->cancelGame();
        }
    }
}
