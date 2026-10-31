<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Enums;

/**
 * Where a permanent game server comes from, and therefore what LANoMAT may do
 * with it. Pelican servers are fully managed (create, power, delete), Docker
 * servers are containers on a managed remote host (power only), external
 * servers are merely registered and probed (no control at all).
 */
enum ServerSource: string
{
    case Pelican = 'pelican';
    case Docker = 'docker';
    case External = 'external';

    /** Whether an admin can start/stop/restart it from LANoMAT. */
    public function canControl(): bool
    {
        return $this !== self::External;
    }

    public function label(): string
    {
        return __('gameservers.permanent.source.'.$this->value);
    }
}
