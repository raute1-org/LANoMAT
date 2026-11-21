<?php

namespace App\Modules\Registration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dedupe record: one row per (registration, reminder kind) that was sent (or deliberately skipped).
 *
 * @property int $id
 * @property int $event_registration_id
 * @property string $kind
 * @property Carbon $sent_at
 */
class PaymentReminderSent extends Model
{
    public const KIND_AFTER_REGISTRATION = 'after_registration';

    public const KIND_BEFORE_EVENT = 'before_event';

    protected $table = 'payment_reminders_sent';

    public $timestamps = false;

    protected $fillable = ['event_registration_id', 'kind', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** @return BelongsTo<EventRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }
}
