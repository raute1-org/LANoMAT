<?php

declare(strict_types=1);

namespace App\Modules\Voice\Domain;

/**
 * A Mumble channel as reported by the mumble-admin Ice-REST sidecar
 * (docker/mumble-admin/app.py's `ChannelOut`).
 */
final readonly class MumbleChannel
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $parentId,
        public bool $temporary,
    ) {}
}
