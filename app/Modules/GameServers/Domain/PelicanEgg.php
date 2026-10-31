<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Domain;

/**
 * An egg (game template). `uuid` is the stable identity across panels (the
 * numeric `id` differs per panel); presets and the egg import match on it. `variables` maps each env variable name to its
 * default value and is only populated by `PelicanClient::getEgg()`
 * (`?include=variables`), not by `listEggs()`.
 */
final readonly class PelicanEgg
{
    /**
     * @param  array<string, string>  $variables  env_variable => default_value
     * @param  list<string>  $requiredVariables  env variables whose rules contain `required`
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $variables = [],
        public array $requiredVariables = [],
        public ?string $uuid = null,
    ) {}
}
