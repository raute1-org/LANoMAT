<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

final readonly class FetchedAvatar
{
    public function __construct(
        public string $binary,
        public string $contentType,
    ) {}
}
