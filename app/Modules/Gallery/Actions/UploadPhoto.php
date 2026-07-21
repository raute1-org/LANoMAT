<?php

namespace App\Modules\Gallery\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Exceptions\GalleryException;
use App\Modules\Gallery\Models\EventPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Loads the upload via Intervention (GD driver, bound automatically by
 * `intervention/image-laravel`'s service provider from
 * `config('intervention-image.driver')` — no manual container binding
 * needed), bakes EXIF orientation into pixels with orient(), caps the
 * longest edge, and re-encodes as JPEG — GD drops ALL EXIF metadata
 * (including GPS) on re-encode, so the stored original carries no
 * location/camera data. A thumbnail is stored alongside. Both land on the
 * private `local` disk; the row starts Pending. Ownership + visibility are
 * set from the trusted actor via forceFill(), never from client input.
 *
 * Deviation from the task brief: `ImageManager` in the installed v4.2.0 has
 * no `read()` method (that name does not exist on this release); the real
 * API is `decode()`/`decodePath()`/`decodeBinary()`/etc. This uses
 * `decodePath()` for the uploaded file and `decodeBinary()` to re-decode
 * the already-encoded original when building the thumbnail.
 */
class UploadPhoto
{
    public function __construct(private readonly ImageManager $manager) {}

    public function handle(Event $event, User $actor, UploadedFile $file, ?string $caption = null): EventPhoto
    {
        $this->guardSize($file);

        try {
            $image = $this->manager->decodePath($file->getRealPath());
        } catch (Throwable) {
            throw GalleryException::unreadable();
        }

        $quality = (int) config('gallery.quality', 82);
        $maxEdge = (int) config('gallery.max_edge');
        $image->orient()->scaleDown(width: $maxEdge, height: $maxEdge);
        $original = $image->encodeUsingFormat(Format::JPEG, quality: $quality);

        $thumb = $this->manager->decodeBinary((string) $original)
            ->scaleDown(width: (int) config('gallery.thumb_width'))
            ->encodeUsingFormat(Format::JPEG, quality: $quality);

        $uuid = (string) Str::uuid();
        $dir = 'event-'.$event->id.'/photos';
        $path = $dir.'/'.$uuid.'.jpg';
        $thumbPath = $dir.'/'.$uuid.'-thumb.jpg';

        Storage::disk('local')->put($path, (string) $original);
        Storage::disk('local')->put($thumbPath, (string) $thumb);

        $trimmed = $caption === null ? null : trim($caption);

        $photo = new EventPhoto([
            'event_id' => $event->id,
            'caption' => $trimmed === '' ? null : $trimmed,
        ]);
        $photo->forceFill([
            'uploaded_by' => $actor->id,
            'path' => $path,
            'thumb_path' => $thumbPath,
            'width' => $image->size()->width(),
            'height' => $image->size()->height(),
            'visibility' => PhotoVisibility::Pending,
            'is_highlight' => false,
        ]);
        $photo->save();

        return $photo;
    }

    private function guardSize(UploadedFile $file): void
    {
        $maxBytes = (int) config('gallery.max_upload_mb') * 1024 * 1024;
        $size = $file->getSize();

        if ($size === false || $size > $maxBytes) {
            throw GalleryException::tooLarge();
        }

        $mime = $file->getMimeType();
        $allowed = config('gallery.allowed_mimes');

        if (! is_array($allowed) || ! in_array($mime, $allowed, true)) {
            throw GalleryException::invalidType();
        }
    }
}
