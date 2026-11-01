<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Testing;

use App\Modules\RoomAudio\Contracts\ReceiverClient;
use App\Modules\RoomAudio\Exceptions\ReceiverUnavailable;
use App\Modules\RoomAudio\Support\ReceiverState;

/**
 * In-memory receiver for tests. Records every setter call in {@see $calls} as
 * [method, value] and returns the state set via {@see willReturnState()}.
 */
class FakeReceiverClient implements ReceiverClient
{
    /** @var list<array{string, bool|float|string}> */
    public array $calls = [];

    private ReceiverState $state;

    private bool $unavailable = false;

    public function __construct()
    {
        $this->state = ReceiverState::unreachable();
    }

    public function willReturnState(ReceiverState $state): void
    {
        $this->state = $state;
    }

    /**
     * Makes every subsequent setter throw {@see ReceiverUnavailable}, and
     * state() report the receiver as unreachable.
     */
    public function willBeUnavailable(): void
    {
        $this->unavailable = true;
    }

    public function state(): ReceiverState
    {
        return $this->unavailable ? ReceiverState::unreachable() : $this->state;
    }

    public function setPower(bool $on): void
    {
        $this->record('setPower', $on);
    }

    public function setVolume(float $volume): void
    {
        $this->record('setVolume', $volume);
    }

    public function setMute(bool $mute): void
    {
        $this->record('setMute', $mute);
    }

    public function setSource(string $code): void
    {
        $this->record('setSource', $code);
    }

    public function setMode(string $key): void
    {
        $this->record('setMode', $key);
    }

    private function record(string $method, bool|float|string $value): void
    {
        if ($this->unavailable) {
            throw ReceiverUnavailable::unreachable();
        }

        $this->calls[] = [$method, $value];
    }
}
