<?php

namespace App\Modules\Files\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UploadSharedFile
{
    /**
     * Stores an uploaded file to the private `local` disk under
     * `event-{id}/…` and creates the row with `visibility = Pending`.
     *
     * The event and actor are always taken from the trusted arguments, never
     * from request input — a client-supplied `user_id` cannot make the
     * upload appear as belonging to another user.
     *
     * The per-event per-user quota is enforced inside a `DB::transaction`
     * with a row lock on the actor's existing files for the event, so two
     * concurrent uploads from the same user cannot both slip in under the
     * limit (a "lock, sum, check, insert" sequence rather than "check then
     * insert").
     */
    public function handle(Event $event, User $actor, UploadedFile $file): SharedFile
    {
        $this->validateFile($file);

        return DB::transaction(function () use ($event, $actor, $file): SharedFile {
            // Postgres rejects `FOR UPDATE` combined with an aggregate
            // (sum) in the same query, so the existing rows are locked and
            // fetched here, then summed in PHP — still serializes
            // concurrent uploads from the same user against the same event.
            $existingBytes = SharedFile::query()
                ->where('event_id', $event->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->get('size_bytes')
                ->sum('size_bytes');

            $quotaBytes = (int) config('files.per_user_quota_mb') * 1024 * 1024;
            $sizeBytes = $file->getSize();

            if ($sizeBytes === false) {
                $sizeBytes = 0;
            }

            if ($existingBytes + $sizeBytes > $quotaBytes) {
                throw FileException::quotaExceeded();
            }

            $path = $file->store('event-'.$event->id, 'local');
            abort_if($path === false, 500, 'Failed to store the uploaded file.');

            $sharedFile = new SharedFile([
                'event_id' => $event->id,
                'user_id' => $actor->id,
                'original_name' => $file->getClientOriginalName(),
            ]);

            $sharedFile->forceFill([
                'user_id' => $actor->id,
                'disk' => 'local',
                'path' => $path,
                'size_bytes' => $sizeBytes,
                'mime' => $file->getMimeType(),
                'visibility' => FileVisibility::Pending,
            ]);
            $sharedFile->save();

            return $sharedFile;
        });
    }

    private function validateFile(UploadedFile $file): void
    {
        $maxBytes = (int) config('files.max_upload_mb') * 1024 * 1024;
        $size = $file->getSize();

        if ($size === false || $size > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => trans('files.errors.too_large'),
            ]);
        }

        $allowedMimes = config('files.allowed_mimes');
        $mime = $file->getMimeType();

        if (! is_array($allowedMimes) || ! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => trans('files.errors.invalid_mime'),
            ]);
        }
    }
}
