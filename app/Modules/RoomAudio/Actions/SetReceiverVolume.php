<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Actions;

use App\Models\User;
use App\Modules\RoomAudio\Contracts\ReceiverClient;
use App\Modules\RoomAudio\Models\RoomAudioSetting;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Clamps to 0..min(98, ceiling) and rounds to 0.5 steps. Returns the value
 * actually sent to the receiver, never the raw input.
 */
final readonly class SetReceiverVolume
{
    private const RECEIVER_MAX = 98.0;

    public function __construct(private ReceiverClient $receiver) {}

    public function __invoke(User $actor, float $volume): float
    {
        Gate::forUser($actor)->authorize('room-audio.control');

        if (! is_finite($volume)) {
            throw new InvalidArgumentException('Volume must be a finite number.');
        }

        $ceiling = min(self::RECEIVER_MAX, (float) RoomAudioSetting::current()->volume_ceiling);
        $sent = round(min(max($volume, 0.0), $ceiling) * 2) / 2;

        $this->receiver->setVolume($sent);

        return $sent;
    }
}
