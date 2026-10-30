<?php

declare(strict_types=1);

namespace App\Modules\GameServers;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanAllocation;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Domain\PelicanNode;
use App\Modules\GameServers\Domain\PelicanServer;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Domain\ServerLimits;
use App\Modules\GameServers\Domain\ServerSpec;
use App\Modules\GameServers\Domain\ServerStats;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Exceptions\GameServerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

/**
 * Talks to a Pelican Panel over its two REST APIs (see {@see PelicanClient}).
 *
 * Auth: the Application API wants an application key (`papp_...`, admin
 * panel > API keys), the Client API a client key (`pacc_...`, a user's
 * account API key). A root admin's client key is also accepted by application
 * endpoints, so the client key should belong to a NON-admin service user that
 * owns the servers LANoMAT creates (`services.pelican.owner_user_id`) — the
 * Client API only exposes servers its user may access (404 otherwise).
 *
 * Real-API facts this class encodes (verified against a live panel; see
 * docs/gameservers-pelican.md): `attributes.allocation` is an integer id (ip
 * and port come from the allocation resource); the Application API `status`
 * is null once installed, so runtime state comes from the Client API
 * `/resources` endpoint; power and resources are addressed by the short
 * `identifier`; `/eggs` lists carry no pagination `meta`; errors arrive as
 * `{errors:[{code,status,detail,meta}]}`.
 */
class HttpPelicanClient implements PelicanClient
{
    private const int PER_PAGE = 100;

    private const int MAX_PAGES = 50;

    public function __construct(
        private readonly string $panelUrl,
        private readonly string $applicationToken,
        private readonly string $clientToken,
        private readonly ?string $defaultNodeId = null,
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
    ) {}

    public function createServer(ServerSpec $spec): PelicanServer
    {
        $nodeId = $spec->nodeId ?? $this->defaultNodeId;
        $allocation = null;
        $allocationId = $spec->allocationId;

        if ($allocationId === null) {
            if ($nodeId === null || $nodeId === '') {
                throw GameServerException::noNodeConfigured();
            }

            $allocation = $this->firstFreeAllocation($nodeId);
            $allocationId = $allocation->id;
        }

        $payload = [
            'name' => $spec->name,
            'user' => (int) $spec->ownerUserId,
            'egg' => (int) $spec->eggId,
            'environment' => $spec->environment === [] ? new stdClass : $spec->environment,
            'limits' => $spec->limits->toLimitsPayload(),
            'feature_limits' => $spec->limits->toFeatureLimitsPayload(),
            'allocation' => ['default' => (int) $allocationId] + ($spec->additionalAllocationIds === [] ? [] : ['additional' => array_map('intval', $spec->additionalAllocationIds)]),
            'start_on_completion' => $spec->startOnCompletion,
        ];

        // No retry: a replayed POST after a timeout could create a second
        // real server.
        $response = $this->send(false, 'POST', '/api/application/servers', json: $payload, retry: false);

        $attributes = $this->attributes($response);

        return $this->toServer($attributes, $allocation ?? $this->resolveAllocation(
            (string) ($attributes['node'] ?? $nodeId ?? ''),
            (string) $allocationId,
        ));
    }

    public function getServer(string $serverId): PelicanServer
    {
        $response = $this->send(false, 'GET', "/api/application/servers/{$serverId}", ['include' => 'allocations'], what: "server {$serverId}");
        $attributes = $this->attributes($response);

        $allocationId = $this->scalarId($attributes['allocation'] ?? null);
        $allocation = $this->allocationFromRelationships($attributes, $allocationId)
            ?? ($allocationId !== null ? $this->resolveAllocation((string) ($attributes['node'] ?? ''), $allocationId) : null);

        $server = $this->toServer($attributes, $allocation);

        if ($server->state !== ServerState::Unknown || $server->identifier === null) {
            return $server;
        }

        return $this->withState($server, $this->runtimeState($server->identifier));
    }

    public function listServers(): array
    {
        return array_map(
            fn (array $row): PelicanServer => $this->toServer($this->rowAttributes($row), null),
            $this->paginate(false, '/api/application/servers'),
        );
    }

    public function powerAction(string $identifier, PowerAction $action): void
    {
        $this->send(true, 'POST', "/api/client/servers/{$identifier}/power", json: ['signal' => $action->value], what: "server {$identifier}");
    }

