<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use RuntimeException;

/** An avatar could not be fetched or is not an acceptable image; the previous avatar stays. */
class AvatarFetchFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('Avatar fetch failed: '.$reason);
    }
}
