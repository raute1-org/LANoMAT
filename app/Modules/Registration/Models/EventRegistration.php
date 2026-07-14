<?php

namespace App\Modules\Registration\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use Database\Factories\EventRegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property RegistrationStatus $status
 * @property Carbon|null $paid_at
 * @property Carbon|null $checked_in_at
 * @property string $qr_token
 */
class EventRegistration extends Model
{
    /** @use HasFactory<EventRegistrationFactory> */
    use HasFactory;

    // status/paid_at/checked_in_at/qr_token deliberately NOT fillable
    // (privilege/state fields — set only via actions or the creating hook).
    protected $fillable = [
        'event_id',
        'user_id',
        'ticket_type',
    ];

    // Defensive: qr_token must never leak through accidental array/JSON
    // serialization (e.g. a future API resource or Filament column that
    // forgets to select columns explicitly). The Register page renders it
    // only as a server-side SVG (see QrCode support class), never as raw
    // token data in props.
    protected $hidden = [
        'qr_token',
    ];

    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'paid_at' => 'datetime',
            'checked_in_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EventRegistration $registration): void {
            if (blank($registration->qr_token)) {
                $registration->qr_token = Str::random(40);
            }
        });
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): EventRegistrationFactory
    {
        return EventRegistrationFactory::new();
    }
}
