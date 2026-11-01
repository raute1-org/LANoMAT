<?php

namespace App\Modules\Preflight\Contracts;

/** Looks up the newest published release of an upstream project. */
interface LatestRelease
{
    /**
     * The tag of the latest release of the GitHub project `org/project`, or null if it could not be
     * looked up (network, rate limit, unexpected answer). Never throws.
     */
    public function latest(string $repo): ?string;
}
