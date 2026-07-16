<?php

declare(strict_types=1);

namespace App\Modules\GameServers;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanServer;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to a Pelican Panel instance over its REST API. Server lifecycle
 * (create/get/delete) lives on the Application API (`/api/application/...`,
 * authenticated with an application API key `ptla_...`); power actions live
 * on the Client API (`/api/client/...`, conventionally a user/client API key
 * `ptlc_...`) — see Pelican's own docs split between "adjusting server
 * configuration" (Application) and "user-facing features" (Client). This
 * client is constructed with a single bearer token; callers are expected to
 * supply whichever token grants both scopes (Pelican's application tokens do
 * not carry client-API scopes and vice versa, so a real deployment may need
 * two distinct tokens — deferred to Task 4's provisioning wiring, which
 * decides how the app's config actually plumbs `client_token` through).
 *
 * The Application API's Server resource reports a nullable `status` string
 * rather than a fixed enum of "running"/"stopped": null means active/running,
 * `installing`/`install_failed`/`reinstall_failed` cover the install
 * pipeline, and `suspended` covers panel-side suspension. There is no
 * dedicated "stopped" status on this resource (that is a Wings/Client-API
 * power-state concept) — `toServer()` maps `install_failed`/
 * `reinstall_failed`/`suspended` to `ServerState::Failed` and null to
 * `ServerState::Running` as the closest fit, documented here since it is not
 * a literal 1:1 field mapping.
 */
class HttpPelicanClient implements PelicanClient
{
    public function __construct(
        private readonly string $panelUrl,
        private readonly string $applicationToken,
        private readonly ?string $nodeId,
    ) {}

    public function createServer(string $eggId, array $config, ?string $nodeId = null): PelicanServer
    {
        $payload = array_merge($config, ['egg_id' => $eggId]);

        $resolvedNodeId = $nodeId ?? $this->nodeId;
        if ($resolvedNodeId !== null) {
            $payload['node_id'] = $resolvedNodeId;
        }

        $response = $this->http()->post("{$this->panelUrl}/api/application/servers", $payload)->throw();

        return $this->toServer($response->json());
    }

    public function getServer(string $serverId): PelicanServer
    {
        $response = $this->http()->get("{$this->panelUrl}/api/application/servers/{$serverId}")->throw();

        return $this->toServer($response->json());
    }

    public function powerAction(string $serverId, PowerAction $action): void
    {
        $this->http()->post("{$this->panelUrl}/api/client/servers/{$serverId}/power", [
            'signal' => $action->value,
        ])->throw();
    }

    public function deleteServer(string $serverId): void
    {
        $this->http()->delete("{$this->panelUrl}/api/application/servers/{$serverId}")->throw();
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->applicationToken)
            ->acceptJson()
            ->retry(3, function (int $attempt, Throwable $exception) {
                return $this->retryDelayMilliseconds($exception);
            }, when: function (Throwable $exception): bool {
                return $this->isTransient($exception);
            }, throw: false);
    }

    /**
     * Only retry transient failures: connection errors and HTTP 429/5xx
     * responses. Non-transient client errors (401, 404, ...) can never
     * succeed on retry, so they are surfaced immediately instead of being
     * hammered against a permanently-broken endpoint. Mirrors
     * HttpMumbleClient's/HttpDiscordClient's transient predicate.
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
     * @param  array<string, mixed>  $payload
     */
    private function toServer(array $payload): PelicanServer
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $payload['attributes'];

        /** @var array<string, mixed>|null $allocation */
        $allocation = $attributes['allocation'] ?? null;

        return new PelicanServer(
            (string) $attributes['id'],
            $this->toState($attributes['status'] ?? null),
            $allocation !== null ? (string) $allocation['ip'] : null,
            $allocation !== null ? (int) $allocation['port'] : null,
            $attributes,
        );
    }

    private function toState(?string $status): ServerState
    {
        return match ($status) {
            null => ServerState::Running,
            'installing' => ServerState::Installing,
            'install_failed', 'reinstall_failed', 'suspended' => ServerState::Failed,
            default => ServerState::Stopped,
        };
    }
}
