<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

use App\Modules\Provisioning\Exceptions\HypervisorException;

/**
 * A cloud image to download onto the hypervisor. The checksum is mandatory:
 * an unverified image is never accepted (the hypervisor verifies it while
 * downloading, `download-url` with `checksum` + `checksum-algorithm`).
 */
final readonly class ImageSource
{
    private const array LENGTHS = ['sha256' => 64, 'sha512' => 128];

    public string $checksum;

    public function __construct(
        public string $url,
        string $checksum,
        public string $algorithm,
        public string $filename,
    ) {
        $checksum = strtolower(trim($checksum));

        if (! isset(self::LENGTHS[$algorithm]) || preg_match('/^[0-9a-f]+$/', $checksum) !== 1 || strlen($checksum) !== self::LENGTHS[$algorithm]) {
            throw HypervisorException::checksumRequired();
        }

        if (! str_starts_with($url, 'https://') || preg_match('/^https:\/\/[A-Za-z0-9._~:\/?#\[\]@!$&\'()*+,;=%-]+$/', $url) !== 1) {
            throw HypervisorException::invalidImageSource();
        }

        if (preg_match('/^[A-Za-z0-9._-]+\.(qcow2|img|raw)$/', $filename) !== 1) {
            throw HypervisorException::invalidImageSource();
        }

        $this->checksum = $checksum;
    }
}
