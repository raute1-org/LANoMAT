<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

/**
 * The player-facing connection details for a provisioned game server.
 *
 * Mirrors Games' ServerConfig: never stored/edited as a loose
 * string=>string map, and a missing/empty value always decodes to an empty
 * (all-null) JoinInfo rather than null, so callers (the match page, the
 * Discord embed) can read it unconditionally. See JoinInfoCast for the DB
 * jsonb <-> JoinInfo bridge.
 */
final readonly class JoinInfo
{
    public function __construct(
        public ?string $address = null,
        public ?int $port = null,
        public ?string $password = null,
        public ?string $connectString = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'address' => $this->address,
            'port' => $this->port,
            'password' => $this->password,
            'connect_string' => $this->connectString,
        ];

        return array_filter(
            $data,
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $port = $data['port'] ?? null;

        return new self(
            address: $data['address'] ?? null,
            port: $port !== null ? (int) $port : null,
            password: $data['password'] ?? null,
            connectString: $data['connect_string'] ?? null,
        );
    }
}
