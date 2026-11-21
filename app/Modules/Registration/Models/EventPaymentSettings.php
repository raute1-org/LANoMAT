<?php

namespace App\Modules\Registration\Models;

use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-event payment settings (one row per event, created lazily on first save).
 *
 * @property int $id
 * @property int $event_id
 * @property string|null $payment_link
 * @property string|null $payment_note
 * @property int $reminder_after_days
 * @property int $reminder_before_event_days
 */
class EventPaymentSettings extends Model
{
    protected $table = 'event_payment_settings';

    protected $fillable = [
        'event_id',
        'payment_link',
        'payment_note',
        'reminder_after_days',
        'reminder_before_event_days',
    ];

    protected function casts(): array
    {
        return [
            'reminder_after_days' => 'integer',
            'reminder_before_event_days' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Existing row or an unsaved instance with defaults; reading never writes. */
    public static function forEvent(Event $event): self
    {
        return self::query()->firstOrNew(
            ['event_id' => $event->id],
            ['reminder_after_days' => 3, 'reminder_before_event_days' => 3],
        );
    }
}
