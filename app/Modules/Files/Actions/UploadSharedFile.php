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
     * that first takes a Postgres transaction-scoped advisory lock keyed on
     * `(event_id, user_id)`. Unlike `lockForUpdate()`, the advisory lock
     * does not depend on existing rows, so it also serializes a user's
     * *first* concurrent uploads to an event — the case where there is
     * nothing yet to row-lock and two transactions could otherwise both
     * read an empty sum and both pass the quota check. The lock is
     * released automatically on commit or rollback.
     */
    public function handle(Event $event, User $actor, UploadedFile $file): SharedFile
    {
        $this->validateFile($file);

        return DB::transaction(function () use ($event, $actor, $file): SharedFile {
            // Serializes concurrent uploads from the same user against the
            // same event, even when the user has zero prior files (and thus
            // no rows for `lockForUpdate()` to lock below).
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["files:{$event->id}:{$actor->id}"]);

            // Postgres rejects `FOR UPDATE` combined with an aggregate
            // (sum) in the same query, so the existing rows are locked and
            // fetched here, then summed in PHP. This row lock is now
            // redundant with the advisory lock above for correctness, but
            // is kept as defense in depth against reads outside this action.
            $existingBytes = SharedFile::query()
                ->where('event_id', $event->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->get('size_bytes')
                ->sum('size_bytes');

            $quotaBytes = (int) config('files.per_user_quota_mb') * 1024 * 1024;
            $sizeBytes = $file->getSize();

            if ($sizeBytes === false) {
                throw FileException::unreadable();
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
