<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

final readonly class CloudInitSpec
{
    /**
     * @param  list<string>  $sshPublicKeys  public keys only, one per entry
     */
    public function __construct(
        public string $user,
        public array $sshPublicKeys,
        public string $ip,
        public int $prefix,
        public string $gateway,
        public string $nameserver,
        public ?string $searchDomain = null,
    ) {}
}
