<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

use App\Modules\GameServers\Support\ServerSpecBuilder;

/**
 * Everything the Pelican Application API needs to create a server
 * (`POST /api/application/servers`), built by
 * {@see ServerSpecBuilder} from LANoMAT's
 * Game/preset data and the `services.pelican` config.
 *
 * `allocationId` null means "let the client pick the first free allocation on
 * `nodeId` (or the configured default node)". `environment` must contain
 * every `required` egg variable — the builder starts from the egg's defaults.
 */
final readonly class ServerSpec
{
    /**
     * @param  array<string, string|int|bool>  $environment
     */
    public function __construct(
        public string $name,
        public string $ownerUserId,
        public string $eggId,
        public array $environment,
        public ServerLimits $limits,
        public ?string $allocationId = null,
        public ?string $nodeId = null,
        public bool $startOnCompletion = true,
    ) {}
}
