<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanAllocation;
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

    /**
     * Preset requirement => [egg variable candidates, `services.pelican.*` config key]. The value
     * comes from the environment config, never from the preset; the variable is only filled
     * where the egg declares it.
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const array SECRETS = [
        'authkey' => [['STEAM_WEB_API_KEY'], 'steam_web_api_key'],
        'gslt' => [['STEAM_ACC', 'STEAM_GSLT'], 'gslt'],
    ];

    /** Egg variables that carry the server's display name (browser name). */
    private const array NAME_VARIABLES = ['SERVER_HOSTNAME', 'SERVER_NAME'];

    public function __construct(private readonly PelicanClient $client) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  list<PelicanAllocation>  $allocations  Planned allocations: the first is the main
     *                                                port, each further one belongs to the
     *                                                config's `extra_ports` variable of the same
     *                                                position. Empty: the client picks one.
     * @param  string|null  $displayName  Human name for the server browser, sent through the
     *                                    egg's hostname variable unless the config sets it.
     * @param  list<string>  $requires  Preset requirements whose secrets are handed to the egg.
     *
     * @throws GameServerException
     */
    public function build(Game $game, string $eggId, array $config, string $name, array $allocations = [], ?string $displayName = null, array $requires = []): ServerSpec
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

        $displayName = self::sanitizeName($displayName);

        if ($displayName !== '') {
            foreach (self::NAME_VARIABLES as $variable) {
                if (array_key_exists($variable, $egg->variables) && ! array_key_exists($variable, $explicit)) {
                    $environment[$variable] = $displayName;
                    break;
                }
            }
        }

        foreach ($requires as $requirement) {
            [$variables, $configKey] = self::SECRETS[$requirement] ?? [[], ''];
            $secret = $configKey !== '' ? config("services.pelican.{$configKey}") : null;

            if (! is_string($secret) || $secret === '') {
                continue;
            }

            foreach ($variables as $variable) {
                if (array_key_exists($variable, $egg->variables)) {
                    $environment[$variable] = $secret;
                    break;
                }
            }
        }

        $extraPorts = array_values(is_array($config['extra_ports'] ?? null) ? $config['extra_ports'] : []);
        $additional = array_slice($allocations, 1);

        foreach ($extraPorts as $i => $variable) {
            if (isset($additional[$i])) {
                $environment[(string) $variable] = (string) $additional[$i]->port;
            }
        }

        $limits = new ServerLimits(
            memory: ResourceEstimate::for($game, $config),
            disk: isset($config['disk_mb']) ? (int) $config['disk_mb'] : (int) config('services.pelican.default_disk_mb'),
            cpu: isset($config['cpu_percent']) ? (int) $config['cpu_percent'] : (int) config('services.pelican.default_cpu_percent'),
            allocations: $allocations === [] ? null : count($allocations),
        );

        $nodeId = config('services.pelican.default_node_id');

        return new ServerSpec(
            name: $name,
            ownerUserId: (string) $owner,
            eggId: $eggId,
            environment: $environment,
            limits: $limits,
            allocationId: $allocations === [] ? null : $allocations[0]->id,
            nodeId: $nodeId !== null && $nodeId !== '' ? (string) $nodeId : null,
            additionalAllocationIds: array_map(static fn (PelicanAllocation $a): string => $a->id, $additional),
        );
    }

    /**
     * The name ends up on the server's startup line (inside double quotes), so
     * quotes, backslashes, `$`, backticks, `;` and parentheses are dropped.
     */
    private static function sanitizeName(?string $name): string
    {
        $clean = preg_replace('/[\\\\"\'`$;()<>|&\\x00-\\x1f]/u', '', (string) $name) ?? '';

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $clean)), 0, 64);
    }
}
