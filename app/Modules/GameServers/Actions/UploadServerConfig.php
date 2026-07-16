<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Modules\GameServers\Support\EffectiveConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores an orga-uploaded game-server config file to the `public` disk
 * ("upload mode" — the alternative to picking a per-game preset, see
 * {@see EffectiveConfig}).
 *
 * The file is always written to Laravel Storage and only its path is kept
 * (never Base64 into the database — see CLAUDE.md's global uploads
 * constraint and TeamController::update's logo upload for the precedent).
 * Parsing/interpreting the file's contents into a config array is
 * EffectiveConfig::resolve()'s job, not this action's: this action only
 * owns the storage side effect.
 */
class UploadServerConfig
{
    public function handle(UploadedFile $file): string
    {
        $path = $file->store('gameserver-configs', 'public');

        abort_if($path === false, 500, 'Failed to store the uploaded server config.');

        return $path;
    }
}
