<?php

declare(strict_types=1);

namespace App\Modules\Voice;

use App\Modules\Voice\Contracts\MumbleClient;
use App\Modules\Voice\Domain\MumbleChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to the mumble-admin Ice-REST sidecar (docker/mumble-admin/app.py,
 * Task 19) over its small REST surface: GET/POST /channels and
 * PATCH|DELETE /channels/{id}. The sidecar's `ChannelOut` uses `parent`
 * (0 = root) rather than a nullable `parentId`; this client normalizes that
 * to the domain's `?int $parentId` (0 => null) at the boundary.
 */
class HttpMumbleClient implements MumbleClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public function createChannel(string $name, ?int $parentId = null, bool $temporary = false): MumbleChannel
    {
        // NOTE: per app.py's create_channel docstring, Murmur's Ice API
        // cannot flip a channel to temporary after creation, and there is no
        // Ice call to create one as temporary directly either — the sidecar
        // accepts this field for forward-compatibility only. It currently
        // has no server-side effect; callers needing cleanup must delete
        // the channel explicitly (Task 21).
        $response = $this->http()->post("{$this->baseUrl}/channels", [
            'name' => $name,
            'parent' => $parentId ?? 0,
            'temporary' => $temporary,
        ])->throw();

        return $this->toChannel($response->json());
    }

    public function renameChannel(int $channelId, string $name): void
    {
        $this->http()->patch("{$this->baseUrl}/channels/{$channelId}", [
            'name' => $name,
        ])->throw();
    }

    public function deleteChannel(int $channelId): void
    {
        $this->http()->delete("{$this->baseUrl}/channels/{$channelId}")->throw();
    }

    public function listChannels(): array
    {
        $response = $this->http()->get("{$this->baseUrl}/channels")->throw();

        /** @var array<int, array<string, mixed>> $payload */
        $payload = $response->json();

        return array_map(fn (array $channel) => $this->toChannel($channel), $payload);
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)
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
     * HttpDiscordClient's transient predicate (Task 16).
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
    private function toChannel(array $payload): MumbleChannel
    {
        $parent = (int) $payload['parent'];

        return new MumbleChannel(
            (int) $payload['id'],
            (string) $payload['name'],
            $parent === 0 ? null : $parent,
            (bool) $payload['temporary'],
        );
    }
}
