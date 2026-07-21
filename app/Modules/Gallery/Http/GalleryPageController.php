<?php

declare(strict_types=1);

namespace App\Modules\Gallery\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Http\FilePageController;
use App\Modules\Gallery\Actions\BuildEventPhotoZip;
use App\Modules\Gallery\Actions\DeletePhoto;
use App\Modules\Gallery\Actions\UploadPhoto;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Events\GalleryUpdated;
use App\Modules\Gallery\Exceptions\GalleryException;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Gallery\Support\GalleryQuery;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The participant gallery page (`Gallery/Index`). Deliberately auth-gated —
 * unlike the other public participant surfaces (jukebox, files, presence),
 * this page renders `<img>` tags pointing at `PhotoController::show()`/
 * `thumb()`, both of which live in the `auth` middleware group (Task 3), so
 * a public index would show guests broken/401 images. The one guest-facing
 * photo surface is the public recap's curated highlights (a later task,
 * separate public path) — never this page or these routes.
 */
class GalleryPageController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    public function index(Request $request, Event $event, GalleryQuery $query): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $photos = $this->visiblePhotosFor($event, $query, $user);

        return Inertia::render('Gallery/Index', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'photos' => $photos->map(fn (EventPhoto $photo): array => $this->photoDto($photo, $user))->all(),
            'canUpload' => $this->canUpload($event, $user),
            'labels' => trans('gallery.page'),
            'canDownloadZip' => $this->isFinishedOrArchived($event),
        ]);
    }

    public function store(Request $request, Event $event, UploadPhoto $action): RedirectResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $user = $this->authUser($request);
        abort_unless($this->canUpload($event, $user), 403);

        $request->validate([
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['required', 'image'],
            'caption' => ['nullable', 'string'],
        ]);

        $caption = $request->string('caption')->toString();

        // Validation guarantees `photos` is an array; Arr::wrap normalises the
        // framework's array|UploadedFile return type to a guaranteed array.
        $files = Arr::wrap($request->file('photos', []));
        $uploaded = 0;
        $skipped = 0;
        $lastError = 'gallery.errors.unreadable';

        // Process EVERY file: one bad file (unreadable/oversize) must not abandon
        // the rest of the batch, so we accumulate per-file outcomes and flash a
        // single summary afterwards instead of bailing on the first failure.
        foreach ($files as $file) {
            try {
                $action->handle($event, $user, $file, $caption === '' ? null : $caption);
                $uploaded++;
            } catch (GalleryException $exception) {
                $skipped++;
                $lastError = $exception->translationKey;
            }
        }

        if ($skipped === 0) {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => trans_choice('gallery.upload.uploaded', $uploaded, ['count' => $uploaded]),
            ]);
        } elseif ($uploaded === 0) {
            // Nothing stored — surface the concrete reason (keeps the precise
            // single-file error message users saw before).
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($lastError)]);
        } else {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => trans('gallery.upload.partial', [
                    'uploaded' => $uploaded,
                    'total' => count($files),
                    'skipped' => $skipped,
                ]),
            ]);
        }

        return back();
    }

    public function destroy(Request $request, EventPhoto $eventPhoto, DeletePhoto $action): RedirectResponse
    {
        $this->authorize('delete', $eventPhoto);

        $eventId = $eventPhoto->event_id;

        GalleryUpdated::dispatch($eventId);

        $action->handle($eventPhoto);

        return back();
    }

    /**
     * Zip download of the event's approved photos — gated to Finished/
     * Archived events only (the "when"); {@see EventPhotoPolicy::downloadZip}
     * is the "who" (any authenticated viewer of a public event).
     */
    public function downloadZip(Request $request, Event $event, BuildEventPhotoZip $action): BinaryFileResponse
    {
        abort_unless($event->isPubliclyVisible(), 404);
        abort_unless($this->isFinishedOrArchived($event), 403);

        $this->authorize('downloadZip', [EventPhoto::class, $event]);

        $path = $action->handle($event);

        return response()->download($path, "{$event->slug}-fotos.zip")->deleteFileAfterSend();
    }

    private function isFinishedOrArchived(Event $event): bool
    {
        return in_array($event->status, [EventStatus::Finished, EventStatus::Archived], true);
    }

    /**
     * Upload eligibility is a controller-level gate, not the policy: any
     * *registered* participant may add photos (during or after the LAN),
     * unlike the jukebox's checked-in gate — a lower-risk surface. The
     * `EventPhotoPolicy::create` ability stays `true` (mechanism only); this
     * method is the actual eligibility check, re-run by `store()`.
     */
    private function canUpload(Event $event, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->whereNotIn('status', [RegistrationStatus::Cancelled])
            ->exists();
    }

    /**
     * Every approved photo, plus the viewer's own pending ones — mirrors
     * {@see FilePageController::index()}.
     *
     * @return Collection<int, EventPhoto>
     */
    private function visiblePhotosFor(Event $event, GalleryQuery $query, User $user): Collection
    {
        $approved = $query->approvedFor($event);

        $ownPending = EventPhoto::query()
            ->where('event_id', $event->id)
            ->where('uploaded_by', $user->id)
            ->where('visibility', PhotoVisibility::Pending)
            ->with('uploader')
            ->get();

        return $approved->concat($ownPending);
    }

    /**
     * @return array<string, mixed>
     */
    private function photoDto(EventPhoto $photo, User $user): array
    {
        return [
            'id' => $photo->id,
            'thumbUrl' => route('gallery.photos.thumb', $photo->id),
            'fullUrl' => route('gallery.photos.show', $photo->id),
            'caption' => $photo->caption,
            'uploaderName' => $photo->uploader?->name,
            'visibility' => $photo->visibility->value,
            'mine' => $photo->uploaded_by === $user->id,
            'isHighlight' => $photo->is_highlight,
        ];
    }
}
