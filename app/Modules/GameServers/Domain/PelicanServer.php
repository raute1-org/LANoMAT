<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

use App\Modules\GameServers\Enums\ServerState;

/**
 * A game server as reported by the Pelican Panel, normalized into LANoMAT's
 * own shape by `HttpPelicanClient`/`FakePelicanClient`.
 *
 * `id` is the numeric Application API id (create/show/delete); `identifier`
 * is the short uuid the Client API addresses a server by (power, resources).
 * `address`/`port` come from the server's default allocation resource
 * (`alias ?? ip`), not from the server payload itself.
 */
final readonly class PelicanServer
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $id,
        public ServerState $state,
        public ?string $address = null,
        public ?int $port = null,
        public ?string $identifier = null,
        public ?string $uuid = null,
        public ?string $name = null,
        public ?string $nodeId = null,
        public ?string $allocationId = null,
        public ?ServerLimits $limits = null,
        public array $meta = [],
    ) {}
}
