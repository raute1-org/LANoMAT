<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Support\SteamActivity;

/**
 * What Steam says people are playing right now (the Web API's `GetPlayerSummaries`). Best effort
 * by design: a missing key, a private profile, a rate limit or any network failure means
 * "unknown", never an exception.
 */
interface SteamPresence
{
    /**
     * @param  list<string>  $steamIds  64-bit Steam ids (the `provider_user_id` of a linked Steam account)
     * @return array<string, SteamActivity|null> keyed by Steam id: the activity, or null when the person is
     *                                           known not to be in a game. An id that is absent is unknown
     *                                           (private profile, failed request, no API key).
     */
    public function activities(array $steamIds): array;
}
