<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Enums;

/**
 * What a registered remote host is used for. Purely descriptive metadata for
 * the orga-facing registry (Task 2's SSH executor and Tasks 3/4's LanCache
 * and custom-game-server provisioning read this to pick the right host for a
 * job); it does not itself gate any behaviour in this task.
 */
enum HostRole: string
{
    case Lancache = 'lancache';
    case GameServer = 'gameserver';
    case Generic = 'generic';

    public function label(): string
    {
        return match ($this) {
            self::Lancache => __('hosts.host_role.lancache'),
            self::GameServer => __('hosts.host_role.gameserver'),
            self::Generic => __('hosts.host_role.generic'),
        };
    }
}
