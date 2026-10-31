<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\UploadReservationStatus;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\UploadReservation;
use App\Modules\Files\Support\ResumableUploads;
use App\Modules\Files\Support\UploadQuota;
use App\Modules\Files\Support\UploadTypeGuard;
use Illuminate\Support\Facades\DB;

/**
 * Control plane of a resumable upload, step 1: before the browser sends a
 * byte to the file host it asks for a ticket. All rules that need the
 * database or the user live here: the per-file maximum, the extension
 * blocklist and the per-user quota, which is RESERVED under the same
 * per-(event, user) advisory lock {@see UploadSharedFile} uses, so parallel
 * uploads cannot add up to more than the quota.
 *
 * The ticket is an opaque random token. Only its sha256 is stored, and it is
 * bound (through the reservation row) to user, event, file name and declared
 * size; the pre-create hook checks all of that and consumes it. Asking again
 * for the very same file (a reload, a resume) rotates the token on the same
 * reservation instead of reserving twice.
 */
class IssueUploadTicket
{
    /**
     * @return array{ticket: string, reservation: UploadReservation}
     *
     * @throws FileException
     */
    public function handle(Event $event, User $actor, string $name, int $size, ?string $clientMime = null): array
    {
        if (! ResumableUploads::enabled()) {
            throw FileException::uploadsUnavailable();
        }

        $name = self::cleanName($name);

        if ($size < 1 || $size > UploadQuota::maxFileBytes()) {
            throw FileException::tooLarge();
        }

        if ($name === '' || UploadTypeGuard::fromConfig()->isBlockedType($name, null, $clientMime)) {
            throw FileException::blockedType();
        }

        $ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $reservation = DB::transaction(function () use ($event, $actor, $name, $size, $ticket): UploadReservation {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["files:{$event->id}:{$actor->id}"]);

            $ticketExpiresAt = now()->addSeconds(ResumableUploads::ticketTtlSeconds());

            $existing = UploadReservation::query()
                ->holdingQuota()
                ->where('event_id', $event->id)
                ->where('user_id', $actor->id)
                ->where('original_name', $name)
                ->where('size_bytes', $size)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'ticket_hash' => ResumableUploads::hashTicket($ticket),
                    'ticket_expires_at' => $ticketExpiresAt,
                    'expires_at' => $existing->expires_at->max($ticketExpiresAt),
                ])->save();

                return $existing;
            }

            if (UploadQuota::usedBytes($event->id, $actor->id) + $size > UploadQuota::capBytes()) {
                throw FileException::quotaExceeded();
            }

            $reservation = new UploadReservation;
            $reservation->forceFill([
                'event_id' => $event->id,
                'user_id' => $actor->id,
                'original_name' => $name,
                'size_bytes' => $size,
                'ticket_hash' => ResumableUploads::hashTicket($ticket),
                'ticket_expires_at' => $ticketExpiresAt,
                'status' => UploadReservationStatus::Ticketed,
                'expires_at' => $ticketExpiresAt,
            ])->save();

            return $reservation;
        });

        return ['ticket' => $ticket, 'reservation' => $reservation];
    }

    /**
     * The name as stored and compared: no control characters, no path parts
     * (only the base name is ever shown or used as a download name), bounded.
     */
    public static function cleanName(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim(str_replace('\\', '/', $name));
        $name = basename($name);

        return mb_substr($name, 0, 255);
    }
}
