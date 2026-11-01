<?php

namespace App\Modules\Preflight\Support;

use App\Modules\Preflight\Contracts\LatestRelease;
use Illuminate\Support\Facades\Http;
use Throwable;

/** GitHub releases API, unauthenticated (60 requests/hour is plenty for a handful of projects a day). */
class HttpLatestRelease implements LatestRelease
{
    public function latest(string $repo): ?string
    {
        try {
            $response = Http::timeout(3)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'LANoMAT-update-watch', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->get('https://api.github.com/repos/'.$repo.'/releases/latest');

            if (! $response->successful()) {
                return null;
            }

            $tag = $response->json('tag_name');

            return is_string($tag) && $tag !== '' ? $tag : null;
        } catch (Throwable) {
            return null;
        }
    }
}
