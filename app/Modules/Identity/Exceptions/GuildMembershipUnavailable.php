<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use RuntimeException;

/**
 * The guild membership could not be determined. Never carries the access
 * token; the message only names the failure class (status code or transport).
 */
class GuildMembershipUnavailable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("Guild membership check unavailable: {$reason}.");
    }
}
