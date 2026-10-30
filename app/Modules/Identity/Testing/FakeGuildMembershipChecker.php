<?php

declare(strict_types=1);

namespace App\Modules\Identity\Testing;

use App\Modules\Identity\Contracts\GuildMembershipChecker;
use App\Modules\Identity\Exceptions\GuildMembershipUnavailable;

/**
 * Scriptable {@see GuildMembershipChecker} for tests. Records every call so
 * specs can assert which guild was checked and with which token.
 */
class FakeGuildMembershipChecker implements GuildMembershipChecker
{
    /** @var list<string> */
    public array $checked = [];

    /** @var list<string> */
    public array $tokens = [];

    private bool $member = true;

    private ?string $failure = null;

    public function willBeMember(bool $member = true): self
    {
        $this->member = $member;
        $this->failure = null;

        return $this;
    }

    public function willFail(string $reason = 'simulated outage'): self
    {
        $this->failure = $reason;

        return $this;
    }

    public function isMember(string $accessToken, string $guildId): bool
    {
        $this->checked[] = $guildId;
        $this->tokens[] = $accessToken;

        if ($this->failure !== null) {
            throw GuildMembershipUnavailable::because($this->failure);
        }

        return $this->member;
    }
}
