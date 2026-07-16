<?php

namespace App\Modules\Infoscreen\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\DrawTombola;
use Database\Factories\TombolaPrizeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single tombola prize, orga-maintained (Filament `TombolaPrizeResource`,
 * reorderable via `sort`), drawn against by {@see DrawTombola}.
 *
 * @property int $sort
 */
class TombolaPrize extends Model
{
    /** @use HasFactory<TombolaPrizeFactory> */
    use HasFactory;

    // sort deliberately NOT fillable: it is orga-owned drag-reorder state
    // driven by the Filament table's `reorderable()`, mirroring
    // InfoscreenScene/ScheduleItem's sort handling.
    protected $fillable = [
        'event_id',
        'title',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasOne<TombolaDraw, $this> */
    public function draw(): HasOne
    {
        return $this->hasOne(TombolaDraw::class);
    }

    protected static function newFactory(): TombolaPrizeFactory
    {
        return TombolaPrizeFactory::new();
    }
}
