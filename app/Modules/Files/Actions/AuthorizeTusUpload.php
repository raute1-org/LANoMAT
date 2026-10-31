<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Modules\Files\Enums\UploadReservationStatus;
use App\Modules\Files\Models\UploadReservation;
use App\Modules\Files\Support\ResumableUploads;
use App\Modules\Files\Support\UploadQuota;
use App\Modules\Files\Support\UploadTypeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * tusd `pre-create` hook: the only moment a new upload is allowed to exist.
 * The ticket from the upload metadata is consumed (single use), checked
 * against the reservation (user, event, name, size) and the rules are
 * re-applied. On success the app, never the client, chooses the upload id and
 * the final path on the file host (`event-<id>/<uuid>`), and replaces the
 * metadata so the ticket does not stay in tusd's .info file.
 *
 * Returns the hook response as a plain array: either `ChangeFileInfo` or
 * `RejectUpload` with a JSON body the browser maps to a German message.
 */
class AuthorizeTusUpload
{
    /**
     * @param  array<string, mixed>  $upload  the hook's Event.Upload object
     * @return array<string, mixed>
     */
    public function handle(array $upload): array
    {
        $metadata = is_array($upload['MetaData'] ?? null) ? $upload['MetaData'] : [];
        $ticket = $metadata['ticket'] ?? null;
        $name = $metadata['filename'] ?? null;
        $size = $upload['Size'] ?? null;
        $deferred = (bool) ($upload['SizeIsDeferred'] ?? false);

        if (! is_string($ticket) || $ticket === '' || strlen($ticket) > 200 || ! is_string($name)) {
            return $this->reject('ticket_invalid');
        }

        return DB::transaction(function () use ($ticket, $name, $size, $deferred, $metadata): array {
            $reservation = UploadReservation::query()
                ->where('ticket_hash', ResumableUploads::hashTicket($ticket))
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return $this->reject('ticket_invalid');
            }

            $usable = in_array($reservation->status, [UploadReservationStatus::Ticketed, UploadReservationStatus::Uploading], true);

            if (! $usable || $reservation->ticket_expires_at === null || $reservation->ticket_expires_at->isPast()) {
                return $this->reject('ticket_expired');
            }

            if ($deferred || ! is_int($size) || $size !== $reservation->size_bytes || $name !== $reservation->original_name) {
                return $this->reject('ticket_mismatch');
            }

            if ($size > UploadQuota::maxFileBytes()) {
                return $this->reject('too_large');
            }

            $clientMime = is_string($metadata['filetype'] ?? null) ? $metadata['filetype'] : null;

            if (UploadTypeGuard::fromConfig()->isBlockedType($reservation->original_name, null, $clientMime)) {
                return $this->reject('blocked_type');
            }

            $uploadId = (string) Str::uuid();
            $path = 'event-'.$reservation->event_id.'/'.$uploadId;

            $reservation->forceFill([
                'status' => UploadReservationStatus::Uploading,
                'upload_id' => $uploadId,
                'stored_path' => $path,
                // single use: the ticket is gone, a resume talks to tusd's own upload URL
                'ticket_hash' => null,
                'ticket_expires_at' => null,
                'expires_at' => now()->addHours(ResumableUploads::uploadTtlHours()),
            ])->save();

            return [
                'ChangeFileInfo' => [
                    'ID' => $uploadId,
                    'MetaData' => [
                        'filename' => $reservation->original_name,
                        'filetype' => $clientMime ?? 'application/octet-stream',
                    ],
                    'Storage' => [
                        'Path' => rtrim((string) config('files.tus.data_dir'), '/').'/'.$path,
                    ],
                ],
            ];
        });
    }

    /** @return array<string, mixed> */
    private function reject(string $code): array
    {
        return [
            'RejectUpload' => true,
            'HTTPResponse' => [
                'StatusCode' => 403,
                'Body' => (string) json_encode(['code' => $code]),
                'Header' => ['Content-Type' => 'application/json'],
            ],
        ];
    }
}
