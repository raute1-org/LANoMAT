<?php

namespace App\Modules\Schedule\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Enums\ScheduleItemType;
use App\Modules\Schedule\Events\ScheduleItemTimeChanged;
use Database\Factories\ScheduleItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /** @return HasMany<ScheduleItemFavorite, $this> */
    public function favorites(): HasMany
    {
        return $this->hasMany(ScheduleItemFavorite::class);
    }

    protected static function newFactory(): ScheduleItemFactory
    {
        return ScheduleItemFactory::new();
    }

    /**
     * Dispatch {@see ScheduleItemTimeChanged} only when `starts_at` actually
     * changed — never for unrelated attribute churn (e.g. `title`),
     * mirroring the `Tournament::booted()` guard so favoriters are alarmed
     * exactly once per real reschedule.
     *
     * `wasChanged()` is already false on a fresh `create()` (there is no
     * "previous" value to differ from), so no separate
     * `wasRecentlyCreated` check is needed here — and deliberately not
     * added, since `wasRecentlyCreated` stays `true` for the lifetime of
     * the in-memory instance and would wrongly suppress a later `update()`
     * call on that same instance within the same request.
     */
    protected static function booted(): void
    {
        static::saved(function (ScheduleItem $item): void {
            if ($item->wasChanged('starts_at')) {
                ScheduleItemTimeChanged::dispatch($item);
            }
        });
    }
}
