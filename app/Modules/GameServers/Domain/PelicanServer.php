<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

use App\Modules\GameServers\Enums\ServerState;

/**
 * A game server as reported by the Pelican Panel Application API, normalized
 * into LANoMAT's own shape by `HttpPelicanClient`/`FakePelicanClient`.
 */
final readonly class PelicanServer
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $id,
        public ServerState $state,
        public ?string $address,
        public ?int $port,
        public array $meta = [],
    ) {}
}
