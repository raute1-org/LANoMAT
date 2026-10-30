<?php

declare(strict_types=1);

namespace App\Modules\Identity\Connectors;

use App\Modules\Identity\Contracts\GuildMembershipChecker;
use App\Modules\Identity\Exceptions\GuildMembershipUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Calls Discord's "Get Current User Guild Member" endpoint with the user's own
 * OAuth token (requires the guilds.members.read scope). 200 means member;
 * 403/404 mean not a member; anything else is unknown and surfaces as
 * {@see GuildMembershipUnavailable}. Deliberately no retries: this runs inside
 * an interactive login, and a failed check simply asks the user to try again.
 */
final class HttpGuildMembershipChecker implements GuildMembershipChecker
{
    private const string BASE_URL = 'https://discord.com/api/v10';

    public function isMember(string $accessToken, string $guildId): bool
    {
        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->get(self::BASE_URL.'/users/@me/guilds/'.rawurlencode($guildId).'/member');
        } catch (ConnectionException) {
            throw GuildMembershipUnavailable::because('connection failed or timed out');
        }

        if ($response->successful()) {
            return true;
        }

        if (in_array($response->status(), [403, 404], true)) {
            return false;
        }

        throw GuildMembershipUnavailable::because("HTTP {$response->status()}");
    }
}
