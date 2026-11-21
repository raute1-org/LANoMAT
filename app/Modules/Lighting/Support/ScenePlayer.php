<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Models\LightingScene;
use InvalidArgumentException;

/**
 * Plays a scene through the (safety-wrapped) LightingClient. No authorization
 * here: callers (manual action, job, console command) own that. Best-effort:
 * stops at the first unreachable answer, never throws on bridge trouble.
 */
final readonly class ScenePlayer
{
    public function __construct(private LightingClient $client, private TopologyResolver $topology) {}

    public function play(LightingScene $scene): LightingResult
    {
        if ($scene->blackout) {
            return $this->client->blackout();
        }

        $last = LightingResult::ok();

        $pattern = $scene->pattern;

        if (is_array($pattern) && isset($pattern['a'], $pattern['b']) && is_string($pattern['a']) && is_string($pattern['b'])) {
            $brightness = isset($pattern['brightness']) && is_numeric($pattern['brightness']) ? (float) $pattern['brightness'] : 1.0;

            try {
                $last = $this->client->pattern(LightState::normalizeHex($pattern['a']), LightState::normalizeHex($pattern['b']), $brightness);
            } catch (InvalidArgumentException) {
                return LightingResult::rejected('invalid pattern');
            }

            if (! $last->ok) {
                return $last;
            }
        }

        $states = is_array($scene->states) ? $scene->states : [];
        $topology = $this->topology->resolve();
        $all = isset($states['all']) && is_array($states['all']) ? $states['all'] : null;

        try {
            if ($all !== null) {
                $last = $this->client->setAll(LightState::fromArray($all));

                if (! $last->ok) {
                    return $last;
                }
            }

            $items = [];
            foreach ($states as $key => $state) {
                if ($key === 'all' || ! is_array($state) || ! is_numeric($key) || ! Fixture::validId((int) $key) || ! $topology->hasFixture((int) $key)) {
                    // Lights the bridge does not report are skipped silently.
                    continue;
                }
                $items[] = new FixtureUpdate((int) $key, LightState::fromArray($state));
            }

            if ($items !== []) {
                $last = $this->client->setBulk($items);
            }
        } catch (InvalidArgumentException) {
            return LightingResult::rejected('invalid scene state');
        }

        return $last;
    }
}
