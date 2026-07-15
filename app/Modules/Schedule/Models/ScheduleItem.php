<?php

namespace App\Modules\Schedule\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Enums\ScheduleItemType;
use Database\Factories\ScheduleItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property ScheduleItemType $type
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $ref_type
 * @property int|null $ref_id
 */
class ScheduleItem extends Model
{
    /** @use HasFactory<ScheduleItemFactory> */
    use HasFactory;

    /**
     * `ref_type`/`ref_id` are deliberately excluded: they mark a row as
     * owned by another aggregate (e.g. a tournament) and subject to
     * automatic overwrite by that aggregate's sync action. They must only
     * ever be set via explicit property assignment (see
     * `SyncTournamentScheduleItem`), never through mass-assignment, so a
     * user-supplied `fill()` (e.g. via `UpsertScheduleItem`) can never claim
     * ownership of a schedule item.
     */
    protected $fillable = [
        'event_id',
        'type',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'location',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'type' => ScheduleItemType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    protected static function newFactory(): ScheduleItemFactory
    {
        return ScheduleItemFactory::new();
    }
}
