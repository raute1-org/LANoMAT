<?php

namespace App\Modules\Teams\Actions;

use App\Modules\Teams\Models\Team;
use App\Support\Images\InvalidImageException;
use App\Support\Images\SanitizeImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores a team logo on the public disk after re-encoding it metadata-free
 * (no EXIF/GPS), capped to `teams.logo_max_edge` and `teams.logo_max_pixels`.
 * The previous logo file is removed only after the new one is stored.
 */
class StoreTeamLogo
{
    public function __construct(private readonly SanitizeImage $sanitize) {}

    public function handle(Team $team, UploadedFile $file): void
    {
        try {
            $image = $this->sanitize->handle(
                (string) $file->getRealPath(),
                (int) config('teams.logo_max_edge'),
                (int) config('teams.logo_max_pixels'),
            );
        } catch (InvalidImageException $e) {
            throw ValidationException::withMessages(['logo' => trans('teams.errors.logo_unreadable')]);
        }

        $path = 'team-logos/'.Str::uuid().'.'.$image->extension;
        Storage::disk('public')->put($path, $image->binary);

        $oldPath = $team->logo_path;
        $team->logo_path = $path;

        if ($oldPath !== null) {
            Storage::disk('public')->delete($oldPath);
        }
    }
}
