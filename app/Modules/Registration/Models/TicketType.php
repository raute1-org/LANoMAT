<?php

namespace App\Modules\Registration\Models;

use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A purchasable ticket of one event, priced in integer cents (EUR).
 *
 * @property int $id
 * @property int $event_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $price_cents
 * @property int $sort
 * @property bool $active
 */
class TicketType extends Model
{
    protected $fillable = [
        'event_id',
        'key',
        'name',
        'description',
        'price_cents',
        'sort',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'sort' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return Collection<int, TicketType> */
    public static function activeFor(Event $event): Collection
    {
        return self::query()
            ->where('event_id', $event->id)
            ->where('active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    public static function findForEvent(Event $event, string $key): ?TicketType
    {
        return self::query()->where('event_id', $event->id)->where('key', $key)->first();
    }

    /** Slug of the name, suffixed `_2`, `_3`, … on collision within the event; max 64 chars. */
    public static function uniqueKeyFor(Event $event, string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 60, '') ?: 'ticket';
        $key = $base;
        $n = 2;

        while (self::query()->where('event_id', $event->id)->where('key', $key)->exists()) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }
}
