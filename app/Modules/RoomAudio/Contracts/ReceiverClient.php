<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Contracts;

use App\Modules\RoomAudio\Exceptions\ReceiverUnavailable;
use App\Modules\RoomAudio\Support\ReceiverState;

/**
 * Steers the room's AV receiver through the denon-admin sidecar. Every
 * setter throws {@see ReceiverUnavailable} when the receiver cannot be
 * reached (connection error or 503); state() never throws.
 */
interface ReceiverClient
{
    public function state(): ReceiverState;

    /**
     * @throws ReceiverUnavailable
     */
    public function setPower(bool $on): void;

    /**
     * @throws ReceiverUnavailable
     */
    public function setVolume(float $volume): void;

    /**
     * @throws ReceiverUnavailable
     */
    public function setMute(bool $mute): void;

    /**
     * @throws ReceiverUnavailable
     */
    public function setSource(string $code): void;

    /**
     * @throws ReceiverUnavailable
     */
    public function setMode(string $key): void;
}
