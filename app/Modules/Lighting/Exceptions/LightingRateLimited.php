<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Exceptions;

use RuntimeException;

final class LightingRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many lighting actions.');
    }
}
