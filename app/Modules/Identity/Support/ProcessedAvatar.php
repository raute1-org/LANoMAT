<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

final readonly class ProcessedAvatar
{
    public function __construct(
        public string $binary,
        public string $extension,
        public string $hash,
    ) {}
}
