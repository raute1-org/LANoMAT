<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Actions;

use App\Models\User;
use App\Modules\RoomAudio\Contracts\ReceiverClient;
use App\Modules\RoomAudio\Exceptions\ReceiverUnavailable;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Accepts only the inputs the receiver reports plus the network input (NET).
 */
final readonly class SetReceiverSource
{
    private const NETWORK_INPUT = 'NET';

    public function __construct(private ReceiverClient $receiver) {}

    public function __invoke(User $actor, string $code): void
    {
        Gate::forUser($actor)->authorize('room-audio.control');

        $state = $this->receiver->state();

        if (! $state->connected) {
            throw ReceiverUnavailable::unreachable();
        }

        $known = array_column($state->sources, 'code');

        if ($code !== self::NETWORK_INPUT && ! in_array($code, $known, true)) {
            throw new InvalidArgumentException('Unknown receiver source.');
        }

        $this->receiver->setSource($code);
    }
}
