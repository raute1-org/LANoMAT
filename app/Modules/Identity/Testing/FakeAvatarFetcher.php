<?php

declare(strict_types=1);

namespace App\Modules\Identity\Testing;

use App\Modules\Identity\Contracts\AvatarFetcher;
use App\Modules\Identity\Exceptions\AvatarFetchFailed;
use App\Modules\Identity\Support\FetchedAvatar;

/**
 * Scriptable {@see AvatarFetcher}. Fails until told otherwise, so a test that forgets to
 * script it can never reach a real CDN. Records every requested URL.
 */
class FakeAvatarFetcher implements AvatarFetcher
{
    /** @var list<string> */
    public array $fetched = [];

    private ?FetchedAvatar $avatar = null;

    private string $failure = 'not scripted';

    private bool $failing = true;

    public function willReturn(string $binary, string $contentType = 'image/png'): self
    {
        $this->avatar = new FetchedAvatar($binary, $contentType);
        $this->failing = false;

        return $this;
    }

    public function willFail(string $reason = 'simulated outage'): self
    {
        $this->failure = $reason;
        $this->failing = true;

        return $this;
    }

    public function fetch(string $url): FetchedAvatar
    {
        $this->fetched[] = $url;

        if ($this->failing || $this->avatar === null) {
            throw AvatarFetchFailed::because($this->failure);
        }

        return $this->avatar;
    }
}
