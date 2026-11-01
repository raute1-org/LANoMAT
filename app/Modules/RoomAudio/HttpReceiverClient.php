<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio;

use App\Modules\RoomAudio\Contracts\ReceiverClient;
use App\Modules\RoomAudio\Exceptions\ReceiverUnavailable;
use App\Modules\RoomAudio\Support\ReceiverState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to the denon-admin sidecar (docker/denon-admin/app.py): GET /state and
 * POST /command with a bearer token.
 *
 * Only state() retries transient failures (connection errors, 429, 5xx).
 * Setters never retry: a repeated volume step must not double-apply.
 */
class HttpReceiverClient implements ReceiverClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public function state(): ReceiverState
    {
        try {
            $response = $this->readHttp()->get("{$this->baseUrl}/state");
        } catch (ConnectionException) {
            return ReceiverState::unreachable();
        }

        if ($response->failed()) {
            return ReceiverState::unreachable();
        }

        $payload = $response->json();

        return is_array($payload)
            ? ReceiverState::fromArray($payload)
            : ReceiverState::unreachable();
    }

    public function setPower(bool $on): void
    {
        $this->send('power', $on ? 'on' : 'off');
    }

    public function setVolume(float $volume): void
    {
        $this->send('volume', $volume);
    }

    public function setMute(bool $mute): void
    {
        $this->send('mute', $mute ? 'on' : 'off');
    }

    public function setSource(string $code): void
    {
        $this->send('source', $code);
    }

    public function setMode(string $key): void
    {
        $this->send('mode', $key);
    }

    /**
     * Single attempt, no retry. 503 means the sidecar is up but the receiver
     * is not connected; any other failed status (422, 401, ...) is surfaced
     * as the RequestException so a bad input is never silently swallowed.
     *
     * @throws ReceiverUnavailable
     * @throws RequestException
     */
    private function send(string $command, string|float $value): void
    {
        try {
            $response = $this->http()->post("{$this->baseUrl}/command", [
                'command' => $command,
                'value' => $value,
            ]);
        } catch (ConnectionException) {
            throw ReceiverUnavailable::unreachable();
        }

        if ($response->status() === 503) {
            throw ReceiverUnavailable::unreachable();
        }

        $response->throw();
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)->acceptJson();
    }

    private function readHttp(): PendingRequest
    {
        return $this->http()
            ->retry(3, 100, when: function (Throwable $exception): bool {
                return $this->isTransient($exception);
            }, throw: false);
    }

    /**
     * Only connection errors and HTTP 429/5xx are worth retrying; a 401 or
     * 404 can never succeed on retry.
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
}
