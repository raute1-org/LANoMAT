<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Enums;

/**
 * Lifecycle state of a ServerLink (the record tying a match/tournament to
 * its provisioned Pelican server), as tracked by the provisioning job
 * (Task 4) — distinct from ServerState, which is the raw Pelican-reported
 * server state.
 */
enum ServerLinkStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';
    case Stopped = 'stopped';

    public function label(): string
    {
        return __('gameservers.server_link_status.'.$this->value);
    }
}
