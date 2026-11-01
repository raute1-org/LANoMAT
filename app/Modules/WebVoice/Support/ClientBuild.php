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
        /** Code-signed by the release pipeline? `null`: the manifest does not say (the local Linux build). */
        public readonly ?bool $signed = null,
    ) {}

    /** Content type of the download, from the file extension (the name was validated against the manifest). */
    public function contentType(): string
    {
        return match (true) {
            str_ends_with($this->name, '.zip') => 'application/zip',
            str_ends_with($this->name, '.exe') => 'application/vnd.microsoft.portable-executable',
            str_ends_with($this->name, '.tar.gz'), str_ends_with($this->name, '.gz') => 'application/gzip',
            default => 'application/octet-stream',
        };
    }

    /** @return array{name: string, os: string, arch: string, kind: string, size: int, sha256: string, signed: bool|null} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'os' => $this->os,
            'arch' => $this->arch,
            'kind' => $this->kind,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'signed' => $this->signed,
        ];
    }
}
