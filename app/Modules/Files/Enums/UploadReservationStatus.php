<?php

declare(strict_types=1);

namespace App\Modules\Files\Enums;

/**
 * Lifecycle of a resumable-upload reservation.
 *
 * Ticketed: the browser holds a single-use ticket, no byte has been sent.
 * Uploading: the file host accepted the upload (pre-create passed).
 * Completed: the file host reported the finished file and a pending SharedFile exists.
 * Released: abandoned, terminated, rejected or expired; the quota is free again.
 */
enum UploadReservationStatus: string
{
    case Ticketed = 'ticketed';
    case Uploading = 'uploading';
    case Completed = 'completed';
    case Released = 'released';
}
