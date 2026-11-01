<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Actions;

use App\Models\User;
use App\Modules\RoomAudio\Contracts\ReceiverClient;
use App\Modules\RoomAudio\Exceptions\ReceiverUnavailable;
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

        $state = $this->receiver->state();

        if (! $state->connected) {
            throw ReceiverUnavailable::unreachable();
        }

        $known = array_column($state->modes, 'key');

        if (! in_array($key, $known, true)) {
            throw new InvalidArgumentException('Unknown receiver sound mode.');
        }

        $this->receiver->setMode($key);
    }
}
