<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Exceptions\AvatarFetchFailed;
use App\Modules\Identity\Support\FetchedAvatar;

/**
 * Downloads a user's avatar from the identity provider's CDN. The real implementation is
 * locked to the CDN hosts; tests use the scriptable fake and never reach Discord.
 */
interface AvatarFetcher
{
    /**
     * @throws AvatarFetchFailed when the source is not allowed, unreachable, too large or not an image response
     */
    public function fetch(string $url): FetchedAvatar;
}
