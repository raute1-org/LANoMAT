<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Enums;

/**
 * A game server's lifecycle state, normalized across Pelican's own vocabulary
 * (the Application API reports a nullable `status` string: null means active
 * and running, `installing`/`install_failed`/`reinstall_failed` cover the
 * install pipeline, `suspended` covers panel-side suspension — there is no
 * dedicated "stopped" status on the Server resource itself; that is a Wings
 * power-state concept exposed only via the Client API's resources endpoint).
 * `HttpPelicanClient` maps Pelican's raw status onto this enum; `Provisioning`
 * is LANoMAT's own pre-creation state used by `FakePelicanClient` and the
 * provisioning workflow (Task 4) before the panel has assigned a server at all.
 */
enum ServerState: string
{
    case Provisioning = 'provisioning';
    case Installing = 'installing';
    case Running = 'running';
    case Stopped = 'stopped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Wird angelegt',
            self::Installing => 'Wird installiert',
            self::Running => 'Läuft',
            self::Stopped => 'Gestoppt',
            self::Failed => 'Fehlgeschlagen',
        };
    }
}
