<?php

declare(strict_types=1);

namespace App\Modules\Voice\Domain;

use App\Modules\Voice\Models\VoiceClientInstaller;

/**
 * The target OS for a downloadable voice-client installer
 * ({@see VoiceClientInstaller}). Purely descriptive
 * — used to group installers on the Filament resource and the participant
 * setup page, never to gate behaviour.
 */
enum VoiceClientPlatform: string
{
    case Windows = 'windows';
    case MacOS = 'macos';
    case Linux = 'linux';

    public function label(): string
    {
        return match ($this) {
            self::Windows => 'Windows',
            self::MacOS => 'macOS',
            self::Linux => 'Linux',
        };
    }
}
