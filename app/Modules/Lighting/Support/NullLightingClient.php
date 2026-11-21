<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

use App\Modules\Lighting\Contracts\LightingClient;

/**
 * Used when the bridge is not configured or `LIGHTING_ENABLED` is off.
 */
final class NullLightingClient implements LightingClient
{
    public function health(): BridgeHealth
    {
        return BridgeHealth::unreachable('not configured');
    }

    public function fixtures(): FixtureReport
    {
        return FixtureReport::unreachable();
    }

    public function setFixture(int $id, LightState $state): LightingResult
    {
        return LightingResult::unreachable('not configured');
    }

    public function setAll(LightState $state): LightingResult
    {
        return LightingResult::unreachable('not configured');
    }

    public function setBulk(array $items): LightingResult
    {
        return LightingResult::unreachable('not configured');
    }

    public function pattern(string $a, string $b, float $brightness = 1.0): LightingResult
    {
        return LightingResult::unreachable('not configured');
    }

    public function blackout(): LightingResult
    {
        return LightingResult::unreachable('not configured');
    }

    public function gameStatus(): GameStatus
    {
        return GameStatus::unreachable();
    }

    public function startGame(?int $durationSeconds = null, ?int $stepMs = null, ?string $mode = null): LightingResult
    {
        return LightingResult::unreachable('not configured');
    }

    public function cancelGame(): LightingResult
    {
        return LightingResult::unreachable('not configured');
    }
}
