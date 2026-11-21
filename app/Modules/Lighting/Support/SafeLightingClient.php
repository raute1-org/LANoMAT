<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Models\LightingSetting;
use Closure;

/**
 * The single safety chokepoint (photosensitivity). Every command to the bridge
 * passes through here, whether it comes from the admin page, a scene, a rule or
 * an Artisan command:
 *
 *  - Strobe locked (default): every strobe value is forced to 0, and a pattern
 *    first clears any strobe the bridge may still be running.
 *  - Strobe allowed: the speed is capped at `lighting.max_strobe`.
 *  - Game Mode only starts when strobe is allowed or the operator attested that
 *    the effect does not strobe (`lighting.game_mode_strobe_free`).
 */
final class SafeLightingClient implements LightingClient
{
    /**
     * @param  Closure(): bool|null  $strobeAllowed
     */
    public function __construct(
        private readonly LightingClient $inner,
        private readonly ?Closure $strobeAllowed = null,
        private readonly ?int $maxStrobe = null,
        private readonly ?bool $gameStrobeFree = null,
    ) {}

    public function health(): BridgeHealth
    {
        return $this->inner->health();
    }

    public function fixtures(): FixtureReport
    {
        return $this->inner->fixtures();
    }

    public function setFixture(int $id, LightState $state): LightingResult
    {
        return $this->inner->setFixture($id, $this->safe($state));
    }

    public function setAll(LightState $state): LightingResult
    {
        return $this->inner->setAll($this->safe($state));
    }

    public function setBulk(array $items): LightingResult
    {
        return $this->inner->setBulk(array_map(
            fn (FixtureUpdate $item) => new FixtureUpdate($item->id, $this->safe($item->state)),
            $items,
        ));
    }

    public function pattern(string $a, string $b, float $brightness = 1.0): LightingResult
    {
        if (! $this->allowed()) {
            $this->inner->setAll(new LightState(strobe: 0));
        }

        return $this->inner->pattern($a, $b, $brightness);
    }

    public function blackout(): LightingResult
    {
        return $this->inner->blackout();
    }

    public function startGame(?int $durationSeconds = null, ?int $stepMs = null): LightingResult
    {
        if (! $this->allowed() && ! ($this->gameStrobeFree ?? (bool) config('lighting.game_mode_strobe_free'))) {
            return LightingResult::blocked(__('lighting.errors.game_blocked'));
        }

        return $this->inner->startGame($durationSeconds, $stepMs);
    }

    public function cancelGame(): LightingResult
    {
        return $this->inner->cancelGame();
    }

    public function isStrobeAllowed(): bool
    {
        return $this->allowed();
    }

    private function safe(LightState $state): LightState
    {
        if (! $this->allowed()) {
            return $state->withStrobe(0);
        }

        $strobe = $state->strobe;

        if ($strobe !== null && $strobe > 0) {
            $strobe = max(LightState::MIN_STROBE, min($strobe, $this->maxStrobe ?? (int) config('lighting.max_strobe')));
        }

        return $state->withStrobe($strobe);
    }

    private function allowed(): bool
    {
        return $this->strobeAllowed !== null
            ? ($this->strobeAllowed)()
            : LightingSetting::strobeAllowed();
    }
}
