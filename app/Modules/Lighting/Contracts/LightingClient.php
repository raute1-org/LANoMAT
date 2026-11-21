<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Contracts;

use App\Modules\Lighting\Support\BridgeHealth;
use App\Modules\Lighting\Support\FixtureReport;
use App\Modules\Lighting\Support\FixtureUpdate;
use App\Modules\Lighting\Support\LightingResult;
use App\Modules\Lighting\Support\LightState;

/**
 * The venue's DMX light bridge. Implementations never throw: a bridge that is
 * not configured, off or unreachable yields an "unreachable" result.
 */
interface LightingClient
{
    public function health(): BridgeHealth;

    public function fixtures(): FixtureReport;

    public function setFixture(int $id, LightState $state): LightingResult;

    public function setAll(LightState $state): LightingResult;

    /**
     * @param  list<FixtureUpdate>  $items
     */
    public function setBulk(array $items): LightingResult;

    /** Lights 1 and 3 show `a`, 2 and 4 show `b`. */
    public function pattern(string $a, string $b, float $brightness = 1.0): LightingResult;

    public function blackout(): LightingResult;

    public function startGame(?int $durationSeconds = null, ?int $stepMs = null): LightingResult;

    public function cancelGame(): LightingResult;
}
