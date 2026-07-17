<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Domain;

use App\Modules\Hosts\Contracts\RemoteExecutor;

/**
 * The outcome of a single reachability probe via
 * {@see RemoteExecutor::probe()}. `$fingerprint`
 * is the SHA256 fingerprint of the server's host key when the probe reached
 * the host, null otherwise; `$error` carries a short, non-sensitive failure
 * description (never the private key or raw command output).
 */
final readonly class HostProbe
{
    public function __construct(
        public bool $reachable,
        public ?string $fingerprint,
        public ?string $error,
    ) {}
}
