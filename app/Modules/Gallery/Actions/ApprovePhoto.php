<?php

namespace App\Modules\Gallery\Actions;

use App\Models\User;
use App\Modules\Files\Actions\ApproveSharedFile;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Events\GalleryUpdated;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Support\Facades\Gate;

class ApprovePhoto
{
    /**
     * Flips a pending (or rejected) photo to Approved and stamps the
     * reviewer, so it becomes visible to every participant via
     * EventPhotoPolicy::view(). Authorized in-Action against
     * EventPhotoPolicy::approve (isHelper()), mirroring
     * {@see ApproveSharedFile}. Dispatches
     * GalleryUpdated so the gallery page/beamer scene refresh.
     */
    public function handle(EventPhoto $photo, User $actor): EventPhoto
    {
        Gate::forUser($actor)->authorize('approve', $photo);

        $photo->forceFill([
            'visibility' => PhotoVisibility::Approved,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);
        $photo->save();

        GalleryUpdated::dispatch($photo->event_id);

        return $photo;
    }
}
