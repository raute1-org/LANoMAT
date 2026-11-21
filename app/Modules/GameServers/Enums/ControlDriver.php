<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Enums;

/**
 * How LANoMAT controls a server, derived from the link (see ServerLink::controlDriver()),
 * never stored: nothing to backfill and no way to drift from the real link columns.
 */
enum ControlDriver: string
{
    /** Registered only: no power, console or logs. */
    case None = 'none';

    /** Through the Pelican panel (provisioned, or an external server adopted into it). */
    case Pelican = 'pelican';

    /** A permanent server of source `docker`: a container LANoMAT created itself (CustomServers). */
    case Docker = 'docker';

    /** An existing container on a managed remote host: power, console and logs over SSH. */
    case RemoteDocker = 'remote_docker';
}
