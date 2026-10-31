<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Contracts;

use App\Modules\GameServers\Domain\PelicanAllocation;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Domain\PelicanNode;
use App\Modules\GameServers\Domain\PelicanServer;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Domain\ServerSpec;
use App\Modules\GameServers\Domain\ServerStats;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Exceptions\GameServerException;

/**
 * The single seam to a Pelican Panel. Behind it the real implementation talks
 * to two distinct APIs with two distinct token types:
 *
 * - Application API (`/api/application/*`, key `papp_...`): create, show, list
 *   and delete servers; list nodes, allocations and eggs.
 * - Client API (`/api/client/*`, key `pacc_...`, owned by a NON-admin service
 *   user): power signals and runtime state, addressed by the server's short
 *   `identifier`, never by its numeric id.
 *
 * Every failure surfaces as a {@see GameServerException} (translation key for
 * the UI), never as a raw HTTP client exception.
 */
interface PelicanClient
{
    /**
     * Creates a server (Application API). Never retried on transient errors: a
     * replayed POST could create a second real server.
     *
     * @throws GameServerException
     */
    public function createServer(ServerSpec $spec): PelicanServer;

    /**
     * One server with its allocation resolved to address/port and — once it is
     * installed — its real runtime state merged in from the Client API. While
     * the panel reports `installing`/`install_failed`/`suspended`, that status
     * wins and the Client API is not asked.
     *
     * @throws GameServerException
     */
    public function getServer(string $serverId): PelicanServer;

    /**
     * All servers (Application API, every page). `state` is
     * {@see ServerState::Unknown} for installed
     * servers — ask {@see getResources()} for the live state.
     *
     * @return list<PelicanServer>
     *
     * @throws GameServerException
     */
    public function listServers(): array;

    /**
     * Sends a power signal (Client API). `$identifier` is the server's short
     * uuid ({@see PelicanServer::$identifier}), not its numeric id.
     *
     * @throws GameServerException
     */
    public function powerAction(string $identifier, PowerAction $action): void;

    /**
     * Runtime state and resource usage (Client API).
     *
     * @throws GameServerException
     */
    public function getResources(string $identifier): ServerStats;

    /**
     * Identifiers of the servers the Client API key's user can see
     * (`GET /api/client`). Doubles as the cheapest "is the client key valid
     * and scoped right" probe.
     *
     * @return list<string>
     *
     * @throws GameServerException
     */
    public function listClientServerIdentifiers(): array;

    /**
     * Deletes a server (Application API). Already-gone (404) counts as success
     * so retried cleanup jobs stay idempotent.
     *
     * @throws GameServerException
     */
    public function deleteServer(string $serverId): void;

    /**
     * @return list<PelicanNode>
     *
     * @throws GameServerException
     */
    public function listNodes(): array;

    /**
     * @return list<PelicanAllocation>
     *
     * @throws GameServerException
     */
    public function listAllocations(string $nodeId): array;

    /**
     * @return list<PelicanEgg> Without variables.
     *
     * @throws GameServerException
     */
    public function listEggs(): array;

    /**
     * One egg including its variables and their defaults.
     *
     * @throws GameServerException
     */
    public function getEgg(string $eggId): PelicanEgg;

    /**
     * Imports (or updates, matched by the egg's uuid) an egg from its YAML or
     * JSON content (Application API `POST /eggs/import`, needs write scope on
     * eggs). Idempotent: re-importing the same uuid overwrites the egg.
     *
     * @throws GameServerException
     */
    public function importEgg(string $content): PelicanEgg;

    /**
     * Creates allocations for the given ports (single ports or `a-b` ranges)
     * on the node's address (Application API, needs write scope on
     * allocations; answers 204, so list again afterwards).
     *
     * @param  list<string>  $ports
     *
     * @throws GameServerException
     */
    public function createAllocations(string $nodeId, string $ip, array $ports): void;

    /**
     * Names of the entries of a server directory (Client API `files/list`).
     *
     * @return list<string>
     *
     * @throws GameServerException
     */
    public function listFiles(string $identifier, string $directory = '/'): array;

    /**
     * Reinstalls a server (Application API); the panel reports `installing`
     * again afterwards.
     *
     * @throws GameServerException
     */
    public function reinstallServer(string $serverId): void;
}
