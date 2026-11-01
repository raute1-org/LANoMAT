<?php

declare(strict_types=1);

namespace App\Modules\Gallery\Actions;

use App\Models\User;
use App\Modules\Gallery\Events\GalleryUpdated;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Support\Facades\Gate;

/**
 * Withdraws a gallery photo the actor uploaded (any time, also once approved),
 * or any photo for orga. The bytes and the row go through {@see DeletePhoto},
 * so every surface that reads approved photos (participant grid, public photo
 * route, beamer slideshow, recap, zip) drops it on its next read. Retracting an
 * already-retracted photo is a no-op. Authorized in-Action against
 * EventPhotoPolicy::retract, mirroring {@see ApprovePhoto}. Notifies nobody:
 * GalleryUpdated only tells open gallery/beamer views to refresh.
 */
class RetractPhoto
{
    public function __construct(private readonly DeletePhoto $deletePhoto) {}

    public function handle(EventPhoto $photo, User $actor): void
    {
        Gate::forUser($actor)->authorize('retract', $photo);

        if (! $photo->exists) {
            return;
        }

        $eventId = $photo->event_id;

        $this->deletePhoto->handle($photo);

        GalleryUpdated::dispatch($eventId);
    }
}
