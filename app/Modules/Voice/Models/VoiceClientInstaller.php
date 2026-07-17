<?php

declare(strict_types=1);

namespace App\Modules\Voice\Models;

use App\Modules\Voice\Actions\SetCurrentInstaller;
use App\Modules\Voice\Domain\VoiceClientPlatform;
use App\Modules\Voice\Domain\VoiceProvider;
use Database\Factories\VoiceClientInstallerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A downloadable voice-client installer for one (provider, platform) pair,
 * stored on the private `local` disk (see M7.3's SharedFile precedent —
 * `app/Modules/Files/Models/SharedFile.php`). Only ever downloaded through
 * the authorized `voice.installers.download` route, never a public URL.
 *
 * @property VoiceProvider $provider
 * @property VoiceClientPlatform $platform
 * @property string $version
 * @property string $path
 * @property string $original_name
 * @property bool $is_current
 */
class VoiceClientInstaller extends Model
{
    /** @use HasFactory<VoiceClientInstallerFactory> */
    use HasFactory;

    /**
     * `path` and `is_current` are deliberately excluded — they are set only
     * by {@see SetCurrentInstaller} (and the
     * Filament Create/Edit pages) via forceFill()/explicit assignment, never
     * mass-assigned from client input. This mirrors SharedFile's
     * `path`/`visibility` exclusion and RemoteHost's `ssh_private_key`
     * exclusion.
     */
    protected $fillable = [
        'provider',
        'platform',
        'version',
        'original_name',
    ];

    protected function casts(): array
    {
        return [
            'provider' => VoiceProvider::class,
            'platform' => VoiceClientPlatform::class,
            'is_current' => 'boolean',
        ];
    }

    protected static function newFactory(): VoiceClientInstallerFactory
    {
        return VoiceClientInstallerFactory::new();
    }
}
