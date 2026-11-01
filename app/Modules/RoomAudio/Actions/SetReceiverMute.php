<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Actions;

use App\Models\User;
use App\Modules\RoomAudio\Contracts\ReceiverClient;
use Illuminate\Support\Facades\Gate;

final readonly class SetReceiverMute
{
    public function __construct(private ReceiverClient $receiver) {}

    public function __invoke(User $actor, bool $mute): void
    {
        Gate::forUser($actor)->authorize('room-audio.control');

        $this->receiver->setMute($mute);
    }
}
