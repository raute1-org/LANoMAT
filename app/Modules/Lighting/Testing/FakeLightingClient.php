<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Testing;

use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Support\BridgeHealth;
use App\Modules\Lighting\Support\FixtureReport;
use App\Modules\Lighting\Support\GameStatus;
use App\Modules\Lighting\Support\LightingResult;
use App\Modules\Lighting\Support\LightState;
use DateTimeImmutable;

/**
 * Records every command in {@see $calls} as [method, ...args]. Install it with
 * {@see install()}; it sits under the safety decorator, so recorded calls are
 * what would reach the bridge.
 */
final class FakeLightingClient implements LightingClient
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public bool $unreachable = false;

    public BridgeHealth $health;

    public ?FixtureReport $report = null;

    public function __construct()
    {
        $this->health = new BridgeHealth(true, true);
    }

    public function withTopology(int $fixtures, ?int $channels): self
    {
        $this->health = new BridgeHealth(true, true, '', [], $fixtures, $channels);

        return $this;
    }

    public static function install(): self
    {
        $fake = new self;
        app()->instance('lighting.transport', $fake);

        return $fake;
    }

    /**
     * @return list<array<int, mixed>>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $c) => $c[0] === $method));
    }

    public function health(): BridgeHealth
    {
        return $this->unreachable ? BridgeHealth::unreachable() : $this->health;
    }

    public function fixtures(): FixtureReport
    {
        if ($this->unreachable) {
            return FixtureReport::unreachable();
        }

        return $this->report ?? new FixtureReport(true, [], new DateTimeImmutable);
    }

    public function setFixture(int $id, LightState $state): LightingResult
    {
        return $this->record(['setFixture', $id, $state]);
    }

    public function setAll(LightState $state): LightingResult
    {
        return $this->record(['setAll', $state]);
    }

    public function setBulk(array $items): LightingResult
    {
        return $this->record(['setBulk', $items]);
    }

    public function pattern(string $a, string $b, float $brightness = 1.0): LightingResult
    {
        return $this->record(['pattern', $a, $b, $brightness]);
    }

    public function blackout(): LightingResult
    {
        return $this->record(['blackout']);
    }

    public ?GameStatus $game = null;

    public function gameStatus(): GameStatus
    {
        return $this->unreachable ? GameStatus::unreachable() : ($this->game ?? new GameStatus(true));
    }

    public function startGame(?int $durationSeconds = null, ?int $stepMs = null, ?string $mode = null): LightingResult
    {
        return $this->record(['startGame', $durationSeconds, $stepMs, $mode]);
    }

    public function cancelGame(): LightingResult
    {
        return $this->record(['cancelGame']);
    }

    /**
     * @param  array<int, mixed>  $call
     */
    private function record(array $call): LightingResult
    {
        $this->calls[] = $call;

        return $this->unreachable ? LightingResult::unreachable() : LightingResult::ok();
    }
}
