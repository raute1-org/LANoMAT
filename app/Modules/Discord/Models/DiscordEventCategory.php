<?php

namespace App\Modules\Discord\Models;

use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Discord category that belongs to an event, see {@see EventCategories}.
 *
 * @property int $event_id
 * @property string $kind 'live' (running matches) or 'archive' (finished match text channels)
 * @property int $sequence 1 for the first category, 2.. when the previous one was full
 * @property string $category_id
 */
class DiscordEventCategory extends Model
{
    protected $fillable = ['event_id', 'kind', 'sequence', 'category_id'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
