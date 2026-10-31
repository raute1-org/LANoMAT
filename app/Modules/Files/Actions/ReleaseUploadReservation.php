<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Modules\Files\Enums\UploadReservationStatus;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\UploadReservation;
use App\Modules\Files\Support\FileStores;
use App\Modules\Files\Support\RemoteHostFileStore;
use App\Modules\Files\Support\ResumableUploads;

/**
 * Frees a reservation: deletes whatever the upload left on the file host (the
 * partial or rejected file and tusd's .info file) through the FileStore
 * contract, then marks the row released so its quota is free again. Deleting
 * is idempotent. When the file host cannot be reached nothing changes and
 * `false` is returned, so the next sweep tries again.
 */
class ReleaseUploadReservation
{
    public function __construct(private readonly FileStores $stores) {}

    public function handle(UploadReservation $reservation): bool
    {
        try {
            $store = $this->stores->for(RemoteHostFileStore::KEY);

            if ($reservation->stored_path !== null) {
                $store->delete($reservation->stored_path);
            }

            if ($reservation->upload_id !== null) {
                $store->delete(ResumableUploads::infoPath($reservation->upload_id));
            }
        } catch (FileException) {
            return false;
        }

        $reservation->forceFill([
            'status' => UploadReservationStatus::Released,
            'ticket_hash' => null,
            'ticket_expires_at' => null,
        ])->save();

        return true;
    }
}
