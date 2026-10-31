<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanServer;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Support\AllocationPlanner;
use App\Modules\GameServers\Support\EggResolver;
use App\Modules\GameServers\Support\GuardrailPolicy;
use App\Modules\GameServers\Support\ServerSpecBuilder;

/**
 * Creates the Pelican server for one resolved config: guardrails, the preset's
 * egg (resolved by uuid), one free pool allocation per port the preset needs
 * (the main port plus one for each `extra_ports` entry), the builder, and
 * `createServer()`. The shared creation path of the match job and of
 * {@see StartServerFromPreset}; it does not touch any ServerLink.
 */
final class ProvisionGameServer
{
    public function __construct(private readonly PelicanClient $client) {}

    /**
     * @param  array<string, mixed>  $config  The effective config (`ServerConfig::toArray()` shape).
     * @param  string|null  $nodeId  The node to place the server on; null = `services.pelican.default_node_id`.
     * @param  bool  $permanent  Permanent servers do not count against the running/per-user caps (per-instance RAM/slot caps still apply).
     *
     * @throws GameServerException {@see GameServerException::noFreeAllocation()} when the port pool is exhausted
     */
    public function handle(Game $game, array $config, string $name, ?ServerPreset $preset = null, ?string $displayName = null, ?User $requester = null, ?int $excludingLinkId = null, ?string $nodeId = null, bool $permanent = false): PelicanServer
    {
        GuardrailPolicy::assertWithinLimits($game, $config, $requester, $excludingLinkId, countAgainstCaps: ! $permanent);

        $eggId = (new EggResolver($this->client))->resolve($game, $preset);

        $nodeId ??= config('services.pelican.default_node_id');

        if ($nodeId === null || $nodeId === '') {
            throw GameServerException::noNodeConfigured();
        }

        $extraPorts = is_array($config['extra_ports'] ?? null) ? count($config['extra_ports']) : 0;
        $allocations = AllocationPlanner::fromConfig($this->client)->plan((string) $nodeId, 1 + $extraPorts);

        $spec = (new ServerSpecBuilder($this->client))->build(
            $game,
            $eggId,
            $config,
            $name,
            allocations: $allocations,
            displayName: $displayName,
            requires: $preset !== null ? self::secretRequirements($preset) : [],
            nodeId: (string) $nodeId,
        );

        return $this->client->createServer($spec);
    }

    /**
     * The secrets handed to the egg: what the preset requires, plus the Steam Web API
     * key for any preset that downloads a Workshop collection.
     *
     * @return list<string>
     */
    private static function secretRequirements(ServerPreset $preset): array
    {
        $requires = $preset->requires;

        if ($preset->config->contentType === 'workshop' && ! in_array('authkey', $requires, true)) {
            $requires[] = 'authkey';
        }

        return $requires;
    }
}
