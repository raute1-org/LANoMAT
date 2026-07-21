<?php

declare(strict_types=1);

namespace App\Modules\News\Models;

use App\Models\User;
use App\Modules\Gallery\Models\EventPhoto;
use Carbon\Carbon;
use Database\Factories\NewsPostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A global (cross-event) orga news post shown on the homepage once
 * published. `published_at` and `author_id` are deliberately NOT
 * mass-assignable: `published_at` is a state field flipped by the
 * publish/unpublish Filament action via `forceFill` (mirrors
 * {@see EventPhoto}'s `visibility`/`reviewed_*`
 * handling), and `author_id` is ownership set from the authenticated user in
 * CreateNewsPost, never a client-supplied form value.
 *
 * @property int|null $author_id
 * @property Carbon|null $published_at
 */
class NewsPost extends Model
{
    /** @use HasFactory<NewsPostFactory> */
    use HasFactory;

    protected $fillable = ['title', 'body'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    protected static function newFactory(): NewsPostFactory
    {
        return NewsPostFactory::new();
    }
}
