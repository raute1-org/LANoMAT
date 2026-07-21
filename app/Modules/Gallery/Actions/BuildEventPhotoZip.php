<?php

declare(strict_types=1);

namespace App\Modules\Gallery\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Gallery\Support\GalleryQuery;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Builds a zip of an event's approved gallery photos on the private `local`
 * disk's `tmp/` directory, using native `ZipArchive`. Photo bytes are read
 * via `Storage::disk('local')->get()` and added with `addFromString()` —
 * deliberately storage-driver-agnostic, never assuming a local filesystem
 * path (the `local` disk could be swapped for any Flysystem adapter).
 *
 * @see GalleryQuery::approvedFor() for the ordering ("gallery order") the zip
 *      follows.
 */
class BuildEventPhotoZip
{
    public function __construct(private readonly GalleryQuery $query) {}

    public function handle(Event $event): string
    {
        $photos = $this->query->approvedFor($event);

        Storage::disk('local')->makeDirectory('tmp');

        $relativePath = 'tmp/event-'.$event->id.'-photos-'.Str::uuid()->toString().'.zip';
        $absolutePath = Storage::disk('local')->path($relativePath);

        $zip = new ZipArchive;
        if ($zip->open($absolutePath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Unable to create zip archive at {$absolutePath}.");
        }

        $number = 1;
        foreach ($photos as $photo) {
            $contents = Storage::disk('local')->get($photo->path);

            if ($contents === null) {
                throw new RuntimeException("Photo not found on disk at [{$photo->path}].");
            }

            $zip->addFromString('photo-'.$number.'.jpg', $contents);
            $number++;
        }

        $zip->close();

        return $absolutePath;
    }
}
