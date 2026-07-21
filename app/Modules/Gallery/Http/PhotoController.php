<?php

namespace App\Modules\Gallery\Http;

use App\Http\Controllers\Controller;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves gallery photos/thumbnails from the private `local` disk. `show`/
 * `thumb` live in the `auth` middleware group and re-check
 * EventPhotoPolicy::view() per request — approved photos are visible to any
 * authenticated participant, pending ones only to the uploader or orga.
 *
 * `publicShow`/`publicThumb` are the separate, deliberately narrower public
 * path used by the beamer gallery scene (Task 7) and the public recap
 * (Task 9): no auth middleware and no policy — the
 * Approved-visibility-plus-publicly-visible-event check *is* the gate.
 * Approved means orga-vetted, so it is safe to serve to an anonymous beamer;
 * pending/rejected photos and photos of a Draft event always 404 here, and
 * these routes are never reused for the auth-gated participant gallery page.
 */
class PhotoController extends Controller
{
    use AuthorizesRequests;

    public function show(Request $request, EventPhoto $eventPhoto): StreamedResponse
    {
        $this->authorize('view', $eventPhoto);

        return Storage::disk('local')->response($eventPhoto->path);
    }

    public function thumb(Request $request, EventPhoto $eventPhoto): StreamedResponse
    {
        $this->authorize('view', $eventPhoto);

        return Storage::disk('local')->response($eventPhoto->thumb_path);
    }

    public function publicShow(EventPhoto $eventPhoto): StreamedResponse
    {
        $this->assertPubliclyServable($eventPhoto);

        return Storage::disk('local')->response($eventPhoto->path);
    }

    public function publicThumb(EventPhoto $eventPhoto): StreamedResponse
    {
        $this->assertPubliclyServable($eventPhoto);

        return Storage::disk('local')->response($eventPhoto->thumb_path);
    }

    private function assertPubliclyServable(EventPhoto $eventPhoto): void
    {
        $event = $eventPhoto->event()->first();

        abort_if($event === null, 404);
        abort_unless($eventPhoto->visibility === PhotoVisibility::Approved && $event->isPubliclyVisible(), 404);
    }
}
