<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Exceptions;

use DomainException;

/**
 * The receiver (or its sidecar) could not be reached, or the sidecar reports
 * that it is not connected to the receiver. Callers render a calm
 * "nicht erreichbar" state instead of surfacing a transport error.
 */
class ReceiverUnavailable extends DomainException
{
    public static function unreachable(): self
    {
        return new self('The room receiver could not be reached.');
    }
}
