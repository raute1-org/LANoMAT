<?php

declare(strict_types=1);

namespace App\Modules\Voice\Domain;

/**
 * A voice channel as reported by a provider's admin sidecar
 * (Mumble: docker/mumble-admin; TeamSpeak: docker/teamspeak-admin).
 */
final readonly class VoiceChannel
{
    /**
     * @param  list<string>  $occupantNames
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?int $parentId,
        public bool $temporary,
        public int $occupants = 0,
        /** Display names of the people in the channel itself (not its sub-channels), when the sidecar reports them. */
        public array $occupantNames = [],
    ) {}
}
