<?php

namespace App\Modules\Infoscreen\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\DrawTombola;
use App\Modules\Registration\Models\EventRegistration;
use Database\Factories\TombolaDrawFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The record of a single tombola draw: which checked-in registration won
 * which prize, and when. `registration_id`/`drawn_at` are deliberately NOT
 * fillable — they are the drawn outcome, set only by
 * {@see DrawTombola}, never by client input
 * or mass assignment (mirrors EventRegistration's status/checked_in_at
 * privilege-field convention).
 *
 * @property int $registration_id
 * @property Carbon $drawn_at
 */
class TombolaDraw extends Model
{
    /** @use HasFactory<TombolaDrawFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'tombola_prize_id',
    ];

    protected function casts(): array
    {
        return [
            'drawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<TombolaPrize, $this> */
    public function prize(): BelongsTo
    {
        return $this->belongsTo(TombolaPrize::class, 'tombola_prize_id');
    }

    /** @return BelongsTo<EventRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class);
    }

    protected static function newFactory(): TombolaDrawFactory
    {
        return TombolaDrawFactory::new();
    }
}
