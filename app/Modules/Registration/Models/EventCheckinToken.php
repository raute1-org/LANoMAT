<?php

namespace App\Modules\Registration\Models;

use App\Modules\Events\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The secret behind the ONE on-site self check-in QR code of an event. Created
 * lazily on first use, replaced by RotateEventCheckinToken.
 *
 * @property int $event_id
 * @property string $token
 * @property CarbonInterface|null $rotated_at
 */
class EventCheckinToken extends Model
{
    // Nothing is mass-assignable: the token is only ever written by this class
    // and RotateEventCheckinToken.
    protected $guarded = ['*'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['rotated_at' => 'datetime'];
    }

    public static function generate(): string
    {
        return Str::random(40);
    }

    /** Get the event's token, creating it on first use. */
    public static function forEvent(Event $event): self
    {
        $existing = self::query()->where('event_id', $event->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $token = new self;
        $token->event_id = $event->id;
        $token->token = self::generate();
        $token->save();

        return $token;
    }

    /** Resolve a presented token to its event; null for anything unknown. */
    public static function resolveEvent(string $presented): ?Event
    {
        $row = self::query()->where('token', $presented)->first();

        if ($row === null || ! hash_equals($row->token, $presented)) {
            return null;
        }

        return $row->event()->first();
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
