<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Actions;

use App\Models\User;
use App\Modules\RoomAudio\Models\RoomAudioSetting;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final readonly class SetVolumeCeiling
{
    private const MAX = 98;

    public function __invoke(User $actor, int $ceiling): void
    {
        Gate::forUser($actor)->authorize('room-audio.control');

        if ($ceiling < 0 || $ceiling > self::MAX) {
            throw new InvalidArgumentException('The volume ceiling must be between 0 and 98.');
        }

        RoomAudioSetting::current()->update(['volume_ceiling' => $ceiling]);
    }
}
