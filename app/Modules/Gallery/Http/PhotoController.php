<?php

namespace App\Modules\Gallery\Http;

use App\Http\Controllers\Controller;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves gallery photos/thumbnails from the private `local` disk. Both
 * routes live in the `auth` middleware group and re-check
 * EventPhotoPolicy::view() per request — approved photos are visible to any
 * authenticated participant, pending ones only to the uploader or orga. This
 * is deliberately auth-gated; the public recap (later task) uses its own
 * narrow public-highlights path and must never reuse these routes.
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
}
