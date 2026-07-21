<?php

namespace App\Modules\Gallery\Actions;

use App\Models\User;
use App\Modules\Gallery\Events\GalleryUpdated;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Support\Facades\Gate;

class ToggleHighlight
{
    /**
     * Flips is_highlight on an (approved) photo. Authorized in-Action
     * against EventPhotoPolicy::highlight (isOrga()). Dispatches
     * GalleryUpdated so the gallery page/beamer scene refresh.
     */
    public function handle(EventPhoto $photo, User $actor): EventPhoto
    {
        Gate::forUser($actor)->authorize('highlight', $photo);

        $photo->forceFill([
            'is_highlight' => ! $photo->is_highlight,
        ]);
        $photo->save();

        GalleryUpdated::dispatch($photo->event_id);

        return $photo;
    }
}
