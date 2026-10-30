<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

/**
 * A node port allocation. `address()` is what players connect to: the
 * allocation's alias when set, else its IP.
 */
final readonly class PelicanAllocation
{
    public function __construct(
        public string $id,
        public string $ip,
        public int $port,
        public ?string $alias = null,
        public bool $assigned = false,
    ) {}

    public function address(): string
    {
        return $this->alias !== null && $this->alias !== '' ? $this->alias : $this->ip;
    }
}
