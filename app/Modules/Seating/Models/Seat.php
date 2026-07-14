<?php

namespace App\Modules\Seating\Models;

use App\Modules\Events\Models\Event;
use Database\Factories\SeatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property array<string, mixed> $meta
 */
class Seat extends Model
{
    /** @use HasFactory<SeatFactory> */
    use HasFactory;

    protected $fillable = ['event_id', 'label', 'pos_x', 'pos_y', 'meta'];

    protected function casts(): array
    {
        return [
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasOne<SeatAssignment, $this> */
    public function assignment(): HasOne
    {
        return $this->hasOne(SeatAssignment::class);
    }

    protected static function newFactory(): SeatFactory
    {
        return SeatFactory::new();
    }
}
