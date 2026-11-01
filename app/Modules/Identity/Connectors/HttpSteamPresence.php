<?php

declare(strict_types=1);

namespace App\Modules\Identity\Connectors;

use App\Modules\Identity\Contracts\SteamPresence;
use App\Modules\Identity\Support\SteamActivity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * `ISteamUser/GetPlayerSummaries` in batches of up to 100 ids, with the key the Steam login
 * already uses (`services.steam.client_secret`). Results are cached per id for
 * {@see self::TTL_SECONDS}; a failure is never cached as "not playing" and a 429 or 5xx pauses
 * all calls for {@see self::BACKOFF_SECONDS} so a flaky Steam is not hammered.
 *
 * A profile that is not public hides `gameid`; such a person comes back as "not in a game"
 * (indistinguishable from really being idle, and the right answer for a feature that must
 * respect privacy).
 */
class HttpSteamPresence implements SteamPresence
{
    public const int TTL_SECONDS = 30;

    public const int BACKOFF_SECONDS = 60;

    private const int BATCH = 100;

    private const string URL = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/';

    public function activities(array $steamIds): array
    {
        $apiKey = config('services.steam.client_secret');

        if (! is_string($apiKey) || $apiKey === '') {
            return [];
        }

        $result = [];
        $missing = [];

        foreach (array_values(array_unique($steamIds)) as $id) {
            $cached = Cache::get($this->cacheKey($id));

            if ($cached === null) {
                $missing[] = $id;

                continue;
            }

            $result[$id] = is_array($cached) ? SteamActivity::fromArray($cached) : null;
        }

        if ($missing === [] || Cache::has('steam.presence.backoff')) {
            return $result;
        }

        foreach (array_chunk($missing, self::BATCH) as $batch) {
            $fetched = $this->fetch($apiKey, $batch);

            if ($fetched === null) {
                break; // Steam is unhappy: everything not cached stays unknown.
            }

            foreach ($batch as $id) {
                if (! array_key_exists($id, $fetched)) {
                    continue; // Steam did not return this profile at all: unknown.
                }

                $activity = $fetched[$id];
                $result[$id] = $activity;
                Cache::put($this->cacheKey($id), $activity?->toArray() ?? false, self::TTL_SECONDS);
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $batch
     * @return array<string, SteamActivity|null>|null null when the request failed
     */
    private function fetch(string $apiKey, array $batch): ?array
    {
        try {
            $response = Http::timeout(5)->connectTimeout(3)->get(self::URL, [
                'key' => $apiKey,
                'steamids' => implode(',', $batch),
                'format' => 'json',
            ]);

            if ($response->status() === 429 || $response->serverError()) {
                Cache::put('steam.presence.backoff', true, self::BACKOFF_SECONDS);

                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            /** @var mixed $players */
            $players = $response->json('response.players');
        } catch (ConnectionException|RequestException) {
            Cache::put('steam.presence.backoff', true, self::BACKOFF_SECONDS);

            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! is_array($players)) {
            return null;
        }

        $out = [];

        foreach ($players as $player) {
            if (! is_array($player) || ! is_string($player['steamid'] ?? null)) {
                continue;
            }

            $gameId = $player['gameid'] ?? null;
            $out[$player['steamid']] = is_string($gameId) || is_int($gameId)
                ? new SteamActivity(
                    gameId: (string) $gameId,
                    gameName: is_string($player['gameextrainfo'] ?? null) ? $player['gameextrainfo'] : null,
                    serverAddress: is_string($player['gameserverip'] ?? null) && $player['gameserverip'] !== '' && $player['gameserverip'] !== '0.0.0.0:0'
                        ? $player['gameserverip'] : null,
                    personaName: is_string($player['personaname'] ?? null) ? $player['personaname'] : null,
                )
                : null;
        }

        return $out;
    }

    private function cacheKey(string $steamId): string
    {
        return 'steam.presence.'.$steamId;
    }
}
