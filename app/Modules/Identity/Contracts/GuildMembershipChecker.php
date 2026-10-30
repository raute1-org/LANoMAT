<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Exceptions\GuildMembershipUnavailable;
use App\Modules\Identity\Testing\FakeGuildMembershipChecker;

/**
 * Answers whether the Discord user behind an OAuth access token is a member of
 * a guild. Login gate only: real HTTP lives in the connector, tests use
 * {@see FakeGuildMembershipChecker}.
 */
interface GuildMembershipChecker
{
    /**
     * @return bool true when the user is a member, false when they are not.
     *
     * @throws GuildMembershipUnavailable when the answer is unknown (outage,
     *                                    rate limit, timeout). Callers must
     *                                    fail closed.
     */
    public function isMember(string $accessToken, string $guildId): bool;
}
