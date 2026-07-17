<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Enums;

/**
 * Reachability state of a registered remote host, as last observed by Task
 * 2's ProbeHost. `Unknown` is the initial state before any probe has run;
 * `status`/`last_probed_at` are both system-managed (not fillable — see
 * RemoteHost::$fillable).
 */
enum HostStatus: string
{
    case Unknown = 'unknown';
    case Reachable = 'reachable';
    case Unreachable = 'unreachable';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => __('hosts.host_status.unknown'),
            self::Reachable => __('hosts.host_status.reachable'),
            self::Unreachable => __('hosts.host_status.unreachable'),
        };
    }
}
