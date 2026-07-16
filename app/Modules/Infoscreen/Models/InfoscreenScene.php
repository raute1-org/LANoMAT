<?php

namespace App\Modules\Infoscreen\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Casts\SceneConfigCast;
use App\Modules\Infoscreen\Domain\SceneConfig;
use App\Modules\Infoscreen\Enums\SceneType;
use Database\Factories\InfoscreenSceneFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property SceneType $type
 * @property SceneConfig $config
 * @property int $duration_sec
 * @property int $sort
 * @property bool $enabled
 */
class InfoscreenScene extends Model
{
    /** @use HasFactory<InfoscreenSceneFactory> */
    use HasFactory;

    // config/sort/enabled deliberately NOT fillable: config is structured
    // data that must go through the typed SceneConfigCast rather than a
    // mass-assigned raw array, and sort/enabled are orga-owned lifecycle
    // fields driven by reorder/toggle Actions (see roadmap insight #9 on
    // Filament's KeyValue mangling jsonb types, mirrored from Catering).
    protected $fillable = [
        'event_id',
        'type',
        'duration_sec',
    ];

    protected function casts(): array
    {
        return [
            'type' => SceneType::class,
            'config' => SceneConfigCast::class,
            'duration_sec' => 'integer',
            'sort' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @param  Builder<InfoscreenScene>  $query
     * @return Builder<InfoscreenScene>
     */
    public function scopeEnabledOrdered(Builder $query): Builder
    {
        return $query->where('enabled', true)->orderBy('sort')->orderBy('id');
    }

    protected static function newFactory(): InfoscreenSceneFactory
    {
        return InfoscreenSceneFactory::new();
    }
}
