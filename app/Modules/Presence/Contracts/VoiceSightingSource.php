<?php

declare(strict_types=1);

namespace App\Modules\Presence\Contracts;

use App\Modules\Presence\Domain\VoiceSighting;

/**
 * One voice backend's answer to "who is in which room right now". Implemented by the module that
 * owns the backend (Discord, Voice for Mumble/TeamSpeak, WebVoice) and registered under the
 * `presence.voice-sources` container tag only while that module is on. A source that cannot be
 * reached throws; the snapshot builder then treats that provider as unknown.
 */
interface VoiceSightingSource
{
    /** `discord`, `mumble`, `teamspeak` or `webvoice`. */
    public function provider(): string;

    /**
     * @return list<VoiceSighting>
     */
    public function sightings(): array;
}
