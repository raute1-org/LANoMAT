<?php

declare(strict_types=1);

namespace App\Modules\Lighting;

use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Support\BridgeHealth;
use App\Modules\Lighting\Support\Fixture;
use App\Modules\Lighting\Support\FixtureReport;
use App\Modules\Lighting\Support\GameStatus;
use App\Modules\Lighting\Support\LightingResult;
use App\Modules\Lighting\Support\LightState;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
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

            $state = self::parseState($row);

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

    public function gameStatus(): GameStatus
    {
        $response = $this->attempt(fn () => $this->http()->get($this->url('/api/game')));

        if (! $response instanceof Response || $response->failed()) {
            return GameStatus::unreachable();
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $config = is_array($json['config'] ?? null) ? $json['config'] : [];
        $modes = array_values(array_filter(
            array_map('strval', array_keys($config)),
            fn (string $m) => GameStatus::isValidMode($m),
        ));

        return new GameStatus(
            true,
            ($json['active'] ?? false) === true,
            is_string($json['mode'] ?? null) && GameStatus::isValidMode($json['mode']) ? $json['mode'] : null,
            $modes,
        );
    }

    public function startGame(?int $durationSeconds = null, ?int $stepMs = null, ?string $mode = null): LightingResult
    {
        $body = array_filter([
            'mode' => $mode,
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
     * Reads one light of `GET /api/lights`: `red`/`green`/`blue` (0-255) become
     * the hex colour, `dimmer` (0-255) the brightness, `strobe` stays raw, and
     * `cold_white`/`warm_white` become the white level and temperature. Missing
     * fields stay unset; the older `hex`/`brightness`/... names still work.
     *
     * @param  array<array-key, mixed>  $row
     */
    private static function parseState(array $row): LightState
    {
        $byte = fn (mixed $v): ?int => is_numeric($v) ? max(0, min(255, (int) round((float) $v))) : null;

        $hex = is_string($row['hex'] ?? null) ? $row['hex'] : (is_string($row['color'] ?? null) ? $row['color'] : null);
        $r = $byte($row['red'] ?? null);
        $g = $byte($row['green'] ?? null);
        $b = $byte($row['blue'] ?? null);

        if ($r !== null || $g !== null || $b !== null) {
            $hex = sprintf('#%02x%02x%02x', $r ?? 0, $g ?? 0, $b ?? 0);
        }

        $brightness = $row['brightness'] ?? null;
        if (($d = $byte($row['dimmer'] ?? null)) !== null) {
            $brightness = $d / 255;
        }

        $temperature = $row['temperature'] ?? null;
        $white = $row['white_level'] ?? null;
        $cold = $byte($row['cold_white'] ?? null);
        $warm = $byte($row['warm_white'] ?? null);

        if ($cold !== null || $warm !== null) {
            $sum = ($cold ?? 0) + ($warm ?? 0);
            $white = max($cold ?? 0, $warm ?? 0) / 255;
            $temperature = $sum > 0 ? ($cold ?? 0) / $sum : $temperature;
        }

        try {
            return LightState::make(
                $hex,
                is_numeric($brightness) ? (float) $brightness : null,
                is_numeric($temperature) ? (float) $temperature : null,
                is_numeric($white) ? (float) $white : null,
                is_numeric($row['strobe'] ?? null) ? (float) $row['strobe'] : null,
            );
        } catch (InvalidArgumentException) {
            return new LightState;
        }
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
