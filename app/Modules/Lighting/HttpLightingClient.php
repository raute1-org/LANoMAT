<?php

declare(strict_types=1);

namespace App\Modules\Lighting;

use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Support\BridgeHealth;
use App\Modules\Lighting\Support\Fixture;
use App\Modules\Lighting\Support\FixtureReport;
use App\Modules\Lighting\Support\LightingResult;
use App\Modules\Lighting\Support\LightState;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to the DMX light bridge's REST API (FastAPI, /docs). Short timeout, no
 * retries (a repeated command is harmless but a hung request is not), and no
 * exception ever leaves this class. The token is never logged.
 */
final class HttpLightingClient implements LightingClient
{
    private readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly ?string $token = null,
        private readonly int $timeout = 2,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function health(): BridgeHealth
    {
        $response = $this->attempt(fn () => $this->http()->get($this->url('/api/health')));

        if (! $response instanceof Response) {
            return BridgeHealth::unreachable();
        }

        if ($response->failed()) {
            return new BridgeHealth(true, false, 'HTTP '.$response->status());
        }

        $json = $response->json();
        /** @var array<string, mixed> $details */
        $details = is_array($json) ? $json : [];
        $status = $details['ok'] ?? $details['status'] ?? null;
        $healthy = ($status === null || $status === true || (is_string($status) && in_array(strtolower($status), ['ok', 'healthy', 'up'], true)))
            && ($details['error'] ?? null) === null;

        $count = $details['fixtures'] ?? null;
        $channels = $details['channels_per_fixture'] ?? null;
        $starts = is_array($details['start_addresses'] ?? null)
            ? array_values(array_filter($details['start_addresses'], 'is_int'))
            : [];

        return new BridgeHealth(
            true,
            $healthy,
            '',
            $details,
            is_int($count) && $count >= 1 && $count <= Fixture::MAX_ID ? $count : null,
            is_int($channels) && $channels >= 1 && $channels <= 512 ? $channels : null,
            $starts,
        );
    }

    public function fixtures(): FixtureReport
    {
        $response = $this->attempt(fn () => $this->http()->get($this->url('/api/lights')));

        if (! $response instanceof Response || $response->failed()) {
            return FixtureReport::unreachable();
        }

        $json = $response->json();
        $rows = is_array($json) ? ($json['lights'] ?? $json['fixtures'] ?? $json) : [];
        $fixtures = [];

        foreach (is_array($rows) ? $rows : [] as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? $key;

            if (! is_numeric($id) || ! Fixture::validId((int) $id)) {
                continue;
            }

            try {
                $state = LightState::fromArray([
                    'hex' => $row['hex'] ?? $row['color'] ?? null,
                    'brightness' => $row['brightness'] ?? null,
                    'temperature' => $row['temperature'] ?? null,
                    'white_level' => $row['white_level'] ?? null,
                    'strobe' => $row['strobe'] ?? null,
                ]);
            } catch (Throwable) {
                $state = new LightState;
            }

            $fixtures[] = new Fixture((int) $id, $state);
        }

        usort($fixtures, fn (Fixture $a, Fixture $b) => $a->id <=> $b->id);

        return new FixtureReport(true, $fixtures, new DateTimeImmutable);
    }

    public function setFixture(int $id, LightState $state): LightingResult
    {
        if (! Fixture::validId($id)) {
            return LightingResult::blocked('invalid fixture id');
        }

        return $this->command('PUT', "/api/lights/{$id}", $state->toPayload());
    }

    public function setAll(LightState $state): LightingResult
    {
        return $this->command('POST', '/api/lights/all', $state->toPayload());
    }

    public function setBulk(array $items): LightingResult
    {
        $payload = array_map(
            fn ($item) => ['id' => $item->id, ...$item->state->toPayload()],
            $items,
        );

        return $this->command('PUT', '/api/lights/bulk', ['items' => $payload]);
    }

    public function pattern(string $a, string $b, float $brightness = 1.0): LightingResult
    {
        return $this->command('POST', '/api/pattern', [
            'a' => LightState::normalizeHex($a),
            'b' => LightState::normalizeHex($b),
            'brightness' => max(0.0, min(1.0, $brightness)),
        ]);
    }

    public function blackout(): LightingResult
    {
        return $this->command('POST', '/api/blackout', null);
    }

    public function startGame(?int $durationSeconds = null, ?int $stepMs = null): LightingResult
    {
        $body = array_filter([
            'duration_s' => $durationSeconds,
            'step_ms' => $stepMs,
        ], fn ($v) => $v !== null);

        return $this->command('POST', '/api/game', $body === [] ? null : $body);
    }

    public function cancelGame(): LightingResult
    {
        return $this->command('DELETE', '/api/game', null);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function command(string $method, string $path, ?array $body): LightingResult
    {
        $response = $this->attempt(fn () => $this->http()->send($method, $this->url($path), $body === null ? [] : ['json' => $body]));

        if (! $response instanceof Response) {
            return LightingResult::unreachable();
        }

        return $response->successful()
            ? LightingResult::ok()
            : LightingResult::rejected('HTTP '.$response->status());
    }

    /**
     * @param  callable(): Response  $call
     */
    private function attempt(callable $call): ?Response
    {
        try {
            return $call();
        } catch (ConnectionException) {
            return null;
        } catch (Throwable $e) {
            // Class name only: the message of a request exception can echo the URL.
            report(new \RuntimeException('Lighting bridge call failed: '.$e::class));

            return null;
        }
    }

    private function http(): PendingRequest
    {
        $request = Http::timeout($this->timeout)->connectTimeout($this->timeout)->acceptJson()->withoutRedirecting();

        return $this->token !== null && $this->token !== '' ? $request->withToken($this->token) : $request;
    }

    private function url(string $path): string
    {
        return $this->baseUrl.$path;
    }
}
