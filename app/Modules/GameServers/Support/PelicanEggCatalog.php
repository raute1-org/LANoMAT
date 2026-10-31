<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Games\Contracts\EggCatalog;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Exceptions\GameServerException;
use Illuminate\Support\Facades\Cache;

/**
 * Panel-backed {@see EggCatalog}: the eggs from `listEggs()`, cached for a few
 * minutes so rendering the admin form does not hit the panel every time. A
 * failure (panel unreachable, unconfigured, key rejected) yields null, and that
 * outcome is cached briefly too, so an unreachable panel costs one connect
 * timeout per window, not one per form render.
 */
final class PelicanEggCatalog implements EggCatalog
{
    private const string KEY = 'gameservers.egg-catalog';

    private const int TTL_SECONDS = 300;

    private const int FAILURE_TTL_SECONDS = 30;

    /** @var array<string, string>|null|false false = not resolved yet in this request */
    private array|null|false $resolved = false;

    public function __construct(private readonly PelicanClient $client) {}

    public function options(): ?array
    {
        if ($this->resolved !== false) {
            return $this->resolved;
        }

        /** @var array{ok: bool, options: array<string, string>} $entry */
        $entry = Cache::remember(self::KEY, self::TTL_SECONDS, fn (): array => $this->fetch());

        if (! $entry['ok']) {
            // Re-check sooner than a successful result would be.
            Cache::put(self::KEY, $entry, self::FAILURE_TTL_SECONDS);
        }

        return $this->resolved = $entry['ok'] ? $entry['options'] : null;
    }

    /**
     * @return array{ok: bool, options: array<string, string>}
     */
    private function fetch(): array
    {
        try {
            $options = [];

            foreach ($this->client->listEggs() as $egg) {
                if ($egg->uuid !== null) {
                    $options[$egg->uuid] = $egg->name;
                }
            }

            asort($options);

            return ['ok' => true, 'options' => $options];
        } catch (GameServerException) {
            return ['ok' => false, 'options' => []];
        }
    }
}
