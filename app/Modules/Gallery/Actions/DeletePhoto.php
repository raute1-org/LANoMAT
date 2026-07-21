<?php

namespace App\Modules\Gallery\Actions;

use App\Modules\Files\Actions\DeleteSharedFile;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Support\Facades\Storage;

class DeletePhoto
{
    /**
     * Deletes both the full-size photo and its thumbnail from the private
     * `local` disk before removing the row — policy gate (owner or orga) is
     * enforced by the caller via authorize('delete'), mirroring
     * {@see DeleteSharedFile}.
     */
    public function handle(EventPhoto $photo): void
    {
        Storage::disk('local')->delete([$photo->path, $photo->thumb_path]);
        $photo->delete();
    }
}
