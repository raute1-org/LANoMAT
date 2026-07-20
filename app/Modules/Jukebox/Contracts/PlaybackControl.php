<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Contracts;

/**
 * Optional playback/device control capability, implemented only by music
 * backends that support pausing and resuming the configured player. Kept
 * separate from {@see MusicClient} so backends without this capability are
 * not forced to implement no-ops or throw not-supported exceptions.
 */
interface PlaybackControl
{
    public function pause(): void;

    public function resume(): void;
}
