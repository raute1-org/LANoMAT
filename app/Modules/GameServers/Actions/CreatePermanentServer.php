<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Actions;

use App\Models\User;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\QueryProtocol;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Jobs\PollServerStatusJob;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\PermanentServerRefresher;
use App\Modules\GameServers\Support\PresetAvailability;
use App\Modules\GameServers\Support\PresetOverrides;
use App\Modules\GameServers\Support\ReadinessPlan;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Creates a permanent game server: one that runs all year, independent of any
 * event, match or tournament (so `event_id`/`match_id`/`tournament_id` stay
 * null and no lifecycle listener ever touches it). One entry point per source:
 *
 * - {@see pelican()}: created on a chosen Pelican node from a game preset,
 *   installed in the background by {@see PollServerStatusJob};
 * - {@see docker()}: registers an existing container definition (Custom
 *   Servers) with the address players use;
 * - {@see external()}: registers a server LANoMAT neither runs nor controls.
 *
 * Authorization is checked here against the passed user (admin only).
 */
final class CreatePermanentServer
{
    public function __construct(
        private readonly PelicanClient $client,
        private readonly Modules $modules,
        private readonly PermanentServerRefresher $refresher,
    ) {}

    /**
     * @param  array<string, string>  $overrides  Environment overrides; only keys the preset lists as overridable.
     * @param  string|null  $nodeId  Pelican node; null = the configured default node.
     *
     * @throws GameServerException
     */
    public function pelican(User $actor, Game $game, string $presetKey, string $title, ?string $description, ?string $nodeId, array $overrides = []): ServerLink
    {
        Gate::forUser($actor)->authorize('managePermanent', ServerLink::class);

        $preset = $game->findPreset($presetKey) ?? throw GameServerException::presetNotFound($presetKey);

        PresetAvailability::assertAvailable($preset);

        $nodeId = $nodeId !== null && $nodeId !== '' ? $nodeId : null;
        $this->assertNodeUsable($nodeId);

        $config = PresetOverrides::apply($preset, $overrides);
        $title = $this->cleanTitle($title);

        $link = $this->newLink($actor, $game, $title, $description, ServerSource::Pelican);
        $link->pelican_node_id = $nodeId ?? (string) config('services.pelican.default_node_id');
        $link->preset_key = $preset->key;
        $link->query_protocol = QueryProtocol::tryFrom((string) $preset->config->readinessType);
        $link->status = ServerLinkStatus::Provisioning;
        $link->readiness = ReadinessPlan::snapshot($preset->config);
        $link->save();

        try {
            $server = (new ProvisionGameServer($this->client))->handle(
                $game,
                $config,
                "lanomat-permanent-{$game->slug}-{$preset->key}-{$link->id}",
                $preset,
                $title,
                $actor,
                excludingLinkId: $link->id,
                nodeId: $nodeId,
                permanent: true,
            );
        } catch (Throwable $e) {
            // Nothing was created on the panel, so the claimed row would only be noise.
            $link->delete();

            throw $e;
        }

        $link->pelican_server_id = $server->id;
        $link->pelican_server_identifier = $server->identifier;
        $link->save();

        PollServerStatusJob::dispatch($link->id)->delay(now()->addSeconds(10));

        return $link;
    }

    /**
     * @throws GameServerException
     */
    public function docker(User $actor, Game $game, CustomServer $container, string $title, ?string $description, string $address, int $port, ?QueryProtocol $protocol, ?string $connectString = null): ServerLink
    {
        Gate::forUser($actor)->authorize('managePermanent', ServerLink::class);

        if (! $this->modules->enabled(Module::CustomServers)) {
            throw GameServerException::dockerUnavailable();
        }

        $link = $this->registered($actor, $game, ServerSource::Docker, $title, $description, $address, $port, $protocol, $connectString);
        $link->custom_server_id = $container->id;
        $link->save();

        return $this->probed($link);
    }

    /**
     * @throws GameServerException
     */
    public function external(User $actor, Game $game, string $title, ?string $description, string $address, int $port, ?QueryProtocol $protocol, ?string $connectString = null): ServerLink
    {
        Gate::forUser($actor)->authorize('managePermanent', ServerLink::class);

        $link = $this->registered($actor, $game, ServerSource::External, $title, $description, $address, $port, $protocol, $connectString);
        $link->save();

        return $this->probed($link);
    }

    private function registered(User $actor, Game $game, ServerSource $source, string $title, ?string $description, string $address, int $port, ?QueryProtocol $protocol, ?string $connectString): ServerLink
    {
        $address = trim($address);

        if ($address === '' || $port < 1 || $port > 65535) {
            throw GameServerException::permanentInputInvalid('gameservers.permanent.errors.address_required');
        }

        $link = $this->newLink($actor, $game, $this->cleanTitle($title), $description, $source);
        $link->query_protocol = $protocol;
        // Without a status probe the server is assumed to be up; otherwise the first probe decides.
        $link->status = $protocol === null ? ServerLinkStatus::Ready : ServerLinkStatus::Offline;
        $link->join_info = new JoinInfo(
            address: $address,
            port: $port,
            connectString: $this->connectString($connectString, $protocol, $address, $port),
        );

        return $link;
    }

    /**
     * A Source server is joined through a `steam://connect/…` link (the default of the join-link
     * helper); every other game gets a plain `address:port` to paste into the game.
     */
    private function connectString(?string $given, ?QueryProtocol $protocol, string $address, int $port): ?string
    {
        if ($given !== null && trim($given) !== '') {
            return trim($given);
        }

        return $protocol === QueryProtocol::A2s ? null : "{$address}:{$port}";
    }

    /** Takes a first status reading right away; an unreachable server just stays Offline. */
    private function probed(ServerLink $link): ServerLink
    {
        try {
            $this->refresher->refresh($link);
        } catch (Throwable) {
            // The scheduled refresh retries; creating the entry must not fail on a probe.
        }

        return $link;
    }

    private function newLink(User $actor, Game $game, string $title, ?string $description, ServerSource $source): ServerLink
    {
        $link = new ServerLink([
            'game_id' => $game->id,
            'title' => $title,
            'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
            'requested_by' => $actor->id,
        ]);
        $link->permanent = true;
        $link->source = $source;

        return $link;
    }

    private function cleanTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            throw GameServerException::permanentInputInvalid('gameservers.permanent.errors.title_required');
        }

        return mb_substr($title, 0, 64);
    }

    /**
     * @throws GameServerException when a chosen node is unknown or in maintenance
     */
    private function assertNodeUsable(?string $nodeId): void
    {
        if ($nodeId === null) {
            return;
        }

        foreach ($this->client->listNodes() as $node) {
            if ($node->id === $nodeId) {
                if ($node->maintenanceMode) {
                    throw GameServerException::permanentInputInvalid('gameservers.permanent.errors.node_maintenance');
                }

                return;
            }
        }

        throw GameServerException::permanentInputInvalid('gameservers.permanent.errors.node_unknown');
    }
}
