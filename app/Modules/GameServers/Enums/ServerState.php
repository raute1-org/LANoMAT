<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Enums;

/**
 * A game server's lifecycle state, normalized across Pelican's two vocabularies.
 * The Application API's `status` is null once a server is installed (it only
 * reports `installing`/`install_failed`/`reinstall_failed`/`suspended`), so the
 * real runtime state comes from the Client API's resources endpoint
 * (`current_state`: running|offline|starting|stopping) — `HttpPelicanClient`
 * merges the two. `Provisioning` is LANoMAT's own pre-creation state;
 * `Unknown` means the runtime state has not been (or could not be) fetched.
 */
enum ServerState: string
{
    case Provisioning = 'provisioning';
    case Installing = 'installing';
    case Running = 'running';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Starting = 'starting';
    case Stopping = 'stopping';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Wird angelegt',
            self::Installing => 'Wird installiert',
            self::Running => 'Läuft',
            self::Stopped => 'Gestoppt',
            self::Failed => 'Fehlgeschlagen',
            self::Starting => 'Startet',
            self::Stopping => 'Stoppt',
            self::Unknown => 'Unbekannt',
        };
    }
}
