<?php

namespace App\Modules\Gallery\Actions;

use App\Models\User;
use App\Modules\Files\Actions\RejectSharedFile;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Support\Facades\Gate;

class RejectPhoto
{
    /**
     * Flips a pending photo to Rejected and stamps the reviewer. The photo
     * stays on disk and remains visible to the uploader and orga
     * (EventPhotoPolicy::view()) but is hidden from every other participant;
     * pruning rejected photos from storage is out of scope here. Authorized
     * in-Action against EventPhotoPolicy::reject (isHelper()), mirroring
     * {@see RejectSharedFile}.
     */
    public function handle(EventPhoto $photo, User $actor): EventPhoto
    {
        Gate::forUser($actor)->authorize('reject', $photo);

        $photo->forceFill([
            'visibility' => PhotoVisibility::Rejected,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);
        $photo->save();

        return $photo;
    }
}
