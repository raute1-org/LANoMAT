<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

/**
 * One thing a step would do, as text. `kind`: hypervisor (API call), ssh
 * (command on the VM, the script body), upload (file written on the VM), local
 * (work inside LANoMAT, e.g. key pinning).
 */
final readonly class PlannedAction
{
    public function __construct(
        public string $kind,
        public string $text,
    ) {}

    public static function hypervisor(string $text): self
    {
        return new self('hypervisor', $text);
    }

    public static function ssh(string $script): self
    {
        return new self('ssh', $script);
    }

    public static function upload(string $path, string $contents): self
    {
        return new self('upload', $path."\n".$contents);
    }

    public static function local(string $text): self
    {
        return new self('local', $text);
    }
}
