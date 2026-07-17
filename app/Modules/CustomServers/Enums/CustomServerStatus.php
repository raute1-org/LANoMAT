<?php

declare(strict_types=1);

namespace App\Modules\CustomServers\Enums;

use App\Modules\CustomServers\Actions\ProbeCustomServer;
use App\Modules\CustomServers\Actions\StartCustomServer;
use App\Modules\CustomServers\Actions\StopCustomServer;
use App\Modules\CustomServers\Models\CustomServer;

/**
 * Lifecycle state of an orga-defined docker {@see CustomServer},
 * as last observed by {@see StartCustomServer},
 * {@see StopCustomServer}, or
 * {@see ProbeCustomServer}.
 * `status`/`last_output` are both system-managed (not fillable — see
 * CustomServer::$fillable), mirroring Hosts\Enums\HostStatus.
 */
enum CustomServerStatus: string
{
    case Stopped = 'stopped';
    case Starting = 'starting';
    case Running = 'running';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Stopped => __('customservers.status.stopped'),
            self::Starting => __('customservers.status.starting'),
            self::Running => __('customservers.status.running'),
            self::Failed => __('customservers.status.failed'),
        };
    }
}
