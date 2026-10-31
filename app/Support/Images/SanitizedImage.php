<?php

namespace App\Support\Images;

final readonly class SanitizedImage
{
    public function __construct(
        public string $binary,
        public string $extension,
        public int $width,
        public int $height,
    ) {}
}
