<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\ServerLimits;
use App\Modules\GameServers\Domain\ServerSpec;
use App\Modules\GameServers\Exceptions\GameServerException;

/**
 * Turns LANoMAT's effective server config ({@see EffectiveConfig::resolve()})
 * into the {@see ServerSpec} the Pelican Application API needs.
 *
 * - `environment`: the egg's own variable defaults (so `required` egg
 *   variables never 422), overlaid with the typed config values mapped onto
 *   the conventional variable names below — but only where the egg really
 *   declares that variable — and finally the config's explicit
 *   `environment` map, which always wins.
 * - `limits`: memory is what {@see ResourceEstimate::for()} judges (the
 *   guardrail and the request therefore agree), disk/cpu come from the config
 *   or the `services.pelican.default_*` fallbacks.
 * - owner: `services.pelican.owner_user_id` (a panel user, ideally the
 *   non-admin service user whose client key LANoMAT holds).
 */
final class ServerSpecBuilder
{
    /**
     * Typed ServerConfig key => conventional egg variable names (first one the
     * egg declares wins).
     *
     * @var array<string, list<string>>
     */
    private const array VARIABLE_MAP = [
        'max_players' => ['MAX_PLAYERS'],
        'map' => ['SRCDS_MAP'],
        'password' => ['SERVER_PASSWORD'],
    ];

    public function __construct(private readonly PelicanClient $client) {}

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws GameServerException
     */
    public function build(Game $game, string $eggId, array $config, string $name): ServerSpec
    {
        $owner = config('services.pelican.owner_user_id');

        if ($owner === null || $owner === '') {
            throw GameServerException::pelicanNotConfigured();
        }

        $egg = $this->client->getEgg($eggId);
        $environment = $egg->variables;

        foreach (self::VARIABLE_MAP as $key => $candidates) {
            if (! isset($config[$key])) {
                continue;
            }

            foreach ($candidates as $variable) {
                if (array_key_exists($variable, $egg->variables)) {
                    $environment[$variable] = (string) $config[$key];
                    break;
                }
            }
        }

        $explicit = is_array($config['environment'] ?? null) ? $config['environment'] : [];

        foreach ($explicit as $variable => $value) {
            $environment[(string) $variable] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        $limits = new ServerLimits(
            memory: ResourceEstimate::for($game, $config),
            disk: isset($config['disk_mb']) ? (int) $config['disk_mb'] : (int) config('services.pelican.default_disk_mb'),
            cpu: isset($config['cpu_percent']) ? (int) $config['cpu_percent'] : (int) config('services.pelican.default_cpu_percent'),
        );

        $nodeId = config('services.pelican.default_node_id');

        return new ServerSpec(
            name: $name,
            ownerUserId: (string) $owner,
            eggId: $eggId,
            environment: $environment,
            limits: $limits,
            allocationId: null,
            nodeId: $nodeId !== null && $nodeId !== '' ? (string) $nodeId : null,
        );
    }
}
