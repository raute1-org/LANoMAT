<?php

declare(strict_types=1);

namespace App\Modules\Files\Console;

use App\Modules\Files\Actions\ReleaseUploadReservation;
use App\Modules\Files\Enums\UploadReservationStatus;
use App\Modules\Files\Models\UploadReservation;
use Illuminate\Console\Command;

/**
 * tusd's file storage has no expiry of its own, so abandoned resumable uploads
 * are cleaned up here: every reservation that is still open after its expiry
 * has its leftovers deleted from the file host (through the FileStore
 * contract) and is released. Idempotent; a host that cannot be reached leaves
 * the row for the next run. Finished rows are pruned after a month.
 */
class SweepAbandonedUploadsCommand extends Command
{
    protected $signature = 'lanomat:sweep-file-uploads';

    protected $description = 'Release expired resumable-upload reservations and delete their leftovers on the file host';

    public function handle(ReleaseUploadReservation $release): int
    {
        $released = 0;
        $kept = 0;

        UploadReservation::query()
            ->whereIn('status', [UploadReservationStatus::Ticketed->value, UploadReservationStatus::Uploading->value])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (UploadReservation $reservation) use ($release, &$released, &$kept): void {
                if ($release->handle($reservation)) {
                    $released++;
                } else {
                    $kept++;
                }
            });

        UploadReservation::query()
            ->whereIn('status', [UploadReservationStatus::Completed->value, UploadReservationStatus::Released->value])
            ->where('updated_at', '<', now()->subDays(30))
            ->delete();

        $this->components->info("Released {$released} abandoned upload(s), {$kept} kept for the next run.");

        return self::SUCCESS;
    }
}
