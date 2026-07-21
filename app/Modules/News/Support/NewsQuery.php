<?php

declare(strict_types=1);

namespace App\Modules\News\Support;

use App\Modules\Gallery\Support\GalleryQuery;
use App\Modules\News\Models\NewsPost;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pure read-model over published news posts — the single place that defines
 * "published" (published_at set and not in the future) and the homepage
 * ordering (most recent first), mirroring {@see GalleryQuery}.
 */
class NewsQuery
{
    /**
     * @return Collection<int, NewsPost>
     */
    public function published(int $limit = 3): Collection
    {
        return NewsPost::query()
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }
}
