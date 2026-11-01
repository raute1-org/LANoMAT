<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Support;

use Carbon\CarbonImmutable;

/** A validated `manifest.json`: which server version the client was built for, and its files. */
final class ClientManifest
{
    /** @param  list<ClientBuild>  $builds  only files that exist on disk with the listed size */
    public function __construct(
        public readonly string $appVersion,
        public readonly string $commit,
        public readonly int $apiVersion,
        public readonly CarbonImmutable $builtAt,
        public readonly array $builds,
    ) {}
}
