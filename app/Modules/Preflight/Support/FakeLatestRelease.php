<?php

namespace App\Modules\Preflight\Support;

use App\Modules\Preflight\Contracts\LatestRelease;

/** Test double: answers from a fixed `repo => tag` map; an absent repo or a null value is a failed lookup. */
class FakeLatestRelease implements LatestRelease
{
    /** @var list<string> */
    public array $lookups = [];

    /** @param array<string, string|null> $releases */
    public function __construct(private readonly array $releases = []) {}

    public function latest(string $repo): ?string
    {
        $this->lookups[] = $repo;

        return $this->releases[$repo] ?? null;
    }
}
