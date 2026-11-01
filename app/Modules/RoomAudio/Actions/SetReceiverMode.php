<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Actions;

use App\Models\User;
use App\Modules\RoomAudio\Contracts\ReceiverClient;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Accepts only the sound-mode keys the receiver reports.
 */
final readonly class SetReceiverMode
{
    public function __construct(private ReceiverClient $receiver) {}

    public function __invoke(User $actor, string $key): void
    {
        Gate::forUser($actor)->authorize('room-audio.control');

        $known = array_column($this->receiver->state()->modes, 'key');

        if (! in_array($key, $known, true)) {
            throw new InvalidArgumentException('Unknown receiver sound mode.');
        }

        $this->receiver->setMode($key);
    }
}