    public function getResources(string $identifier): ServerStats
    {
        $response = $this->send(true, 'GET', "/api/client/servers/{$identifier}/resources", what: "server {$identifier}");
        $attributes = $this->attributes($response);

        /** @var array<string, mixed> $resources */
        $resources = is_array($attributes['resources'] ?? null) ? $attributes['resources'] : [];

        return new ServerStats(
            state: $this->mapCurrentState((string) ($attributes['current_state'] ?? '')),
            suspended: (bool) ($attributes['is_suspended'] ?? false),
            memoryBytes: (int) ($resources['memory_bytes'] ?? 0),
            cpuAbsolute: (float) ($resources['cpu_absolute'] ?? 0),
            diskBytes: (int) ($resources['disk_bytes'] ?? 0),
            uptimeMs: (int) ($resources['uptime'] ?? 0),
        );
    }

    public function listClientServerIdentifiers(): array
    {
        $identifiers = [];

        foreach ($this->paginate(true, '/api/client') as $row) {
            $identifier = $this->rowAttributes($row)['identifier'] ?? null;

            if (is_string($identifier)) {
                $identifiers[] = $identifier;
            }
        }

        return $identifiers;
    }

    public function deleteServer(string $serverId): void
    {
        try {
            $this->send(false, 'DELETE', "/api/application/servers/{$serverId}", what: "server {$serverId}");
        } catch (GameServerException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }
        }
    }

    public function listNodes(): array
    {
        return array_map(function (array $row): PelicanNode {
            $a = $this->rowAttributes($row);

            return new PelicanNode(
                id: (string) $a['id'],
                name: (string) ($a['name'] ?? ''),
                fqdn: (string) ($a['fqdn'] ?? ''),
                maintenanceMode: (bool) ($a['maintenance_mode'] ?? false),
                memoryMb: (int) ($a['memory'] ?? 0),
                diskMb: (int) ($a['disk'] ?? 0),
            );
        }, $this->paginate(false, '/api/application/nodes'));
    }

    public function listAllocations(string $nodeId): array
    {
        return array_map(
            fn (array $row): PelicanAllocation => $this->toAllocation($this->rowAttributes($row)),
            $this->paginate(false, "/api/application/nodes/{$nodeId}/allocations"),
        );
    }

    public function listEggs(): array
    {
        return array_map(
            fn (array $row): PelicanEgg => $this->toEgg($this->rowAttributes($row)),
            $this->paginate(false, '/api/application/eggs'),
        );
    }

    public function getEgg(string $eggId): PelicanEgg
    {
        $response = $this->send(false, 'GET', "/api/application/eggs/{$eggId}", ['include' => 'variables'], what: "egg {$eggId}");

        return $this->toEgg($this->attributes($response));
    }

    public function importEgg(string $content): PelicanEgg
    {
        // No retry: a replay is harmless (upsert by uuid) but a 403 is final anyway.
        $response = $this->send(false, 'POST', '/api/application/eggs/import', retry: false, what: 'egg import', body: $content);

        return $this->toEgg($this->attributes($response));
    }

    public function createAllocations(string $nodeId, string $ip, array $ports): void
    {
        $this->send(false, 'POST', "/api/application/nodes/{$nodeId}/allocations", json: ['ip' => $ip, 'ports' => $ports], retry: false, what: "node {$nodeId}");
    }

    public function listFiles(string $identifier, string $directory = '/'): array
    {
        $response = $this->send(true, 'GET', "/api/client/servers/{$identifier}/files/list", ['directory' => $directory], what: "server {$identifier}");
        $rows = $response->json('data');
        $names = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = is_array($row) ? ($this->rowAttributes($row)['name'] ?? null) : null;

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function reinstallServer(string $serverId): void
    {
        $this->send(false, 'POST', "/api/application/servers/{$serverId}/reinstall", retry: false, what: "server {$serverId}");
    }

    // -- transport ----------------------------------------------------------

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $json
     *
     * @throws GameServerException
     */
    private function send(bool $clientApi, string $method, string $path, array $query = [], ?array $json = null, bool $retry = true, string $what = '', ?string $body = null): Response
    {
        $token = $clientApi ? $this->clientToken : $this->applicationToken;

        if ($this->panelUrl === '' || $token === '') {
            throw GameServerException::pelicanNotConfigured();
        }

        $request = Http::withToken($token)
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds);

        if ($retry) {
            $request = $request->retry(3, fn (int $attempt, Throwable $e): int => $this->retryDelayMilliseconds($e), when: fn (Throwable $e): bool => $this->isTransient($e), throw: false);
        }

        $url = rtrim($this->panelUrl, '/').$path;

        try {
            $response = $body !== null
                ? $request->withBody($body, 'application/yaml')->post($url)
                : $this->dispatch($request, $method, $url, $query, $json);
        } catch (ConnectionException $e) {
            throw GameServerException::pelicanUnavailable(null, 'Connection failed.');
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->mapFailure($response, $what !== '' ? $what : $path);
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $json
     */
    private function dispatch(PendingRequest $request, string $method, string $url, array $query, ?array $json): Response
    {
        return match ($method) {
            'GET' => $request->get($url, $query),
            'DELETE' => $request->delete($url, $json ?? []),
            default => $request->post($url, $json ?? []),
        };
    }

    private function mapFailure(Response $response, string $what): GameServerException
    {
        $status = $response->status();

        /** @var list<array<string, mixed>> $errors */
        $errors = is_array($response->json('errors')) ? $response->json('errors') : [];
        $details = array_values(array_filter(array_map(
            static fn (array $error): string => (string) ($error['detail'] ?? ''),
            $errors,
        )));

        return match (true) {
            $status === 401, $status === 403 => GameServerException::pelicanUnauthorized($status, $details[0] ?? ''),
            $status === 404 => GameServerException::pelicanNotFound($what),
            $status === 409 => GameServerException::pelicanServerBusy($details[0] ?? ''),
            $status === 422 => GameServerException::pelicanRejected($details),
            default => GameServerException::pelicanUnavailable($status, $details[0] ?? ''),
        };
    }

    /**
     * Only retry transient failures: connection errors and HTTP 429/5xx.
     * Mirrors HttpMumbleClient's/HttpDiscordClient's predicate.
     */
    private function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    private function retryDelayMilliseconds(Throwable $exception): int
    {
        if ($exception instanceof RequestException
            && $exception->response->status() === 429) {
            $retryAfter = $exception->response->header('Retry-After');

            if (is_numeric($retryAfter)) {
                return (int) round(((float) $retryAfter) * 1000);
            }
        }

        return 100;
    }

    /**
     * Follows `meta.pagination` over every page; a list without `meta`
     * (`/eggs` on the real panel) is a single page.
     *
     * @return list<array<string, mixed>>
     */
    private function paginate(bool $clientApi, string $path): array
    {
        $rows = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->send($clientApi, 'GET', $path, ['per_page' => self::PER_PAGE, 'page' => $page], what: $path);

            /** @var list<array<string, mixed>> $data */
            $data = is_array($response->json('data')) ? $response->json('data') : [];
            array_push($rows, ...$data);

            $current = $response->json('meta.pagination.current_page');
            $total = $response->json('meta.pagination.total_pages');

            if (! is_numeric($current) || ! is_numeric($total) || (int) $current >= (int) $total) {
                break;
            }
        }

        return $rows;
    }

    // -- mapping ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function attributes(Response $response): array
    {
        $attributes = $response->json('attributes');

        if (! is_array($attributes)) {
            throw GameServerException::pelicanUnavailable($response->status(), 'Unexpected response shape.');
        }

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowAttributes(array $row): array
    {
        /** @var array<string, mixed> $attributes */
        $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function toServer(array $attributes, ?PelicanAllocation $allocation): PelicanServer
    {
        $limits = is_array($attributes['limits'] ?? null)
            ? ServerLimits::fromPayload($attributes['limits'], is_array($attributes['feature_limits'] ?? null) ? $attributes['feature_limits'] : [])
            : null;

        $allocationId = $this->scalarId($attributes['allocation'] ?? null) ?? $allocation?->id;

        return new PelicanServer(
            id: (string) $attributes['id'],
            state: $this->mapApplicationStatus($attributes),
            address: $allocation?->address(),
            port: $allocation?->port,
            identifier: isset($attributes['identifier']) ? (string) $attributes['identifier'] : null,
            uuid: isset($attributes['uuid']) ? (string) $attributes['uuid'] : null,
            name: isset($attributes['name']) ? (string) $attributes['name'] : null,
            nodeId: isset($attributes['node']) ? (string) $attributes['node'] : null,
            allocationId: $allocationId,
            limits: $limits,
            meta: $attributes,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function mapApplicationStatus(array $attributes): ServerState
    {
        if ($attributes['suspended'] ?? false) {
            return ServerState::Failed;
        }

        return match ($attributes['status'] ?? null) {
            'installing' => ServerState::Installing,
            'install_failed', 'reinstall_failed', 'suspended' => ServerState::Failed,
            default => ServerState::Unknown,
        };
    }

    private function mapCurrentState(string $state): ServerState
    {
        return match ($state) {
            'running' => ServerState::Running,
            'starting' => ServerState::Starting,
            'stopping' => ServerState::Stopping,
            'offline' => ServerState::Stopped,
            default => ServerState::Unknown,
        };
    }

    /**
     * A 409 from the Client API means the server is still installing (or
     * suspended/transferring), which the Application API does not report in
     * the instant after creation.
     */
    private function runtimeState(string $identifier): ServerState
    {
        try {
            $stats = $this->getResources($identifier);

            return $stats->suspended ? ServerState::Failed : $stats->state;
        } catch (GameServerException $e) {
            if ($e->httpStatus === 409) {
                return ServerState::Installing;
            }

            if ($e->httpStatus === 404) {
                throw GameServerException::pelicanNotFound("client server {$identifier} (the client key's user must own or have access to the server)");
            }

            throw $e;
        }
    }

    private function withState(PelicanServer $server, ServerState $state): PelicanServer
    {
        return new PelicanServer(
            $server->id, $state, $server->address, $server->port, $server->identifier, $server->uuid,
            $server->name, $server->nodeId, $server->allocationId, $server->limits, $server->meta,
        );
    }

    private function scalarId(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_int($value) || is_string($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function allocationFromRelationships(array $attributes, ?string $allocationId): ?PelicanAllocation
    {
        $rows = $attributes['relationships']['allocations']['data'] ?? null;

        if (! is_array($rows) || $allocationId === null) {
            return null;
        }

        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['attributes']['id'] ?? '') === $allocationId) {
                return $this->toAllocation($this->rowAttributes($row));
            }
        }

        return null;
    }

    private function resolveAllocation(string $nodeId, string $allocationId): ?PelicanAllocation
    {
        if ($nodeId === '') {
            return null;
        }

        foreach ($this->listAllocations($nodeId) as $allocation) {
            if ($allocation->id === $allocationId) {
                return $allocation;
            }
        }

        return null;
    }

    private function firstFreeAllocation(string $nodeId): PelicanAllocation
    {
        foreach ($this->listAllocations($nodeId) as $allocation) {
            if (! $allocation->assigned) {
                return $allocation;
            }
        }

        throw GameServerException::noFreeAllocation($nodeId);
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function toAllocation(array $a): PelicanAllocation
    {
        return new PelicanAllocation(
            id: (string) $a['id'],
            ip: (string) ($a['ip'] ?? ''),
            port: (int) ($a['port'] ?? 0),
            alias: isset($a['alias']) ? (string) $a['alias'] : null,
            assigned: (bool) ($a['assigned'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function toEgg(array $a): PelicanEgg
    {
        $variables = [];
        $required = [];
        $rows = $a['relationships']['variables']['data'] ?? [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $v = is_array($row) ? $this->rowAttributes($row) : [];
            $name = (string) ($v['env_variable'] ?? '');

            if ($name === '') {
                continue;
            }

            $variables[$name] = (string) ($v['default_value'] ?? '');
            $rules = $v['rules'] ?? [];
            $rules = is_array($rules) ? $rules : explode('|', (string) $rules);

            if (in_array('required', $rules, true)) {
                $required[] = $name;
            }
        }

        return new PelicanEgg(
            (string) $a['id'],
            (string) ($a['name'] ?? ''),
            $variables,
            $required,
            isset($a['uuid']) && is_string($a['uuid']) ? $a['uuid'] : null,
        );
    }
}
