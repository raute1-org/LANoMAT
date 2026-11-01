<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Support;

/** One downloadable file of a client release, as listed in `manifest.json`. */
final class ClientBuild
{
    public function __construct(
        public readonly string $name,
        public readonly string $os,
        public readonly string $arch,
        public readonly string $kind,
        public readonly int $size,
        public readonly string $sha256,
    ) {}

    /** @return array{name: string, os: string, arch: string, kind: string, size: int, sha256: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'os' => $this->os,
            'arch' => $this->arch,
            'kind' => $this->kind,
            'size' => $this->size,
            'sha256' => $this->sha256,
        ];
    }
}
