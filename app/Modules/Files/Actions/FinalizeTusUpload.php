<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Enums\UploadReservationStatus;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Models\UploadReservation;
use App\Modules\Files\Support\UploadTypeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * tusd `post-finish` hook: the file is complete on the file host. The app
 * never saw the bytes, so it can only check what tusd reports: the received
 * offset must equal the declared size, and the extension rules are applied
 * once more. A file that fails is deleted and its quota released; a file that
 * passes becomes a PENDING {@see SharedFile} (orga still has to approve it)
 * and the reservation is converted in the same transaction, so the quota is
 * never counted twice or not at all. Repeated calls (tusd retries) are no-ops.
 *
 * Content cannot be sniffed here: the bytes are on another machine. The type
 * is stored as application/octet-stream; safety comes from the extension
 * blocklist, the moderation gate and attachment-only delivery.
 */
class FinalizeTusUpload
{
    public function __construct(private readonly ReleaseUploadReservation $release) {}

    /** @param  array<string, mixed>  $upload  the hook's Event.Upload object */
    public function handle(array $upload): void
    {
        $uploadId = $upload['ID'] ?? null;

        if (! is_string($uploadId) || $uploadId === '') {
            return;
        }

        $reservation = UploadReservation::query()->where('upload_id', $uploadId)->first();

        if ($reservation === null) {
            // tusd only reports uploads our pre-create hook let in (or stale ones we released).
            Log::warning('files: finished upload without a reservation ignored.', ['upload_id' => $uploadId]);

            return;
        }

        if ($reservation->status === UploadReservationStatus::Completed) {
            return;
        }

        if ($reservation->status !== UploadReservationStatus::Uploading) {
            // Released while it was running (expired, terminated): the file must not stay behind.
            $this->release->handle($reservation);

            return;
        }

        $size = $upload['Size'] ?? null;
        $offset = $upload['Offset'] ?? null;
        $metadata = is_array($upload['MetaData'] ?? null) ? $upload['MetaData'] : [];
        $clientMime = is_string($metadata['filetype'] ?? null) ? $metadata['filetype'] : null;

        $sizeOk = $size === $reservation->size_bytes && $offset === $reservation->size_bytes;
        $typeBlocked = UploadTypeGuard::fromConfig()->isBlockedType($reservation->original_name, null, $clientMime);

        if (! $sizeOk || $typeBlocked) {
            Log::warning('files: a finished upload was refused.', [
                'reservation_id' => $reservation->id,
                'reason' => $typeBlocked ? 'blocked_type' : 'size_mismatch',
            ]);

            if (! $this->release->handle($reservation)) {
                // Host unreachable: let the sweep retry the delete.
                $reservation->forceFill(['expires_at' => now()])->save();
            }

            return;
        }

        DB::transaction(function () use ($reservation): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["files:{$reservation->event_id}:{$reservation->user_id}"]);

            $file = new SharedFile([
                'event_id' => $reservation->event_id,
                'original_name' => $reservation->original_name,
            ]);
            $file->forceFill([
                'user_id' => $reservation->user_id,
                'disk' => 'filehost',
                'path' => $reservation->stored_path,
                'size_bytes' => $reservation->size_bytes,
                'mime' => 'application/octet-stream',
                'visibility' => FileVisibility::Pending,
            ])->save();

            $reservation->forceFill([
                'status' => UploadReservationStatus::Completed,
                'shared_file_id' => $file->id,
            ])->save();
        });
    }
}
